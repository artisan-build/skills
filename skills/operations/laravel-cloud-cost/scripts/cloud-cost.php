#!/usr/bin/env php
<?php

/**
 * cloud-cost.php
 *
 * Reads one Laravel Cloud billing period through the `cloud` CLI, attributes every
 * cost line to an application, reconciles the parts against the published total,
 * and emits findings worth a human's attention.
 *
 * Reads only. It never changes a Cloud resource and never writes a credential
 * anywhere. Requires the `cloud` CLI, authenticated.
 *
 * Exit codes: 0 success, 1 failure, 2 misuse.
 */

declare(strict_types=1);

const SCHEMA_VERSION = 1;

// Confidence levels for attributing a cost line to an application.
const CONF_LINKED       = 'linked';        // joined on a real identifier
const CONF_OVERRIDE     = 'override';      // the operator said so, in a map file
const CONF_INFERRED     = 'inferred';      // matched by naming convention
const CONF_UNATTRIBUTED = 'unattributed';  // neither

// ---------------------------------------------------------------- arguments

function usage(): string
{
    return <<<TXT
    Usage: php cloud-cost.php [options]

      --period=WHICH     current | previous | 1 | 2 | 3     (default: current)
      --dir=PATH         run the cloud CLI from PATH. Needed when more than one
                         organization token is configured: point it at a repo whose
                         .cloud/config.json selects the organization you want.
      --cloud=PATH       path to the cloud binary                (default: cloud)
      --map=FILE         attribution overrides, JSON: {"resource name": "app name"}
      --environments     also fetch per-environment compute detail
                         (one extra call per environment)
      --no-trend         skip the prior-period fetches used for trend findings
      --no-findings      report cost only
      --json             emit one JSON object on stdout and nothing else
      -h, --help         this text

    TXT;
}

$opts = [
    'period' => 'current',
    'dir' => null,
    'cloud' => 'cloud',
    'map' => null,
    'environments' => false,
    'trend' => true,
    'findings' => true,
    'json' => false,
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '-h' || $arg === '--help') {
        fwrite(STDOUT, usage());
        exit(0);
    }
    if ($arg === '--json')          { $opts['json'] = true; continue; }
    if ($arg === '--environments')  { $opts['environments'] = true; continue; }
    if ($arg === '--no-trend')      { $opts['trend'] = false; continue; }
    if ($arg === '--no-findings')   { $opts['findings'] = false; continue; }
    if (preg_match('/^--(period|dir|cloud|map)=(.*)$/', $arg, $m)) {
        $opts[$m[1]] = $m[2];
        continue;
    }
    fwrite(STDERR, "unknown option: {$arg}\n\n" . usage());
    exit(2);
}

if (! in_array((string) $opts['period'], ['current', 'previous', '0', '1', '2', '3'], true)) {
    fwrite(STDERR, "--period must be one of: current, previous, 1, 2, 3\n");
    exit(2);
}

// ---------------------------------------------------------------- the CLI

final class CloudCliError extends RuntimeException {}

/**
 * Run the cloud CLI and decode its JSON. Anything other than a clean exit and a
 * decodable body is an error the caller has to see, not a value to guess around.
 */
function cloud(array $opts, array $args): mixed
{
    $cmd = escapeshellcmd($opts['cloud']);
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }
    $cmd .= ' --json --no-interaction 2>&1';

    if ($opts['dir'] !== null) {
        $cmd = 'cd ' . escapeshellarg($opts['dir']) . ' && ' . $cmd;
    }

    exec($cmd, $lines, $rc);
    $out = implode("\n", $lines);

    if ($rc !== 0) {
        throw new CloudCliError(explain(trim(strip_ansi($out))));
    }

    $decoded = json_decode($out, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new CloudCliError('the CLI did not return JSON: ' . trim(strip_ansi(substr($out, 0, 400))));
    }

    // Some failures come back as an error object with a zero exit code.
    if (is_array($decoded) && ($decoded['error'] ?? false) === true) {
        throw new CloudCliError(explain((string) ($decoded['message'] ?? 'unknown error')));
    }

    return $decoded;
}

function strip_ansi(string $s): string
{
    return preg_replace('/\e\[[0-9;]*m/', '', $s) ?? $s;
}

/**
 * Turn the CLI's own wording into something the reader can act on. The first two
 * are the failures every new user hits, and neither message says what to do next.
 */
function explain(string $message): string
{
    if (str_contains($message, 'Multiple API tokens')) {
        return "more than one organization is authenticated, so the CLI cannot tell which one you mean.\n"
            . "Pass --dir=PATH pointing at a repository whose .cloud/config.json selects the organization\n"
            . "you want to report on (the file holds an organization_id and is created by `cloud repo:config`).\n"
            . "Original message: {$message}";
    }

    if (str_contains($message, 'Unauthenticated') || str_contains($message, '401')) {
        return "not authenticated. Run: cloud auth\nOriginal message: {$message}";
    }

    if (str_contains($message, 'include(s)')) {
        return "the CLI asked the API for data the API rejected. This is an upstream bug, not a\n"
            . "problem with your setup. Try `cloud self-update`.\nOriginal message: {$message}";
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

// ---------------------------------------------------------------- money

function dollars(?int $cents): string
{
    return '$' . number_format(($cents ?? 0) / 100, 2);
}

// ---------------------------------------------------------------- attribution

/**
 * Normalise a name for matching: lowercase, strip everything but letters and digits.
 * `acme-crm.com` and `acme_crm` and `ACME-CRM` all collapse to `acmecrm`.
 */
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
 * Build the name index a resource is matched against.
 *
 * Laravel Cloud names an auto-provisioned resource after the application, sometimes
 * with the environment appended: `acme_crm_production`, `widgets_production`,
 * `beacon`. That convention is the only signal available, because the API exposes no
 * foreign key from a database, cache, bucket, or websocket cluster back to the
 * application that uses it. Everything this index produces is therefore `inferred`.
 *
 * @return array<string, array<string, true>> normalised key => set of app ids
 */
function build_index(array $apps): array
{
    $index = [];
    $add = static function (string $key, string $appId) use (&$index): void {
        $key = norm($key);
        if (strlen($key) < 4) {
            return; // too short to match on without collisions
        }
        $index[$key][$appId] = true;
    };

    foreach ($apps as $app) {
        $bases = array_unique(array_filter([
            $app['slug'] ?? null,
            $app['name'] ?? null,
            isset($app['slug']) ? strip_tld($app['slug']) : null,
            isset($app['name']) ? strip_tld($app['name']) : null,
        ]));

        foreach ($bases as $base) {
            $add($base, $app['id']);
            foreach ($app['environments'] ?? [] as $env) {
                foreach (array_unique(array_filter([$env['name'] ?? null, $env['slug'] ?? null])) as $suffix) {
                    $add($base . '_' . $suffix, $app['id']);
                }
            }
        }
    }

    return $index;
}

/**
 * @return array{appId: ?string, confidence: string, ambiguous: array<int, string>}
 */
function attribute(string $resourceName, array $index, array $overrides, array $appsByNorm): array
{
    $n = norm($resourceName);

    if (isset($overrides[$n])) {
        return ['appId' => $overrides[$n], 'confidence' => CONF_OVERRIDE, 'ambiguous' => []];
    }

    $hit = $index[$n] ?? null;

    if ($hit === null) {
        // Longest prefix wins: `acme_crm_production` should match the
        // `acme_crm_production` key over the shorter `acmecrm` one.
        $best = null;
        foreach ($index as $key => $_) {
            if (str_starts_with($n, $key) && ($best === null || strlen($key) > strlen($best))) {
                $best = $key;
            }
        }
        $hit = $best === null ? null : $index[$best];
    }

    if ($hit === null) {
        return ['appId' => null, 'confidence' => CONF_UNATTRIBUTED, 'ambiguous' => []];
    }

    $ids = array_keys($hit);
    if (count($ids) > 1) {
        return ['appId' => null, 'confidence' => CONF_UNATTRIBUTED, 'ambiguous' => $ids];
    }

    return ['appId' => $ids[0], 'confidence' => CONF_INFERRED, 'ambiguous' => []];
}

function load_overrides(?string $path, array $apps): array
{
    if ($path === null) {
        return [];
    }
    if (! is_readable($path)) {
        throw new RuntimeException("map file not readable: {$path}");
    }

    $raw = json_decode((string) file_get_contents($path), true);
    if (! is_array($raw)) {
        throw new RuntimeException("map file is not a JSON object: {$path}");
    }

    $byName = [];
    foreach ($apps as $app) {
        $byName[norm($app['name'] ?? '')] = $app['id'];
        $byName[norm($app['slug'] ?? '')] = $app['id'];
        $byName[$app['id']] = $app['id'];
    }

    $out = [];
    foreach ($raw as $resource => $target) {
        $id = $byName[$target] ?? $byName[norm((string) $target)] ?? null;
        if ($id === null) {
            throw new RuntimeException("map file points '{$resource}' at an unknown application: {$target}");
        }
        $out[norm((string) $resource)] = $id;
    }

    return $out;
}

// ---------------------------------------------------------------- period shape

/**
 * How many hours this billing period covers.
 *
 * Taken from the data rather than the calendar: a cache or websocket cluster that
 * ran the whole period reports exactly the period length in hours, and taking the
 * maximum ignores resources created partway through. Returns null when nothing in
 * the payload reports hours, in which case hour-based findings are skipped rather
 * than guessed at.
 */
function period_hours(array $usage): ?float
{
    $hours = [];
    foreach ($usage['caches'] ?? [] as $c) {
        $hours[] = (float) ($c['computeHours'] ?? 0);
    }
    foreach ($usage['websockets'] ?? [] as $w) {
        $hours[] = (float) ($w['usageTimeHours'] ?? 0);
    }
    foreach ($usage['environmentUsageItems'] ?? [] as $i) {
        $hours[] = (float) ($i['cpuHours'] ?? 0);
    }

    $max = $hours === [] ? 0.0 : max($hours);

    return $max > 0 ? $max : null;
}

// ---------------------------------------------------------------- the report

final class Report
{
    public array $apps = [];          // appId => row
    public array $unattributed = [];  // resource rows
    public array $findings = [];

    public function app(string $id, ?array $meta = null): array
    {
        if (! isset($this->apps[$id])) {
            $this->apps[$id] = [
                'id' => $id,
                'name' => $meta['name'] ?? null,
                'slug' => $meta['slug'] ?? null,
                'known' => $meta !== null,
                'computeCents' => 0,
                'resourceCents' => 0,
                'totalCents' => 0,
                'resources' => [],
                'environments' => [],
            ];
        }

        return $this->apps[$id];
    }
}

const RESOURCE_FAMILIES = [
    'databases'  => 'database',
    'caches'     => 'cache',
    'buckets'    => 'bucket',
    'websockets' => 'websocket',
];

function build_report(array $usage, array $apps, array $index, array $overrides): Report
{
    $report = new Report();
    $appsById = [];
    foreach ($apps as $a) {
        $appsById[$a['id']] = $a;
    }

    // Compute cost joins on a real application id. This half is `linked`.
    foreach ($usage['applications'] ?? [] as $row) {
        $id = (string) $row['identifier'];
        $meta = $appsById[$id] ?? null;
        $report->app($id, $meta);
        $report->apps[$id]['computeCents'] += (int) ($row['totalCostCents'] ?? 0);
        $report->apps[$id]['confidence'] = CONF_LINKED;
    }

    // Applications with no billed compute this period still belong in the report.
    foreach ($apps as $a) {
        $report->app($a['id'], $a);
    }

    // Attached resources carry no foreign key. Match on name, label the result.
    foreach (RESOURCE_FAMILIES as $key => $kind) {
        foreach ($usage[$key] ?? [] as $row) {
            $name = (string) ($row['name'] ?? '');
            $cents = (int) ($row['totalCents'] ?? 0);
            $match = attribute($name, $index, $overrides, $appsById);

            $entry = [
                'kind' => $kind,
                'name' => $name,
                'identifier' => $row['identifier'] ?? null,
                'type' => $row['type'] ?? null,
                'totalCents' => $cents,
                'confidence' => $match['confidence'],
                'raw' => $row,
            ];

            if ($match['appId'] === null) {
                $entry['ambiguousBetween'] = array_map(
                    static fn ($id) => $appsById[$id]['name'] ?? $id,
                    $match['ambiguous'],
                );
                $report->unattributed[] = $entry;
                continue;
            }

            $report->app($match['appId'], $appsById[$match['appId']] ?? null);
            $report->apps[$match['appId']]['resources'][] = $entry;
            $report->apps[$match['appId']]['resourceCents'] += $cents;
        }
    }

    foreach ($report->apps as $id => $row) {
        $report->apps[$id]['totalCents'] = $row['computeCents'] + $row['resourceCents'];
    }

    uasort($report->apps, static fn ($a, $b) => $b['totalCents'] <=> $a['totalCents']);
    usort($report->unattributed, static fn ($a, $b) => $b['totalCents'] <=> $a['totalCents']);

    return $report;
}

function reconcile(array $usage, Report $report): array
{
    $appsTotal = (int) ($usage['applicationsTotalCostCents'] ?? 0);
    $resourcesTotal = (int) ($usage['resourcesTotalCostCents'] ?? 0);
    $addons = (int) ($usage['addonsTotalCostCents'] ?? 0);
    $bandwidth = (int) ($usage['bandwidth']['costCents'] ?? 0);
    $published = (int) ($usage['currentSpendCents'] ?? 0);

    $summedResources = 0;
    foreach (RESOURCE_FAMILIES as $key => $_) {
        foreach ($usage[$key] ?? [] as $row) {
            $summedResources += (int) ($row['totalCents'] ?? 0);
        }
    }

    $summedApps = 0;
    foreach ($usage['applications'] ?? [] as $row) {
        $summedApps += (int) ($row['totalCostCents'] ?? 0);
    }

    $parts = $appsTotal + $resourcesTotal + $addons + $bandwidth;

    return [
        'publishedTotalCents' => $published,
        'partsTotalCents' => $parts,
        'discrepancyCents' => $published - $parts,
        'reconciles' => $published === $parts,
        'applicationsAddUp' => $summedApps === $appsTotal,
        'resourcesAddUp' => $summedResources === $resourcesTotal,
        'attributedCents' => array_sum(array_column($report->apps, 'totalCents')),
        'unattributedCents' => array_sum(array_column($report->unattributed, 'totalCents')),
    ];
}

// ---------------------------------------------------------------- findings

const SEV_ORDER = ['high' => 0, 'medium' => 1, 'info' => 2];

function finding(string $id, string $severity, string $title, string $subject, int $cents, string $evidence, string $check): array
{
    return compact('id', 'severity', 'title', 'subject', 'evidence', 'check') + ['periodCents' => $cents];
}

/**
 * Everything here is an observation with its evidence attached. None of it is an
 * instruction: whether a cost is wrong depends on what the application is for, and
 * this script does not know that.
 */
function findings(array $usage, Report $report, array $apps, ?float $hours, array $trend, string $reportedLabel): array
{
    $out = [];

    // Money nobody can put a name to.
    foreach ($report->unattributed as $r) {
        if ($r['totalCents'] > 0 && ($r['ambiguousBetween'] ?? []) === []) {
            $out[] = finding(
                'unattributed-resource',
                $r['totalCents'] >= 500 ? 'high' : 'medium',
                'Resource cost that matches no application',
                "{$r['kind']} '{$r['name']}'",
                $r['totalCents'],
                "Billed " . dollars($r['totalCents']) . " this period. Its name matches no application in this organization.",
                'Find out which application uses it. A resource nobody can name is a resource nobody is watching, and it is the most common way a deleted project keeps billing.',
            );
        } elseif ($r['totalCents'] === 0) {
            $out[] = finding(
                'orphan-resource',
                'info',
                'Idle resource with no application',
                "{$r['kind']} '{$r['name']}'",
                0,
                'Cost nothing this period and matches no application.',
                'Probably left over. Confirm nothing points at it, then delete it so it stops appearing in this report.',
            );
        }

        if (($r['ambiguousBetween'] ?? []) !== []) {
            $out[] = finding(
                'ambiguous-attribution',
                $r['totalCents'] >= 500 ? 'medium' : 'info',
                'Resource name matches more than one application',
                "{$r['kind']} '{$r['name']}'",
                $r['totalCents'],
                'Matches: ' . implode(', ', $r['ambiguousBetween']) . '.',
                'Pin it with a --map entry so this report stops guessing.',
            );
        }
    }

    // Billed after the application record went away.
    foreach ($report->apps as $row) {
        if (! $row['known'] && $row['computeCents'] > 0) {
            $out[] = finding(
                'billed-unknown-application',
                'high',
                'Compute billed for an application that no longer exists',
                $row['id'],
                $row['computeCents'],
                'Billed ' . dollars($row['computeCents']) . ' this period, but no application with this id is listed in the organization.',
                'Usually an application deleted partway through the period, billed for the part it ran. If it appears in a completed period too, ask Laravel Cloud support about it.',
            );
        }
    }

    // A resource that costs more than the application it serves.
    foreach ($report->apps as $row) {
        foreach ($row['resources'] as $r) {
            if ($r['totalCents'] >= 500 && $row['computeCents'] > 0 && $r['totalCents'] > 2 * $row['computeCents']) {
                $out[] = finding(
                    'resource-outweighs-application',
                    'medium',
                    'Attached resource costs several times the application it serves',
                    "{$r['kind']} '{$r['name']}' on " . ($row['name'] ?? $row['id']),
                    $r['totalCents'],
                    dollars($r['totalCents']) . ' for the ' . $r['kind'] . ' against ' . dollars($row['computeCents']) . ' of compute for the application.',
                    'Check the size it was provisioned at. A tier chosen for a launch that has not happened yet bills the same as one under load.',
                );
            }
        }
    }

    // Two of the same kind of resource inferred onto one application.
    foreach ($report->apps as $row) {
        $byKind = [];
        foreach ($row['resources'] as $r) {
            $byKind[$r['kind']][] = $r;
        }
        foreach ($byKind as $kind => $list) {
            if (count($list) > 1) {
                $names = implode(', ', array_map(static fn ($r) => "'{$r['name']}' (" . dollars($r['totalCents']) . ')', $list));
                $out[] = finding(
                    'duplicate-resource-family',
                    'medium',
                    "More than one {$kind} attributed to one application",
                    (string) ($row['name'] ?? $row['id']),
                    array_sum(array_column($list, 'totalCents')),
                    "{$names}.",
                    'Often a replacement provisioned while the original stayed up. Confirm which one the application actually connects to, and whether the other still needs to exist.',
                );
            }
        }
    }

    // Serverless compute that never varies is serverless compute that never sleeps.
    if (count($trend) >= 2) {
        foreach ($usage['databases'] ?? [] as $db) {
            if (! str_contains(strtolower((string) ($db['type'] ?? '')), 'serverless')) {
                continue;
            }
            $id = (string) ($db['identifier'] ?? '');

            // Seed with the period being reported, so the evidence shows every
            // period it was measured over and the test is made over all of them.
            $series = [$reportedLabel => (int) ($db['totalCents'] ?? 0)];

            foreach ($trend as $label => $byId) {
                if (isset($byId[$id])) {
                    $series[$label] = $byId[$id];
                }
            }
            if (count($series) < 2) {
                continue;
            }
            $vals = array_values($series);
            $min = min($vals);
            $max = max($vals);
            if ($min < 500) {
                continue; // small enough that flatness says nothing
            }
            if ($max > 0 && ($max - $min) / $max <= 0.10) {
                $detail = [];
                foreach ($series as $label => $cents) {
                    $detail[] = "{$label}: " . dollars($cents);
                }
                $out[] = finding(
                    'serverless-database-never-idles',
                    'high',
                    'Serverless database billed a flat amount every period',
                    "database '{$db['name']}'",
                    (int) round(array_sum($vals) / count($vals)),
                    implode(', ', $detail) . '. Serverless compute that bills the same every period is compute that never scaled down.',
                    'Find what keeps it awake: a health check that queries, a scheduler running every minute, a persistent connection pool, or a scale-to-zero delay longer than the gap between requests. Confirm against the database metrics in the dashboard before changing anything.',
                );
            }
        }
    }

    // Websocket clusters bill for wall-clock time, used or not.
    if ($hours !== null) {
        foreach ($usage['websockets'] ?? [] as $w) {
            $used = (float) ($w['usageTimeHours'] ?? 0);
            $cents = (int) ($w['totalCents'] ?? 0);
            if ($cents > 0 && $used >= 0.95 * $hours) {
                $out[] = finding(
                    'websocket-cluster-billed-full-period',
                    'medium',
                    'Websocket cluster billed for the whole period',
                    "websocket '{$w['name']}'",
                    $cents,
                    dollars($cents) . ' for ' . round($used) . ' hours, which is the full period. Websocket clusters bill for uptime, not for connections.',
                    'Check whether the application still broadcasts. A cluster left up after a feature was removed bills exactly the same as one carrying traffic.',
                );
            }
        }
    }

    // Storage nobody reads.
    foreach ($usage['buckets'] ?? [] as $b) {
        $requests = (int) ($b['classARequestsCount'] ?? 0) + (int) ($b['classBRequestsCount'] ?? 0);
        $storage = (float) ($b['storageGb'] ?? 0);
        if ($requests === 0 && $storage >= 0.5) {
            $out[] = finding(
                'bucket-storage-without-requests',
                (int) ($b['totalCents'] ?? 0) >= 100 ? 'medium' : 'info',
                'Bucket holding data that was not read this period',
                "bucket '{$b['name']}'",
                (int) ($b['totalCents'] ?? 0),
                round($storage, 2) . ' GB stored, zero requests of either class this period.',
                'Either it is a backup target working as intended, or it is the residue of something that stopped running. Check which before deleting anything.',
            );
        }
    }

    // Bandwidth is an allowance, so the interesting moment is before it is spent.
    $pct = (int) ($usage['bandwidth']['usagePercentage'] ?? 0);
    if ($pct >= 80) {
        $out[] = finding(
            'bandwidth-allowance-nearly-spent',
            $pct >= 95 ? 'high' : 'medium',
            'Bandwidth allowance nearly spent',
            'organization',
            (int) ($usage['bandwidth']['costCents'] ?? 0),
            "{$pct}% of the included allowance used this period.",
            'Overage bills per byte once the allowance is gone. Check what is serving large responses from the application rather than from object storage or a CDN.',
        );
    }

    // Per-environment findings, only when the caller paid for the fan-out.
    foreach ($report->apps as $row) {
        foreach ($row['environments'] as $env) {
            $isProd = in_array(strtolower((string) $env['name']), ['main', 'production', 'prod', 'master'], true);
            if (! $isProd && $env['totalCents'] >= 200) {
                $out[] = finding(
                    'non-production-environment-cost',
                    'medium',
                    'Non-production environment carrying real cost',
                    ($row['name'] ?? $row['id']) . " / {$env['name']}",
                    $env['totalCents'],
                    dollars($env['totalCents']) . ' this period.',
                    'Confirm somebody still uses it. A staging environment nobody has opened in a month costs the same as one under test.',
                );
            }

            if ($hours !== null && $env['cpuHours'] !== null && $env['cpuHours'] >= 0.95 * $hours && $env['usesHibernation'] === false && $env['totalCents'] > 0) {
                $out[] = finding(
                    'instance-runs-continuously-without-hibernation',
                    'info',
                    'Environment ran continuously with hibernation off',
                    ($row['name'] ?? $row['id']) . " / {$env['name']}",
                    $env['totalCents'],
                    round((float) $env['cpuHours']) . ' of ' . round($hours) . ' hours billed, hibernation disabled.',
                    'Correct for anything serving traffic. Worth a look for a demo, a staging environment, or a side project where the traffic does not justify a warm instance.',
                );
            }
        }
    }

    usort($out, static function ($a, $b) {
        return [SEV_ORDER[$a['severity']], -$a['periodCents']] <=> [SEV_ORDER[$b['severity']], -$b['periodCents']];
    });

    return $out;
}

// ---------------------------------------------------------------- rendering

function render_text(array $doc): string
{
    $o = '';
    $p = $doc['period'];
    $state = $p['complete'] ? 'complete' : 'in progress';

    $o .= "Laravel Cloud cost: {$p['from']} to {$p['to']} (period {$p['index']}, {$state})\n";
    $o .= "Figures in {$doc['currency']}, as of {$doc['lastUpdatedAt']}\n";
    $o .= str_repeat('=', 72) . "\n\n";

    $t = $doc['totals'];
    $o .= 'TOTAL  ' . dollars($t['publishedTotalCents']) . "\n";
    $o .= '  compute, linked to an application   ' . str_pad(dollars($doc['breakdown']['applicationsCents']), 12, ' ', STR_PAD_LEFT) . "\n";
    $o .= '  attached resources, inferred        ' . str_pad(dollars($doc['breakdown']['resourcesCents']), 12, ' ', STR_PAD_LEFT) . "\n";
    $o .= '  add-ons                             ' . str_pad(dollars($doc['breakdown']['addonsCents']), 12, ' ', STR_PAD_LEFT) . "\n";
    $o .= '  bandwidth                           ' . str_pad(dollars($doc['breakdown']['bandwidthCents']), 12, ' ', STR_PAD_LEFT)
        . '   (' . $doc['bandwidth']['usagePercentage'] . "% of allowance)\n";

    if (! $t['reconciles']) {
        $o .= "\n  ! the published total and the sum of its parts differ by "
            . dollars(abs($t['discrepancyCents'])) . ". Numbers below are the parts.\n";
    }
    if ($t['unattributedCents'] > 0) {
        $o .= "\n  ! " . dollars($t['unattributedCents']) . " could not be attributed to any application (see below).\n";
    }

    $o .= "\nPER APPLICATION\n";
    $o .= sprintf("  %-32s %10s %10s %10s\n", 'application', 'total', 'compute', 'resources');
    $o .= '  ' . str_repeat('-', 64) . "\n";
    foreach ($doc['apps'] as $a) {
        if ($a['totalCents'] === 0) {
            continue;
        }
        $label = $a['name'] ?? ('(unknown) ' . $a['id']);
        $o .= sprintf(
            "  %-32s %10s %10s %10s\n",
            substr($label, 0, 32),
            dollars($a['totalCents']),
            dollars($a['computeCents']),
            dollars($a['resourceCents']),
        );
    }

    $zero = array_filter($doc['apps'], static fn ($a) => $a['totalCents'] === 0);
    if ($zero !== []) {
        $o .= '  ' . count($zero) . " application(s) billed nothing this period.\n";
    }

    $o .= "\nBREAKDOWN\n";
    foreach ($doc['apps'] as $a) {
        if ($a['totalCents'] === 0) {
            continue;
        }
        $o .= '  ' . ($a['name'] ?? $a['id']) . '  ' . dollars($a['totalCents']) . "\n";
        $o .= sprintf("      %-46s %9s  %s\n", 'compute', dollars($a['computeCents']), CONF_LINKED);
        foreach ($a['resources'] as $r) {
            $o .= sprintf(
                "      %-46s %9s  %s\n",
                substr($r['kind'] . " '" . $r['name'] . "'" . ($r['type'] ? ' (' . $r['type'] . ')' : ''), 0, 46),
                dollars($r['totalCents']),
                $r['confidence'],
            );
        }
        foreach ($a['environments'] as $e) {
            $hrs = $e['cpuHours'] === null ? '' : '  ' . round((float) $e['cpuHours']) . ' cpu-hours';
            $o .= sprintf("      %-46s %9s%s\n", 'environment ' . $e['name'], dollars($e['totalCents']), $hrs);
        }
        $o .= "\n";
    }

    if ($doc['unattributed'] !== []) {
        $o .= 'UNATTRIBUTED  ' . dollars($doc['totals']['unattributedCents']) . "\n";
        $o .= "  Resources whose name matches no application. The API exposes no link from a\n";
        $o .= "  resource to the application that uses it, so this report matches on naming.\n\n";
        foreach ($doc['unattributed'] as $r) {
            $extra = ($r['ambiguousBetween'] ?? []) === [] ? '' : '  matches: ' . implode(', ', $r['ambiguousBetween']);
            $o .= sprintf("      %-46s %9s%s\n", substr($r['kind'] . " '" . $r['name'] . "'", 0, 46), dollars($r['totalCents']), $extra);
        }
        $o .= "\n";
    }

    if (($doc['findings'] ?? []) !== []) {
        $o .= "FINDINGS\n\n";
        foreach ($doc['findings'] as $f) {
            $o .= '  [' . strtoupper($f['severity']) . '] ' . $f['title'] . "\n";
            $o .= '      subject:  ' . $f['subject'] . '  (' . dollars($f['periodCents']) . " this period)\n";
            $o .= '      evidence: ' . wrap($f['evidence']) . "\n";
            $o .= '      check:    ' . wrap($f['check']) . "\n\n";
        }
    } elseif (isset($doc['findings'])) {
        $o .= "FINDINGS\n\n  Nothing flagged.\n\n";
    }

    return $o;
}

function wrap(string $s): string
{
    return implode("\n                ", explode("\n", wordwrap($s, 76, "\n", false)));
}

// ---------------------------------------------------------------- main

try {
    preflight($opts);

    $usage = cloud($opts, ['usage', '--period=' . $opts['period']]);

    // Narrow the application fetch to the fields this report needs. The full
    // payload carries build commands, which routinely contain credentials; asking
    // for less means never holding them in the first place.
    $apps = cloud($opts, [
        'application:list',
        '--fields=id,name,slug,region,environments.id,environments.name,environments.slug,'
        . 'environments.status,environments.usesHibernation,environments.usesOctane',
    ]);

    if (! is_array($apps)) {
        throw new RuntimeException('application:list did not return a list');
    }

    $overrides = load_overrides($opts['map'], $apps);
    $index = build_index($apps);
    $report = build_report($usage, $apps, $index, $overrides);

    $periodIndex = (int) ($usage['period'] ?? 0);
    $hours = period_hours($usage);
    $reportedLabel = ($usage['availablePeriods'][$periodIndex]['from'] ?? "period {$periodIndex}")
        . '-' . ($usage['availablePeriods'][$periodIndex]['to'] ?? '');

    // Per-environment compute, one call per environment. Off by default.
    if ($opts['environments']) {
        foreach ($apps as $app) {
            foreach ($app['environments'] ?? [] as $env) {
                $detail = cloud($opts, ['usage', '--period=' . $opts['period'], '--environment=' . $env['id']]);
                $items = $detail['environmentUsageItems'] ?? [];
                $cpuHours = null;
                foreach ($items as $i) {
                    if (($i['type'] ?? '') === 'app') {
                        $cpuHours = (float) ($i['cpuHours'] ?? 0);
                    }
                }
                $report->app($app['id'], $app);
                $report->apps[$app['id']]['environments'][] = [
                    'id' => $env['id'],
                    'name' => $env['name'] ?? '',
                    'totalCents' => (int) ($detail['environmentUsageTotalCostCents'] ?? 0),
                    'cpuHours' => $cpuHours,
                    'usesHibernation' => $env['usesHibernation'] ?? null,
                    'items' => $items,
                ];
            }
        }
    }

    // Prior complete periods, for findings that need a shape over time.
    $trend = [];
    if ($opts['trend'] && $opts['findings']) {
        foreach ([1, 2, 3] as $p) {
            if ($p === $periodIndex) {
                continue;
            }
            try {
                $prior = cloud($opts, ['usage', '--period=' . $p]);
            } catch (CloudCliError) {
                continue; // an organization younger than three periods is fine
            }
            $label = ($prior['availablePeriods'][$p]['from'] ?? "period {$p}") . '-' . ($prior['availablePeriods'][$p]['to'] ?? '');
            $byId = [];
            foreach (RESOURCE_FAMILIES as $key => $_) {
                foreach ($prior[$key] ?? [] as $row) {
                    $byId[(string) ($row['identifier'] ?? '')] = (int) ($row['totalCents'] ?? 0);
                }
            }
            $trend[$label] = $byId;
        }
    }

    $totals = reconcile($usage, $report);

    $doc = [
        'schemaVersion' => SCHEMA_VERSION,
        'currency' => $usage['currency'] ?? 'USD',
        'lastUpdatedAt' => $usage['lastUpdatedAt'] ?? null,
        'period' => [
            'index' => $periodIndex,
            'from' => $usage['availablePeriods'][$periodIndex]['from'] ?? null,
            'to' => $usage['availablePeriods'][$periodIndex]['to'] ?? null,
            'complete' => $periodIndex !== 0,
            'hours' => $hours,
        ],
        'totals' => $totals,
        'breakdown' => [
            'applicationsCents' => (int) ($usage['applicationsTotalCostCents'] ?? 0),
            'resourcesCents' => (int) ($usage['resourcesTotalCostCents'] ?? 0),
            'addonsCents' => (int) ($usage['addonsTotalCostCents'] ?? 0),
            'bandwidthCents' => (int) ($usage['bandwidth']['costCents'] ?? 0),
        ],
        'bandwidth' => $usage['bandwidth'] ?? [],
        'apps' => array_values(array_map(static function (array $a) {
            unset($a['confidence']);
            $a['resources'] = array_map(static function (array $r) {
                unset($r['raw']);

                return $r;
            }, $a['resources']);

            return $a;
        }, $report->apps)),
        'unattributed' => array_map(static function (array $r) {
            unset($r['raw']);

            return $r;
        }, $report->unattributed),
        'addons' => $usage['addonItems'] ?? [],
    ];

    if ($opts['findings']) {
        $doc['findings'] = findings($usage, $report, $apps, $hours, $trend, $reportedLabel);
    }

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
