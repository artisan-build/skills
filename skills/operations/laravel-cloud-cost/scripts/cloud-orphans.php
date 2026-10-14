#!/usr/bin/env php
<?php

/**
 * cloud-orphans.php
 *
 * Decides which Laravel Cloud resources nothing is using, by proving references
 * instead of guessing at names.
 *
 * The cost report (cloud-cost.php) matches a resource to an application by name,
 * because the organization-wide resource lists carry no application id. That is
 * fine for splitting a bill and useless for finding an orphan: a resource whose
 * name matches a live application looks owned whether or not anything points at
 * it, and a resource named after something else looks abandoned while it serves
 * production.
 *
 * So this script walks the only link the API actually populates: every
 * environment, one `environment:get` at a time, reading `databaseSchemaId`,
 * `cacheId`, and `websocketApplicationId`. A resource is in use when an
 * environment names it. Anything else is a candidate, and the verdict says how
 * strong the evidence is.
 *
 * Reads only. Never provisions, resizes, or deletes. Never requests a connection
 * string, a password, or an environment variable's value: environment variables
 * are fetched as KEY NAMES ONLY, through `--fields=environmentVariables.key`, so
 * no credential is ever in this process's memory to leak into its output.
 *
 * Exit codes: 0 success, 1 failure, 2 misuse.
 */

declare(strict_types=1);

const SCHEMA_VERSION = 1;

// How strong the evidence is that a resource is, or is not, in use.
const V_IN_USE       = 'in-use';                 // an environment names it
const V_SUPERSEDED   = 'possibly-superseded';    // named, but every environment naming it wires that kind by hand
const V_ACTIVE       = 'unreferenced-but-active'; // nothing names it, yet it is doing work
const V_ORPHAN       = 'probable-orphan';        // nothing names it and it shows no work
const V_UNPROVEN     = 'unproven';              // the API exposes no attachment for this kind at all

// Default hours of compute over the metrics window before "doing work" is a fair
// reading. A serverless database that woke up once bills a fraction of an hour.
const ACTIVE_HOURS = 1.0;

// Environment variables that override an attached resource, by the kind of
// resource they override. Laravel Cloud injects an attached resource's own
// configuration separately from the variables set on the environment, so a
// variable set here wins and the attachment stops being the thing the
// application talks to. Only KEY NAMES are ever read, and only these.
const WIRING_KEYS = [
    'database' => [
        'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME',
        'DB_PASSWORD', 'DB_URL', 'DB_SOCKET', 'DATABASE_URL',
    ],
    'cache' => [
        'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD', 'REDIS_USERNAME', 'REDIS_URL',
        'REDIS_SCHEME', 'REDIS_CLUSTER', 'REDIS_CACHE_DB', 'REDIS_DB',
        'MEMCACHED_HOST', 'MEMCACHED_PORT', 'CACHE_URL',
    ],
    'bucket' => [
        'AWS_BUCKET', 'AWS_ENDPOINT', 'AWS_URL', 'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY', 'AWS_DEFAULT_REGION', 'AWS_USE_PATH_STYLE_ENDPOINT',
    ],
    'websocket' => [
        'PUSHER_APP_ID', 'PUSHER_APP_KEY', 'PUSHER_APP_SECRET', 'PUSHER_HOST',
        'PUSHER_PORT', 'PUSHER_SCHEME', 'PUSHER_APP_CLUSTER',
        'REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET', 'REVERB_HOST',
        'REVERB_PORT', 'REVERB_SCHEME',
    ],
];

// ---------------------------------------------------------------- arguments

function usage(): string
{
    return <<<TXT
    Usage: php cloud-orphans.php [options]

      --period=WHICH     current | previous | 1 | 2 | 3     (default: previous)
                         which billing period the money in the report comes from.
      --dir=PATH         run the cloud CLI from PATH. Needed when more than one
                         organization token is configured: point it at a repo whose
                         .cloud/config.json selects the organization you want.
      --cloud=PATH       path to the cloud binary                (default: cloud)
      --map=FILE         attribution overrides for the cost join, same file the
                         cost report takes
      --active-hours=N   compute-hours over the metrics window above which an
                         unreferenced resource counts as active  (default: 1)
      --window=PERIOD    metrics window: 6h | 24h | 3d | 7d | 30d    (default: 30d)
      --no-metrics       skip the activity metrics (one call per cluster and cache).
                         Unreferenced resources then read 'probable-orphan' with
                         activity unknown.
      --no-cost          skip the cost join: verdicts without money attached
      --delay=MS         pause between CLI calls                 (default: 1500)
      --json             emit one JSON object on stdout and nothing else
      -h, --help         this text

    Read-only. Makes one call per environment, so it takes minutes rather than
    seconds; the call budget is printed before the first call.

    TXT;
}

$opts = [
    'period' => 'previous',
    'dir' => null,
    'cloud' => 'cloud',
    'map' => null,
    'active-hours' => ACTIVE_HOURS,
    'window' => '30d',
    'metrics' => true,
    'cost' => true,
    'delay' => 1500,
    'json' => false,
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '-h' || $arg === '--help') {
        fwrite(STDOUT, usage());
        exit(0);
    }
    if ($arg === '--json')        { $opts['json'] = true; continue; }
    if ($arg === '--no-metrics')  { $opts['metrics'] = false; continue; }
    if ($arg === '--no-cost')     { $opts['cost'] = false; continue; }
    if (preg_match('/^--(period|dir|cloud|map|window)=(.*)$/', $arg, $m)) {
        $opts[$m[1]] = $m[2];
        continue;
    }
    if (preg_match('/^--active-hours=([0-9]*\.?[0-9]+)$/', $arg, $m)) {
        $opts['active-hours'] = (float) $m[1];
        continue;
    }
    if (preg_match('/^--delay=([0-9]+)$/', $arg, $m)) {
        $opts['delay'] = (int) $m[1];
        continue;
    }
    fwrite(STDERR, "unknown option: {$arg}\n\n" . usage());
    exit(2);
}

if (! in_array((string) $opts['period'], ['current', 'previous', '0', '1', '2', '3'], true)) {
    fwrite(STDERR, "--period must be one of: current, previous, 1, 2, 3\n");
    exit(2);
}

if (! in_array((string) $opts['window'], ['6h', '24h', '3d', '7d', '30d'], true)) {
    fwrite(STDERR, "--window must be one of: 6h, 24h, 3d, 7d, 30d\n");
    exit(2);
}

// ---------------------------------------------------------------- the CLI

final class CloudCliError extends RuntimeException {}

/**
 * Every CLI call goes through here, one at a time.
 *
 * Four concurrent calls earn an HTTP 429 on roughly a fifth of them, which is
 * why this is serial with a pause and a growing backoff rather than a fan-out.
 * A probe of sixty calls is slow; a probe of sixty calls where twelve silently
 * failed is wrong.
 */
final class Cli
{
    public int $made = 0;
    public int $rateLimited = 0;
    public int $retries = 0;
    private float $lastCallAt = 0.0;

    public function __construct(private array $opts) {}

    public function json(array $args, array $fields = []): mixed
    {
        $raw = $this->run($args, $fields);

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new CloudCliError('the CLI did not return JSON: ' . substr($raw, 0, 300));
        }

        if (is_array($decoded) && ($decoded['error'] ?? false) === true) {
            throw new CloudCliError(explain((string) ($decoded['message'] ?? 'unknown error')));
        }

        return $decoded;
    }

    /**
     * The list commands return a bare array. Some payloads wrap it in `data`, so
     * accept both rather than depending on which one today's CLI emits.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(array $args, array $fields = []): array
    {
        $decoded = $this->json($args, $fields);

        if (is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])) {
            $decoded = $decoded['data'];
        }

        if (! is_array($decoded)) {
            throw new CloudCliError(implode(' ', $args) . ' did not return a list');
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    private function run(array $args, array $fields): string
    {
        $cmd = escapeshellcmd($this->opts['cloud']);
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg((string) $a);
        }
        $cmd .= ' --json --no-interaction';
        if ($fields !== []) {
            $cmd .= ' ' . escapeshellarg('--fields=' . implode(',', $fields));
        }
        $cmd .= ' 2>&1';

        if ($this->opts['dir'] !== null) {
            $cmd = 'cd ' . escapeshellarg($this->opts['dir']) . ' && ' . $cmd;
        }

        // 15s, 30s, 45s, 60s. The limit is per-minute, so waiting out the window
        // is the only thing that works; retrying immediately just spends another.
        for ($attempt = 0; $attempt <= 4; $attempt++) {
            $this->pace();
            $lines = []; // exec() appends, so a retry would carry the failed attempt with it
            exec($cmd, $lines, $rc);
            $this->made++;
            $out = strip_ansi(implode("\n", $lines));

            if ($rc === 0 || ! is_rate_limited($out)) {
                if ($rc !== 0) {
                    throw new CloudCliError(explain(trim($out)));
                }

                return $out;
            }

            $this->rateLimited++;
            if ($attempt === 4) {
                throw new CloudCliError(
                    "rate limited by the API after 5 attempts at: " . implode(' ', $args) . "\n"
                    . "Raise --delay and run it again."
                );
            }
            $this->retries++;
            sleep(15 * ($attempt + 1));
        }

        throw new CloudCliError('unreachable');
    }

    private function pace(): void
    {
        $wait = ($this->lastCallAt + $this->opts['delay'] / 1000) - microtime(true);
        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
        $this->lastCallAt = microtime(true);
    }
}

function is_rate_limited(string $out): bool
{
    return str_contains($out, 'Too Many Attempts')
        || str_contains($out, '429')
        || str_contains(strtolower($out), 'rate limit');
}

function strip_ansi(string $s): string
{
    return preg_replace('/\e\[[0-9;]*m/', '', $s) ?? $s;
}

function explain(string $message): string
{
    if (str_contains($message, 'Multiple API tokens')) {
        return "more than one organization is authenticated, so the CLI cannot tell which one you mean.\n"
            . "Pass --dir=PATH pointing at a repository whose .cloud/config.json selects the organization\n"
            . "you want to report on.\nOriginal message: {$message}";
    }

    if (str_contains($message, 'Unauthenticated') || str_contains($message, '401')) {
        return "not authenticated. Run: cloud auth\nOriginal message: {$message}";
    }

    return $message;
}

function preflight(array $opts): void
{
    $bin = $opts['cloud'];
    exec(escapeshellcmd($bin) . ' --version 2>&1', $out, $rc);
    if ($rc !== 0) {
        throw new CloudCliError(
            "the `{$bin}` CLI is not available.\n" .
            "Install it with: composer global require laravel/cloud-cli\n" .
            "Then authenticate with: cloud auth"
        );
    }
}

// ---------------------------------------------------------------- helpers

function dollars(?int $cents): string
{
    return '$' . number_format(($cents ?? 0) / 100, 2);
}

function norm(string $s): string
{
    return preg_replace('/[^a-z0-9]/', '', strtolower($s)) ?? '';
}

const KNOWN_TLDS = ['com', 'app', 'io', 'dev', 'fm', 'us', 'net', 'org', 'co', 'ai', 'sh', 'tv', 'build', 'cloud'];

function strip_tld(string $s): string
{
    foreach (KNOWN_TLDS as $tld) {
        if (str_ends_with(strtolower($s), '.' . $tld)) {
            return substr($s, 0, -1 - strlen($tld));
        }
    }

    return $s;
}

/**
 * The application whose name a resource name looks like. A hint only: it is what
 * the cost report would guess, printed next to what the references prove, so the
 * reader can see the two disagree. Never a verdict.
 */
function name_owner(string $resourceName, array $apps): ?string
{
    $n = norm($resourceName);
    if ($n === '') {
        return null;
    }

    $best = null;
    foreach ($apps as $app) {
        foreach ([$app['name'] ?? null, $app['slug'] ?? null] as $label) {
            if ($label === null) {
                continue;
            }
            foreach ([$label, strip_tld((string) $label)] as $candidate) {
                $key = norm((string) $candidate);
                if (strlen($key) < 4) {
                    continue;
                }
                if (! str_starts_with($n, $key)) {
                    continue;
                }
                if ($best === null || strlen($key) > strlen($best['key'])) {
                    $best = ['key' => $key, 'name' => (string) ($app['name'] ?? $app['id'])];
                }
            }
        }
    }

    return $best === null ? null : $best['name'];
}

// ---------------------------------------------------------------- the probe

/**
 * One environment's reference set, plus the wiring it overrides by hand.
 *
 * `environment:get` is the only route that returns a populated
 * `databaseSchemaId`, `cacheId`, or `websocketApplicationId`. The list-level
 * relation fields (`cache:list.environmentIds`,
 * `application:list environments[].databaseSchemaId`,
 * `websocket-cluster:list.applicationIds`, `bucket:list.keyIds`) come back empty
 * or null for resources provably in use, so none of them can be trusted to say a
 * resource is unused.
 */
function probe_environments(Cli $cli, array $apps): array
{
    $rows = [];

    foreach ($apps as $app) {
        foreach ($app['environments'] ?? [] as $env) {
            $detail = $cli->json(['environment:get', (string) $env['id']], [
                'id', 'name', 'status', 'usesHibernation', 'databaseSchemaId', 'cacheId',
                'websocketApplicationId', 'environmentVariables.key',
            ]);

            if (is_array($detail) && isset($detail['data']) && is_array($detail['data'])) {
                $detail = $detail['data'];
            }
            if (! is_array($detail)) {
                continue;
            }

            // Key names only, and only the ones that override an attachment.
            // Nothing else about an environment's variables is read, kept, or
            // printed: this is the one place a credential could reach the output,
            // and the request never asked for a value in the first place.
            $wiring = [];
            foreach ($detail['environmentVariables'] ?? [] as $var) {
                $key = is_array($var) ? ($var['key'] ?? null) : null;
                if (! is_string($key)) {
                    continue;
                }
                foreach (WIRING_KEYS as $kind => $keys) {
                    if (in_array($key, $keys, true)) {
                        $wiring[$kind][] = $key;
                    }
                }
            }
            foreach ($wiring as $kind => $keys) {
                $wiring[$kind] = array_values(array_unique($keys));
                sort($wiring[$kind]);
            }

            $rows[] = [
                'applicationId' => (string) $app['id'],
                'application' => (string) ($app['name'] ?? $app['id']),
                'environmentId' => (string) ($detail['id'] ?? $env['id']),
                'environment' => (string) ($detail['name'] ?? ($env['name'] ?? '')),
                'status' => $detail['status'] ?? null,
                'usesHibernation' => $detail['usesHibernation'] ?? null,
                'databaseSchemaId' => isset($detail['databaseSchemaId']) ? (string) $detail['databaseSchemaId'] : null,
                'cacheId' => isset($detail['cacheId']) ? (string) $detail['cacheId'] : null,
                'websocketApplicationId' => isset($detail['websocketApplicationId']) ? (string) $detail['websocketApplicationId'] : null,
                'wiringEnvKeys' => $wiring,
            ];
        }
    }

    return $rows;
}

function who(array $env): string
{
    return $env['application'] . ' / ' . $env['environment'];
}

/**
 * Whether the signal this kind of resource reports can tell activity from
 * silence, worked out from the resources of the same type that are *proven* in
 * use.
 *
 * It has to be derived rather than assumed. `computeHours.total` comes back 0
 * for every `laravel_mysql` cluster, including ones several environments point
 * at, so a plain "zero means idle" test would condemn every MySQL cluster in the
 * organization. When the type's in-use resources all report nothing, the signal
 * is dead for that type and the honest reading is "unknown".
 *
 * Three outcomes, because "no sample to calibrate against" is not the same claim
 * as "the sample says the signal is dead":
 *
 *   trusted      an in-use resource of this type reports a figure, so a figure
 *                here means something
 *   dead         every in-use resource of this type reports nothing, so nothing
 *                here means nothing
 *   uncalibrated no resource of this type is proven in use, so there is no
 *                sample; fall back to the threshold and say the reading is
 *                uncorroborated
 *
 * @return array<string, string> type => trusted | dead | uncalibrated
 */
function calibrate(array $resources): array
{
    $status = [];
    foreach ($resources as $r) {
        $type = (string) ($r['type'] ?? '');
        $status[$type] = $status[$type] ?? 'uncalibrated';
        if ($r['referencedBy'] === []) {
            continue;
        }
        if (($r['activityValue'] ?? 0) > 0) {
            $status[$type] = 'trusted';
        } elseif ($status[$type] !== 'trusted') {
            $status[$type] = 'dead';
        }
    }

    return $status;
}

/**
 * @return array{signal: string, value: float|null, unit: string, window: string, state: string, note: ?string}
 */
function activity(
    string $signal,
    string $unit,
    ?float $value,
    string $window,
    array $calibration,
    string $type,
    float $threshold,
    bool $referenced,
): array {
    $state = 'unknown';
    $note = null;
    $sample = $calibration[$type] ?? 'uncalibrated';

    if ($value === null) {
        $note = 'no metrics were fetched for this resource.';
    } elseif ($sample === 'dead') {
        // Nothing of this type that is in use reports a figure either, so a zero
        // here says nothing about whether the resource is working.
        $state = 'unknown';
        $note = "every {$type} resource in this organization reports no {$signal}, including ones "
            . 'an environment proves are in use, so this signal cannot tell activity from silence '
            . 'for this type. The verdict rests on the references alone.';
    } else {
        $state = $value >= $threshold ? 'active' : 'idle';
        if ($sample === 'uncalibrated' && ! $referenced) {
            $note = "no {$type} resource in this organization is proven in use, so there is nothing "
                . "to check this {$signal} figure against. Read it as uncorroborated.";
        }
    }

    return [
        'signal' => $signal,
        'value' => $value,
        'unit' => $unit,
        'window' => $window,
        'state' => $state,
        'note' => $note,
    ];
}

/**
 * The verdict, from the references and the activity.
 *
 * Referenced resources can still be the wrong answer: an environment that sets
 * `DB_HOST` by hand talks to whatever that says, not to the database Cloud
 * attached, so an attachment every referencing environment overrides is a
 * leftover rather than a dependency. Unreferenced resources can still be in use
 * through exactly the same mechanism, which is why activity decides between the
 * two unreferenced verdicts instead of cost or age.
 */
function verdict(string $kind, array $refs, array $overriddenBy, array $activity): string
{
    if ($refs !== []) {
        return count($overriddenBy) === count($refs) && $overriddenBy !== []
            ? V_SUPERSEDED
            : V_IN_USE;
    }

    return ($activity['state'] ?? 'unknown') === 'active' ? V_ACTIVE : V_ORPHAN;
}

/**
 * Which of the environments that reference this resource override its wiring by
 * hand, carrying the key names that do the overriding.
 */
function overriding_envs(string $kind, array $refs): array
{
    $out = [];
    foreach ($refs as $ref) {
        $keys = $ref['wiringEnvKeys'][$kind] ?? [];
        if ($keys !== []) {
            $out[] = ['environment' => who($ref), 'keys' => $keys];
        }
    }

    return $out;
}

// ---------------------------------------------------------------- cost join

/**
 * The money, from the cost report rather than from a second reading of `usage`.
 *
 * The cost report owns the name matcher, the billing-period selection, and the
 * `--map` overrides. Re-deriving any of that here would mean two matchers
 * drifting apart, and the misattribution finding below is precisely a comparison
 * against what the cost report decided, so it has to be the cost report's own
 * answer being compared.
 *
 * A failure here is not fatal: verdicts without money are still verdicts.
 */
function cost_join(array $opts): array
{
    $script = __DIR__ . '/cloud-cost.php';
    if (! is_file($script)) {
        return ['ok' => false, 'error' => "cost report not found next to this script at {$script}", 'byId' => [], 'doc' => null];
    }

    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
        . ' --json --no-findings --no-trend'
        . ' ' . escapeshellarg('--period=' . $opts['period'])
        . ' ' . escapeshellarg('--cloud=' . $opts['cloud']);
    if ($opts['dir'] !== null) {
        $cmd .= ' ' . escapeshellarg('--dir=' . $opts['dir']);
    }
    if ($opts['map'] !== null) {
        $cmd .= ' ' . escapeshellarg('--map=' . $opts['map']);
    }

    exec($cmd . ' 2>/dev/null', $lines, $rc);
    $doc = json_decode(implode("\n", $lines), true);

    if ($rc !== 0 || ! is_array($doc)) {
        return [
            'ok' => false,
            'error' => 'the cost report failed, so no money is attached to these verdicts. '
                . 'Run it on its own to see why: php cloud-cost.php --period=' . $opts['period'],
            'byId' => [],
            'doc' => null,
        ];
    }

    // Resource identifiers as the cost report sees them: a database row's
    // identifier is the cluster id, a cache's is the cache id, a bucket's is the
    // bucket id.
    $byId = [];
    foreach ($doc['apps'] ?? [] as $app) {
        foreach ($app['resources'] ?? [] as $r) {
            if (($r['identifier'] ?? null) === null) {
                continue;
            }
            $byId[(string) $r['identifier']] = [
                'totalCents' => (int) ($r['totalCents'] ?? 0),
                'attributedTo' => $app['name'] ?? $app['id'] ?? null,
                'confidence' => $r['confidence'] ?? null,
            ];
        }
    }
    foreach ($doc['unattributed'] ?? [] as $r) {
        if (($r['identifier'] ?? null) === null) {
            continue;
        }
        $byId[(string) $r['identifier']] = [
            'totalCents' => (int) ($r['totalCents'] ?? 0),
            'attributedTo' => null,
            'confidence' => $r['confidence'] ?? 'unattributed',
        ];
    }

    return ['ok' => true, 'error' => null, 'byId' => $byId, 'doc' => $doc];
}

// ---------------------------------------------------------------- rendering

function render_text(array $doc): string
{
    $o = '';
    $o .= "Laravel Cloud orphan probe\n";
    if ($doc['period'] !== null) {
        $o .= "Money from {$doc['period']['from']} to {$doc['period']['to']} (period {$doc['period']['index']}), "
            . "figures in {$doc['currency']} as of " . ($doc['lastUpdatedAt'] ?? 'unknown') . "\n";
    } else {
        $o .= "No cost joined: verdicts only.\n";
    }
    $o .= 'Activity window ' . $doc['metricsWindow'] . ", references proven through environment:get\n";
    $o .= str_repeat('=', 72) . "\n\n";

    $i = $doc['inventory'];
    $o .= "INVENTORY\n";
    $o .= "  {$i['applications']} applications, {$i['environments']} environments\n";
    $o .= "  {$i['databaseClusters']} database clusters holding {$i['databaseSchemas']} schemas, "
        . "{$i['caches']} caches, {$i['buckets']} buckets, {$i['websocketClusters']} websocket clusters\n\n";

    $o .= "VERDICTS\n";
    foreach ($doc['verdictCounts'] as $v => $n) {
        $o .= sprintf("  %-26s %d\n", $v, $n);
    }
    $o .= "\n";

    $candidates = array_values(array_filter(
        $doc['resources'],
        static fn ($r) => ! in_array($r['verdict'], [V_IN_USE, V_UNPROVEN], true),
    ));
    $unproven = array_values(array_filter(
        $doc['resources'],
        static fn ($r) => $r['verdict'] === V_UNPROVEN,
    ));

    if ($candidates !== []) {
        $o .= "CANDIDATES\n";
        $o .= "  Everything the references do not prove is in use. Read the evidence before\n";
        $o .= "  acting on any of it: this probe sees Laravel Cloud, and something outside\n";
        $o .= "  Cloud can still hold a connection.\n\n";

        foreach ($candidates as $r) {
            $money = $r['cost'] === null ? 'cost unknown' : dollars($r['cost']['totalCents']) . ' per period';
            $o .= '  [' . strtoupper($r['verdict']) . "] {$r['kind']} '{$r['name']}'  ({$money})\n";
            $o .= '      type:     ' . ($r['type'] ?? 'unknown') . "\n";

            if ($r['referencedBy'] !== []) {
                $o .= '      used by:  ' . implode(', ', array_map(
                    static fn ($x) => $x['application'] . ' / ' . $x['environment'],
                    $r['referencedBy'],
                )) . "\n";
            } else {
                $o .= "      used by:  no environment references it\n";
            }

            if ($r['activity'] !== null) {
                $a = $r['activity'];
                $value = $a['value'] === null ? 'not measured' : rtrim(rtrim(number_format($a['value'], 2, '.', ''), '0'), '.') . ' ' . $a['unit'];
                $o .= "      activity: {$a['state']} ({$value} over {$a['window']})\n";
                if ($a['note'] !== null) {
                    $o .= '                ' . wrap($a['note']) . "\n";
                }
            }

            foreach ($r['manualWiringEnvs'] as $w) {
                $o .= "      wiring:   {$w['environment']} sets " . implode(', ', $w['keys']) . " by hand\n";
            }

            if ($r['cost'] !== null && $r['cost']['attributedTo'] !== null) {
                $o .= "      cost report attributes it to: {$r['cost']['attributedTo']} ({$r['cost']['confidence']})\n";
            }
            if ($r['nameOwner'] !== null && $r['referencedBy'] === []) {
                $o .= "      name looks like: {$r['nameOwner']}  (a naming guess, not evidence)\n";
            }

            foreach ($r['notes'] as $note) {
                $o .= '      note:     ' . wrap($note) . "\n";
            }
            $o .= "\n";
        }
    } else {
        $o .= "CANDIDATES\n\n  Every resource whose attachment the API exposes is referenced by an environment.\n\n";
    }

    if ($unproven !== []) {
        $o .= 'UNPROVEN  (' . count($unproven) . ")\n";
        $o .= "  No field in the CLI attaches these to an environment, in either direction, so\n";
        $o .= "  this probe can neither confirm nor rule out that they are in use. The name is\n";
        $o .= "  the only hint there is.\n\n";
        foreach ($unproven as $r) {
            $money = $r['cost'] === null ? '' : str_pad(dollars($r['cost']['totalCents']), 10, ' ', STR_PAD_LEFT);
            $hint = $r['nameOwner'] === null ? 'name matches no application' : 'name looks like ' . $r['nameOwner'];
            $o .= sprintf("      %-40s %10s   %s\n", substr($r['kind'] . " '" . $r['name'] . "'", 0, 40), $money, $hint);
        }
        $o .= "\n";
    }

    if ($doc['misattributions'] !== []) {
        $o .= "MISATTRIBUTED IN THE COST REPORT\n";
        $o .= "  In use, so not orphans, but the cost report put the money on the wrong\n";
        $o .= "  application because it had only the name to go on.\n\n";
        foreach ($doc['misattributions'] as $m) {
            $o .= sprintf("      %-40s %9s\n", $m['kind'] . " '" . $m['name'] . "'", dollars($m['totalCents']));
            $o .= '          cost report says: ' . ($m['costReportSays'] ?? 'nobody') . "\n";
            $o .= '          references prove: ' . implode(', ', $m['referenceProves']) . "\n";
        }
        if ($doc['mapSuggestion'] !== []) {
            $o .= "\n  Pin them with --map so the next cost run is right:\n\n";
            $o .= '      ' . str_replace("\n", "\n      ", (string) json_encode($doc['mapSuggestion'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
        }
        $o .= "\n";
    }

    if ($doc['danglingReferences'] !== []) {
        $o .= "DANGLING REFERENCES\n";
        $o .= "  An environment points at a resource no list reports. Either the resource\n";
        $o .= "  was removed under a running environment, or the lists are incomplete.\n\n";
        foreach ($doc['danglingReferences'] as $d) {
            $o .= "      {$d['environment']} -> {$d['kind']} {$d['id']}\n";
        }
        $o .= "\n";
    }

    $c = $doc['callBudget'];
    $o .= "CALLS\n";
    $o .= "  {$c['made']} made, {$c['planned']} planned, {$c['rateLimited']} rate limited, {$c['retried']} retried";
    $o .= $c['costReportCalls'] > 0 ? ", plus {$c['costReportCalls']} by the cost report\n" : "\n";

    if ($doc['costCoverage'] !== null && $doc['costCoverage']['usageRowsWithoutListedResource'] > 0) {
        $o .= "\n  ! {$doc['costCoverage']['usageRowsWithoutListedResource']} billed resource(s) in the cost report are not in any\n";
        $o .= "    resource list, so this probe has no verdict for them. Usually something\n";
        $o .= "    deleted partway through the period, billed for the part it ran.\n";
    }

    if ($doc['errors'] !== []) {
        $o .= "\nERRORS\n";
        foreach ($doc['errors'] as $e) {
            $o .= '  ' . wrap($e) . "\n";
        }
    }

    return $o;
}

function wrap(string $s): string
{
    return implode("\n                ", explode("\n", wordwrap($s, 72, "\n", false)));
}

// ---------------------------------------------------------------- main

try {
    preflight($opts);

    $cli = new Cli($opts);
    $errors = [];

    $apps = $cli->list(['application:list'], [
        'id', 'name', 'slug', 'environments.id', 'environments.name',
    ]);

    $envCount = 0;
    foreach ($apps as $app) {
        $envCount += count($app['environments'] ?? []);
    }

    // Everything after this point is a known number of calls, so say what it will
    // cost before spending it.
    $planned = 1                                   // application:list
        + $envCount                                // environment:get, the only proof of a reference
        + 4;                                       // database-cluster, cache, bucket, websocket-cluster lists
    // The cost report makes its own two calls in its own process, so they are
    // counted apart from this script's: `made` could never include them.
    $costCalls = $opts['cost'] ? 2 : 0;
    $budgetNote = "Probing {$envCount} environments across " . count($apps) . " applications.\n"
        . "Planned calls: {$planned} so far, plus one per database cluster and cache for activity"
        . ($opts['metrics'] ? '' : ' (skipped)') . ", and one per websocket cluster"
        . ($costCalls > 0 ? ", and {$costCalls} more by the cost report" : '') . ".\n"
        . 'Serial, ' . $opts['delay'] . "ms apart, so expect minutes rather than seconds.\n";
    fwrite($opts['json'] ? STDERR : STDOUT, $budgetNote . "\n");

    $envs = probe_environments($cli, $apps);

    $clusters = $cli->list(['database-cluster:list'], [
        'id', 'name', 'type', 'status', 'createdAt', 'schemas.id', 'schemas.name',
    ]);
    $caches = $cli->list(['cache:list'], ['id', 'name', 'type', 'status', 'size', 'createdAt']);
    $buckets = $cli->list(['bucket:list'], ['id', 'name', 'type', 'status', 'visibility', 'createdAt']);
    $wsClusters = $cli->list(['websocket-cluster:list'], ['id', 'name', 'type', 'status', 'createdAt']);

    $planned += count($clusters) + count($caches) + count($wsClusters);

    // The reference sets. One environment can hold one schema, one cache, and one
    // websocket application, so these are keyed by the resource id.
    $refSchema = [];
    $refCache = [];
    $refWs = [];
    foreach ($envs as $env) {
        if ($env['databaseSchemaId'] !== null) {
            $refSchema[$env['databaseSchemaId']][] = $env;
        }
        if ($env['cacheId'] !== null) {
            $refCache[$env['cacheId']][] = $env;
        }
        if ($env['websocketApplicationId'] !== null) {
            $refWs[$env['websocketApplicationId']][] = $env;
        }
    }

    $cost = $opts['cost'] ? cost_join($opts) : ['ok' => true, 'error' => null, 'byId' => [], 'doc' => null];
    if ($cost['error'] !== null) {
        $errors[] = $cost['error'];
    }

    // ---------------------------------------------------------- classification

    $resources = [];

    foreach ($clusters as $c) {
        $metrics = null;
        if ($opts['metrics']) {
            try {
                $metrics = $cli->json(['database-cluster:metrics', (string) $c['id'], '--period=' . $opts['window']], [
                    'period', 'cpuUsage.average', 'computeHours.total', 'storageUsage.current',
                ]);
            } catch (CloudCliError $e) {
                $errors[] = "metrics for database cluster '{$c['name']}': " . $e->getMessage();
            }
        }

        $schemas = [];
        $refs = [];
        foreach ($c['schemas'] ?? [] as $s) {
            $hits = $refSchema[(string) $s['id']] ?? [];
            $schemas[] = [
                'id' => (string) $s['id'],
                'name' => $s['name'] ?? null,
                'referencedBy' => array_map(static fn ($e) => who($e), $hits),
            ];
            foreach ($hits as $hit) {
                $refs[$hit['environmentId']] = $hit;
            }
        }

        $resources[] = [
            'kind' => 'database-cluster',
            'id' => (string) $c['id'],
            'name' => (string) ($c['name'] ?? ''),
            'type' => $c['type'] ?? null,
            'status' => $c['status'] ?? null,
            'createdAt' => $c['createdAt'] ?? null,
            'schemas' => $schemas,
            'referencedBy' => array_values($refs),
            'activityValue' => isset($metrics['computeHours']['total']) ? (float) $metrics['computeHours']['total'] : null,
            'activitySignal' => 'compute-hours',
            'activityUnit' => 'hours',
            'metrics' => $metrics === null ? null : [
                'computeHoursTotal' => $metrics['computeHours']['total'] ?? null,
                'cpuUsageAverage' => $metrics['cpuUsage']['average'] ?? null,
                'storageUsageCurrentBytes' => $metrics['storageUsage']['current'] ?? null,
            ],
            'wiringKind' => 'database',
        ];
    }

    foreach ($caches as $c) {
        $metrics = null;
        if ($opts['metrics']) {
            try {
                $metrics = $cli->json(['cache:metrics', (string) $c['id'], '--period=' . $opts['window']], [
                    'period', 'bandwidthUsage.total',
                ]);
            } catch (CloudCliError $e) {
                $errors[] = "metrics for cache '{$c['name']}': " . $e->getMessage();
            }
        }

        $hits = $refCache[(string) $c['id']] ?? [];

        $resources[] = [
            'kind' => 'cache',
            'id' => (string) $c['id'],
            'name' => (string) ($c['name'] ?? ''),
            'type' => $c['type'] ?? null,
            'status' => $c['status'] ?? null,
            'createdAt' => $c['createdAt'] ?? null,
            'schemas' => null,
            'referencedBy' => $hits,
            'activityValue' => isset($metrics['bandwidthUsage']['total'])
                ? (float) $metrics['bandwidthUsage']['total'] / 1_073_741_824
                : null,
            'activitySignal' => 'bandwidth',
            'activityUnit' => 'GB',
            'metrics' => $metrics === null ? null : [
                'bandwidthUsageTotalBytes' => $metrics['bandwidthUsage']['total'] ?? null,
            ],
            'wiringKind' => 'cache',
        ];
    }

    foreach ($wsClusters as $w) {
        $refs = [];
        $wsApps = [];
        try {
            $wsApps = $cli->list(['websocket-application:list', (string) $w['id']], ['id', 'name']);
        } catch (CloudCliError $e) {
            $errors[] = "websocket applications for cluster '{$w['name']}': " . $e->getMessage();
        }
        foreach ($wsApps as $a) {
            foreach ($refWs[(string) $a['id']] ?? [] as $hit) {
                $refs[$hit['environmentId']] = $hit;
            }
        }

        $resources[] = [
            'kind' => 'websocket-cluster',
            'id' => (string) $w['id'],
            'name' => (string) ($w['name'] ?? ''),
            'type' => $w['type'] ?? null,
            'status' => $w['status'] ?? null,
            'createdAt' => $w['createdAt'] ?? null,
            'schemas' => null,
            'applications' => array_map(static fn ($a) => ['id' => $a['id'] ?? null, 'name' => $a['name'] ?? null], $wsApps),
            'referencedBy' => array_values($refs),
            // There is no websocket metrics command, so activity is never known.
            'activityValue' => null,
            'activitySignal' => 'none',
            'activityUnit' => '',
            'metrics' => null,
            'wiringKind' => 'websocket',
        ];
    }

    foreach ($buckets as $b) {
        $resources[] = [
            'kind' => 'bucket',
            'id' => (string) $b['id'],
            'name' => (string) ($b['name'] ?? ''),
            'type' => $b['type'] ?? null,
            'status' => $b['status'] ?? null,
            'createdAt' => $b['createdAt'] ?? null,
            'schemas' => null,
            'referencedBy' => [],
            'activityValue' => null,
            'activitySignal' => 'none',
            'activityUnit' => '',
            'metrics' => null,
            'wiringKind' => 'bucket',
        ];
    }

    $calibration = calibrate($resources);

    // Metrics that match another resource's exactly are usually one resource
    // reported twice: a branch billed inside its parent, say. Worth saying,
    // because it is the difference between "this is busy" and "something else is
    // busy and this is watching".
    $byMetricFingerprint = [];
    foreach ($resources as $r) {
        if ($r['metrics'] === null || ($r['activityValue'] ?? 0) <= 0) {
            continue;
        }
        $fp = $r['kind'] . '|' . json_encode($r['metrics']);
        $byMetricFingerprint[$fp][] = $r['name'];
    }

    $out = [];
    $verdictCounts = [V_IN_USE => 0, V_SUPERSEDED => 0, V_ACTIVE => 0, V_ORPHAN => 0, V_UNPROVEN => 0];
    $misattributions = [];
    $mapSuggestion = [];

    foreach ($resources as $r) {
        $notes = [];
        $overridden = overriding_envs($r['wiringKind'], $r['referencedBy']);

        if ($r['kind'] === 'bucket') {
            // No field anywhere in the CLI attaches a bucket to an environment:
            // not `bucket:list`, not `environment:get`. An unreferenced bucket and
            // a busy one look identical, so neither verdict is available.
            $act = null;
            $v = V_UNPROVEN;
            $notes[] = 'no field in the CLI attaches a bucket to an environment, so this is neither '
                . 'proven in use nor proven unused. The name is the only hint, and the cost report '
                . 'already matches on that.';
        } else {
            $act = activity(
                $r['activitySignal'],
                $r['activityUnit'],
                $r['activityValue'],
                $opts['window'],
                $calibration,
                (string) ($r['type'] ?? ''),
                (float) $opts['active-hours'],
                $r['referencedBy'] !== [],
            );

            if ($r['kind'] === 'websocket-cluster') {
                $act['note'] = 'the CLI has no websocket metrics command, so activity cannot be measured.';
            }

            $v = verdict($r['kind'], $r['referencedBy'], $overridden, $act);
        }

        if ($v === V_SUPERSEDED) {
            $notes[] = 'every environment attached to this sets its ' . $r['wiringKind']
                . ' connection by hand, which overrides the attachment. The application is probably '
                . 'talking to something else, and this is the leftover attachment.';
        }
        if ($v === V_ACTIVE) {
            $notes[] = 'nothing in Cloud references it, yet it is doing work. The usual cause is an '
                . 'environment wiring it by hand through environment variables, which this probe '
                . 'cannot follow because it never reads a variable\'s value. Check the environments '
                . 'listed as setting wiring keys by hand.';
        }

        if ($r['metrics'] !== null && ($r['activityValue'] ?? 0) > 0) {
            $fp = $r['kind'] . '|' . json_encode($r['metrics']);
            $twins = array_values(array_diff($byMetricFingerprint[$fp] ?? [], [$r['name']]));
            if ($twins !== []) {
                $notes[] = 'its metrics are identical to ' . implode(', ', array_map(static fn ($n) => "'" . $n . "'", $twins))
                    . ', which usually means one underlying resource reported twice rather than two '
                    . 'resources doing the same work. Treat the activity figure as the other one\'s.';
            }
        }

        // Schemas belonging to more than one application: a shared cluster. One
        // map entry cannot split it, so say so instead of suggesting one.
        $apps_referencing = array_values(array_unique(array_map(
            static fn ($e) => $e['application'],
            $r['referencedBy'],
        )));
        if (count($apps_referencing) > 1) {
            $notes[] = 'shared: environments of ' . implode(' and ', $apps_referencing)
                . ' all reference it, so its cost cannot be assigned to one application.';
        }

        $costRow = $cost['byId'][$r['id']] ?? null;

        // The cost report had only the name. Where a reference proves a different
        // owner, that is money on the wrong application: not an orphan, but wrong.
        $mis = null;
        if ($costRow !== null && $apps_referencing !== [] && in_array($v, [V_IN_USE, V_SUPERSEDED], true)) {
            $claimed = $costRow['attributedTo'];
            if (($costRow['confidence'] ?? null) !== 'override'
                && (
                    $claimed === null
                    || ! in_array($claimed, $apps_referencing, true)
                )
            ) {
                $mis = ['costReportSays' => $claimed, 'referenceProves' => $apps_referencing];
                $misattributions[] = [
                    'kind' => $r['kind'],
                    'name' => $r['name'],
                    'identifier' => $r['id'],
                    'totalCents' => $costRow['totalCents'],
                    'costReportSays' => $claimed,
                    'referenceProves' => $apps_referencing,
                    'mappable' => count($apps_referencing) === 1,
                ];
                if (count($apps_referencing) === 1) {
                    $mapSuggestion[$r['name']] = $apps_referencing[0];
                }
            }
        }

        $verdictCounts[$v]++;

        $row = [
            'kind' => $r['kind'],
            'id' => $r['id'],
            'name' => $r['name'],
            'type' => $r['type'],
            'status' => $r['status'],
            'createdAt' => $r['createdAt'],
            'verdict' => $v,
            'referencedBy' => array_map(static fn ($e) => [
                'applicationId' => $e['applicationId'],
                'application' => $e['application'],
                'environmentId' => $e['environmentId'],
                'environment' => $e['environment'],
            ], $r['referencedBy']),
            'manualWiringEnvs' => $overridden,
            'activity' => $act,
            'metrics' => $r['metrics'],
            'cost' => $costRow,
            'nameOwner' => name_owner($r['name'], $apps),
            'misattributed' => $mis,
            'notes' => $notes,
        ];
        if ($r['kind'] === 'database-cluster') {
            $row['schemas'] = $r['schemas'];
        }
        if ($r['kind'] === 'websocket-cluster') {
            $row['applications'] = $r['applications'];
        }

        $out[] = $row;
    }

    // Environments pointing at something no list reports.
    $knownSchemas = [];
    foreach ($clusters as $c) {
        foreach ($c['schemas'] ?? [] as $s) {
            $knownSchemas[(string) $s['id']] = true;
        }
    }
    $knownCaches = [];
    foreach ($caches as $c) {
        $knownCaches[(string) $c['id']] = true;
    }

    $dangling = [];
    foreach ($envs as $env) {
        if ($env['databaseSchemaId'] !== null && ! isset($knownSchemas[$env['databaseSchemaId']])) {
            $dangling[] = ['environment' => who($env), 'kind' => 'database schema', 'id' => $env['databaseSchemaId']];
        }
        if ($env['cacheId'] !== null && ! isset($knownCaches[$env['cacheId']])) {
            $dangling[] = ['environment' => who($env), 'kind' => 'cache', 'id' => $env['cacheId']];
        }
    }

    // Reconcile the join: money in the cost report this probe found no resource
    // for is money with no verdict, and that has to be visible rather than
    // quietly dropped.
    $costCoverage = null;
    if ($cost['doc'] !== null) {
        $ids = [];
        foreach ($out as $r) {
            $ids[$r['id']] = true;
        }
        $joined = 0;
        $missing = 0;
        foreach ($cost['byId'] as $id => $_) {
            isset($ids[$id]) ? $joined++ : $missing++;
        }
        $costCoverage = [
            'usageRowsJoined' => $joined,
            'usageRowsWithoutListedResource' => $missing,
        ];
    }

    $order = [V_ORPHAN => 0, V_SUPERSEDED => 1, V_ACTIVE => 2, V_UNPROVEN => 3, V_IN_USE => 4];
    usort($out, static function ($a, $b) use ($order) {
        return [$order[$a['verdict']], -(int) ($a['cost']['totalCents'] ?? 0)]
            <=> [$order[$b['verdict']], -(int) ($b['cost']['totalCents'] ?? 0)];
    });
    usort($misattributions, static fn ($a, $b) => $b['totalCents'] <=> $a['totalCents']);

    $schemaCount = 0;
    foreach ($clusters as $c) {
        $schemaCount += count($c['schemas'] ?? []);
    }

    $doc = [
        'schemaVersion' => SCHEMA_VERSION,
        'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
        'currency' => $cost['doc']['currency'] ?? 'USD',
        'lastUpdatedAt' => $cost['doc']['lastUpdatedAt'] ?? null,
        'period' => $cost['doc']['period'] ?? null,
        'metricsWindow' => $opts['metrics'] ? $opts['window'] : 'not fetched',
        'activeHoursThreshold' => (float) $opts['active-hours'],
        'callBudget' => [
            'planned' => $planned,
            'made' => $cli->made,
            'costReportCalls' => $costCalls,
            'rateLimited' => $cli->rateLimited,
            'retried' => $cli->retries,
        ],
        'inventory' => [
            'applications' => count($apps),
            'environments' => count($envs),
            'databaseClusters' => count($clusters),
            'databaseSchemas' => $schemaCount,
            'caches' => count($caches),
            'buckets' => count($buckets),
            'websocketClusters' => count($wsClusters),
        ],
        'verdictCounts' => $verdictCounts,
        'resources' => $out,
        'misattributions' => $misattributions,
        'mapSuggestion' => $mapSuggestion,
        'danglingReferences' => $dangling,
        'costCoverage' => $costCoverage,
        'errors' => $errors,
    ];

    if ($opts['json']) {
        fwrite(STDOUT, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    } else {
        fwrite(STDOUT, render_text($doc));
    }

    exit(0);
} catch (CloudCliError $e) {
    fwrite(STDERR, "cloud CLI: {$e->getMessage()}\n");
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
    exit(1);
}
