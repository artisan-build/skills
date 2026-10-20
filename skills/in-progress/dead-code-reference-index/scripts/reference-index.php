#!/usr/bin/env php
<?php

/**
 * reference-index.php
 *
 * Searches for references to candidate symbols OUTSIDE the application they
 * live in, and reports every hit with its source, location, match kind and
 * confidence.
 *
 * A static analyser inside one application can only see that application. It
 * calls a symbol unreachable when nothing in that tree reaches it, which is a
 * different claim from "nothing anywhere uses this". A job class named in
 * another app's dispatch, a console command run from a runbook, a route a
 * third party posts to, a class name sitting in a `*_type` column: all of them
 * look unreachable from inside and are all alive.
 *
 * So this script runs beyond the application, over sources the caller names,
 * and only ever ADDS evidence of life. It never concludes that anything is
 * dead. No hits means no hits; it does not mean dead.
 *
 * Reads only. Writes nothing anywhere, including the database source, which
 * emits SQL for a human to run unless it is handed a DSN explicitly.
 *
 * Exit codes: 0 success, 1 failure, 2 misuse.
 */

declare(strict_types=1);

const SCHEMA_VERSION = 1;

// How strong the evidence is that this hit is really about this symbol. The
// third state, `unattributed`, belongs to a candidate with no hits at all, and
// is never a property of a hit.
const C_LINKED   = 'linked';   // the match cannot plausibly be another symbol
const C_INFERRED = 'inferred'; // the match could belong to something else

// What the index can say about a candidate once the sources have been read.
// None of these is `dead`; that verdict is not this script's to give.
const V_CONTRACT = 'contract';              // named in a published contract: removal is breaking
const V_CONTRACT_MAYBE = 'contract-possible'; // a contract hit, but on an inferred form of the name
const V_ALIVE    = 'alive-elsewhere';       // a linked reference outside the application
const V_REVIEW   = 'needs-review';          // only inferred references; a human or model decides
const V_NONE     = 'no-external-evidence';  // nothing matched. NOT a death certificate

// Directory names that never hold a reference worth having and cost minutes to
// walk. Overridable per source with "exclude".
const DEFAULT_EXCLUDE = [
    '.git', '.svn', '.hg', 'vendor', 'node_modules', 'bower_components',
    '.idea', '.vscode', '.fleet', 'storage', 'bootstrap/cache',
    'dist', 'build', 'coverage', '.phpunit.cache', '.pest',
    '__pycache__', '.venv', 'venv', '.next', '.nuxt', 'public/build',
];

// Extensions a reference can hide in, per source kind.
const DEFAULT_EXTENSIONS = [
    'repos'     => ['php', 'json', 'yml', 'yaml', 'xml', 'neon', 'sh', 'bash', 'env', 'ini', 'toml', 'js', 'ts', 'stub'],
    'ops'       => ['md', 'txt', 'sh', 'bash', 'yml', 'yaml', 'json', 'log', 'rst', 'org'],
    'deploy'    => ['yml', 'yaml', 'json', 'sh', 'bash', 'conf', 'cnf', 'ini', 'toml', 'env', 'txt', ''],
    'contracts' => ['yml', 'yaml', 'json', 'md', 'graphql', 'proto', 'raml', 'txt'],
];

// Relative paths the deploy source reads when the config names none itself.
const DEFAULT_DEPLOY_PATHS = [
    '.github/workflows', '.gitlab-ci.yml', '.cloud/config.json', '.cloud',
    'Procfile', 'Procfile.dev', 'crontab', 'crontabs', 'deploy', 'deploy.sh',
    'Dockerfile', 'docker-compose.yml', 'fly.toml', 'vapor.yml', 'nixpacks.toml',
];

const SOURCE_KINDS = ['repos', 'ops', 'deploy', 'contracts', 'github', 'database'];

// ---------------------------------------------------------------- arguments

function usage(): string
{
    return <<<TXT
    Usage: php reference-index.php --candidates=FILE --config=FILE [options]

      --candidates=FILE   the candidates to look for. Either a TSV with a header
                          row (column names are resolved by alias, so a producer
                          renaming its columns does not break this) or a plain
                          list of one symbol per line, optionally followed by a
                          tab and the symbol's kind.
      --config=FILE       JSON naming the search sources. Every root and every
                          source is the caller's to supply; nothing is assumed.
                          See CONFIG.md, or --print-config for a starter.
      --print-config      write a starter config to stdout and exit
      --only=A,B          run only these sources: repos, ops, deploy, contracts,
                          github, database
      --skip=A,B          run every configured source except these
      --include-no-context
                          also report short-name and bare-method matches in files
                          that carry no context tying them to the symbol. Off by
                          default: these are the script's false positives, and
                          they are counted either way.
      --max-file-kb=N     skip files bigger than this                (default 512)
      --max-hits=N        most hits kept per candidate               (default 200)
                          A very short needle, such as a one-word MCP tool name,
                          can match thousands of files. The count of what was
                          dropped is reported either way.
      --no-grep           walk with PHP instead of prefiltering with grep. Slower
                          and used automatically when grep is missing.
      --github-limit=N    most code-search queries this run          (default 30)
      --state=FILE        where GitHub query results are kept between runs.
                          Re-running with the same file resumes where it stopped.
      --db-dsn-env=VAR    run the database SQL using the PDO DSN in environment
                          variable VAR. Without this the database source prints
                          its SQL and runs nothing.
      --db-user-env=VAR   environment variable holding the database user
      --db-pass-env=VAR   environment variable holding the database password
      --allow-remote-db   permit a DSN whose host is not local. Refused otherwise,
                          because the host in a copied .env is often production.
      --no-snippet        leave the matched line out of the output
      --tsv               emit the hit table as TSV instead of a report
      --json              emit one JSON object on stdout and nothing else
      -h, --help          this text

    Reads only. Evidence from this script can move a candidate from dead to
    unknown or alive. It can never move one toward dead.

    TXT;
}

function starterConfig(): string
{
    return <<<'JSON'
    {
      "selfRoots": ["/absolute/path/to/the/application/under/test"],
      "sources": {
        "repos": {
          "roots": ["/absolute/path/to/your/other/checkouts"],
          "exclude": ["vendor", "node_modules", ".git"]
        },
        "ops": {
          "roots": ["/absolute/path/to/runbooks/and/playbooks"]
        },
        "deploy": {
          "roots": ["/absolute/path/to/the/application/under/test"],
          "paths": [".github/workflows", ".cloud/config.json", "Procfile", "crontab"]
        },
        "contracts": {
          "roots": ["/absolute/path/to/published/openapi/or/docs"]
        },
        "github": {
          "org": "your-org",
          "repos": [],
          "excludeRepos": ["your-org/the-application-under-test"],
          "extraQualifiers": ""
        },
        "database": {
          "driver": "mysql",
          "morphTypeColumns": ["taggables.taggable_type", "activity_log.subject_type"],
          "typeColumns": ["notifications.type"],
          "payloadColumns": ["jobs.payload", "failed_jobs.payload"],
          "extraColumns": ["settings.handler_class"]
        }
      }
    }
    JSON;
}

$opts = [
    'candidates' => null,
    'config' => null,
    'only' => [],
    'skip' => [],
    'include-no-context' => false,
    'max-file-kb' => 512,
    'max-hits' => 200,
    'grep' => true,
    'github-limit' => 30,
    'state' => null,
    'db-dsn-env' => null,
    'db-user-env' => null,
    'db-pass-env' => null,
    'allow-remote-db' => false,
    'snippet' => true,
    'tsv' => false,
    'json' => false,
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '-h' || $arg === '--help') { fwrite(STDOUT, usage()); exit(0); }
    if ($arg === '--print-config')          { fwrite(STDOUT, starterConfig() . "\n"); exit(0); }
    if ($arg === '--json')                  { $opts['json'] = true; continue; }
    if ($arg === '--tsv')                   { $opts['tsv'] = true; continue; }
    if ($arg === '--no-grep')               { $opts['grep'] = false; continue; }
    if ($arg === '--no-snippet')            { $opts['snippet'] = false; continue; }
    if ($arg === '--include-no-context')    { $opts['include-no-context'] = true; continue; }
    if ($arg === '--allow-remote-db')       { $opts['allow-remote-db'] = true; continue; }
    if (preg_match('/^--(candidates|config|state|db-dsn-env|db-user-env|db-pass-env)=(.+)$/', $arg, $m)) {
        $opts[$m[1]] = $m[2];
        continue;
    }
    if (preg_match('/^--(only|skip)=(.+)$/', $arg, $m)) {
        $opts[$m[1]] = array_values(array_filter(array_map('trim', explode(',', $m[2]))));
        continue;
    }
    if (preg_match('/^--(max-file-kb|max-hits|github-limit)=([0-9]+)$/', $arg, $m)) {
        $opts[$m[1]] = (int) $m[2];
        continue;
    }
    fwrite(STDERR, "unknown option: {$arg}\n\n" . usage());
    exit(2);
}

foreach (array_merge($opts['only'], $opts['skip']) as $name) {
    if (! in_array($name, SOURCE_KINDS, true)) {
        fwrite(STDERR, "unknown source '{$name}'. Sources are: " . implode(', ', SOURCE_KINDS) . "\n");
        exit(2);
    }
}

if ($opts['candidates'] === null) {
    fwrite(STDERR, "--candidates=FILE is required\n\n" . usage());
    exit(2);
}
if ($opts['config'] === null) {
    fwrite(STDERR, "--config=FILE is required. Run --print-config for a starter.\n\n" . usage());
    exit(2);
}
if ($opts['tsv'] && $opts['json']) {
    fwrite(STDERR, "--tsv and --json are mutually exclusive\n");
    exit(2);
}

// ---------------------------------------------------------------- candidates

/**
 * Column aliases for a candidates TSV.
 *
 * The producer of that file is a separate tool whose column names are still
 * moving, so headers are matched on a normalised form (lowercased, every run of
 * non-alphanumerics collapsed to one space) against these alias lists. A header
 * the script does not recognise is kept verbatim as an extra, never dropped.
 */
const COLUMN_ALIASES = [
    'symbol'        => ['symbol', 'candidate', 'name', 'fqcn', 'identifier'],
    'location'      => ['file line', 'location', 'file', 'where', 'path', 'site'],
    'kind'          => ['kind', 'type', 'symbol kind', 'category'],
    'staticVerdict' => ['static verdict', 'verdict', 'static', 'static analysis', 'static result'],
    'entryPoint'    => ['entry point evidence', 'entry point', 'entrypoint evidence', 'entrypoint', 'reachable from'],
    'prodEvidence'  => ['prod evidence', 'production evidence', 'runtime evidence', 'telemetry'],
    'decision'      => ['final decision', 'decision', 'outcome'],
    'reason'        => ['reason', 'notes', 'note', 'why', 'comment'],
];

function normaliseHeader(string $raw): string
{
    $lower = strtolower(trim($raw));

    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $lower));
}

/**
 * @return array{rows: array<int, array<string, mixed>>, format: string, columns: array<string, string>, headers: array<int, string>}
 */
function readCandidates(string $path): array
{
    if (! is_file($path) || ! is_readable($path)) {
        fwrite(STDERR, "cannot read candidates file: {$path}\n");
        exit(1);
    }

    $lines = preg_split('/\r\n|\r|\n/', (string) file_get_contents($path)) ?: [];
    $lines = array_values(array_filter($lines, static fn ($l) => trim($l) !== '' && ! str_starts_with(ltrim($l), '#')));

    if ($lines === []) {
        fwrite(STDERR, "candidates file is empty: {$path}\n");
        exit(1);
    }

    $first = explode("\t", $lines[0]);
    $mapped = [];
    foreach ($first as $i => $header) {
        $norm = normaliseHeader($header);
        foreach (COLUMN_ALIASES as $field => $aliases) {
            if (in_array($norm, $aliases, true) && ! isset($mapped[$field])) {
                $mapped[$field] = $i;
            }
        }
    }

    // A header row is a header row when it names a symbol column alongside at
    // least one other recognised column, or when its one column is explicitly
    // called `symbol` or `candidate`. Anything else is read as a plain list, so
    // a one-column file of symbols still works and a class called `Path` is not
    // mistaken for a header.
    $firstCell = normaliseHeader($first[0] ?? '');
    $isHeader = isset($mapped['symbol'])
        && (count($mapped) >= 2 || in_array($firstCell, ['symbol', 'candidate'], true));

    if (! $isHeader) {
        $rows = [];
        foreach ($lines as $line) {
            $cells = array_map('trim', explode("\t", $line));
            $symbol = $cells[0];
            if ($symbol === '') { continue; }
            $rows[] = [
                'symbol' => $symbol,
                'kind' => ($cells[1] ?? '') !== '' ? $cells[1] : inferKind($symbol),
                'location' => '', 'staticVerdict' => '', 'entryPoint' => '',
                'prodEvidence' => '', 'decision' => '', 'reason' => '', 'extra' => [],
            ];
        }

        return ['rows' => $rows, 'format' => 'list', 'columns' => [], 'headers' => []];
    }

    $columns = [];
    foreach ($mapped as $field => $i) {
        $columns[$field] = trim($first[$i]);
    }

    $rows = [];
    foreach (array_slice($lines, 1) as $line) {
        $cells = explode("\t", $line);
        $get = static function (string $field) use ($mapped, $cells): string {
            $i = $mapped[$field] ?? null;

            return $i === null ? '' : trim((string) ($cells[$i] ?? ''));
        };

        $symbol = $get('symbol');
        if ($symbol === '') { continue; }

        $extra = [];
        foreach ($first as $i => $header) {
            if (! in_array($i, $mapped, true)) {
                $extra[trim($header)] = trim((string) ($cells[$i] ?? ''));
            }
        }

        $kind = $get('kind');
        $rows[] = [
            'symbol' => $symbol,
            'kind' => $kind !== '' ? normaliseKind($kind) : inferKind($symbol),
            'location' => $get('location'),
            'staticVerdict' => $get('staticVerdict'),
            'entryPoint' => $get('entryPoint'),
            'prodEvidence' => $get('prodEvidence'),
            'decision' => $get('decision'),
            'reason' => $get('reason'),
            'extra' => $extra,
        ];
    }

    return ['rows' => $rows, 'format' => 'tsv', 'columns' => $columns, 'headers' => array_map('trim', $first)];
}

function normaliseKind(string $kind): string
{
    $k = strtolower(trim($kind));
    $map = [
        'class' => 'class', 'classes' => 'class', 'interface' => 'class', 'trait' => 'class', 'enum' => 'class',
        'method' => 'method', 'function' => 'function', 'fn' => 'function',
        'route' => 'route', 'route name' => 'route', 'uri' => 'route',
        'command' => 'command', 'console' => 'command', 'artisan' => 'command',
        'job' => 'class', 'listener' => 'class', 'event' => 'class', 'notification' => 'class',
        'mcp' => 'tool', 'mcp tool' => 'tool', 'tool' => 'tool',
        'const' => 'constant', 'constant' => 'constant', 'property' => 'property',
        'view' => 'view', 'blade' => 'view', 'component' => 'class',
    ];

    return $map[normaliseHeader($k)] ?? $map[$k] ?? 'symbol';
}

/**
 * Guess what a symbol is from its shape, for callers who hand over a bare list.
 * A wrong guess costs needles, not correctness: every kind's needles are a
 * superset of the literal symbol itself.
 */
function inferKind(string $symbol): string
{
    if (str_contains($symbol, '::')) { return 'method'; }
    if (str_contains($symbol, '\\')) { return 'class'; }
    if (preg_match('/^[a-z0-9_]+(:[a-z0-9_:.-]+)+$/', $symbol)) { return 'command'; }
    if (str_contains($symbol, '/')) { return 'route'; }
    if (str_contains($symbol, '.') && preg_match('/^[a-z0-9._-]+$/', $symbol)) { return 'route'; }
    if (preg_match('/^[A-Z]/', $symbol)) { return 'class'; }

    return 'function';
}

// ---------------------------------------------------------------- needles

// A context string no file can contain, given to a needle that cannot be
// fenced. NUL does not appear in the text files this script reads.
const UNSATISFIABLE_CONTEXT = "\0";

/**
 * One thing to look for, and what finding it would mean.
 *
 * `context` is the discipline that keeps this script honest. A needle like a
 * short class name or a method name matches text that often has nothing to do
 * with the candidate, so such a needle carries a list of strings the file must
 * ALSO contain before the match counts: the fully-qualified name in an import,
 * the namespace it lives in, the owning class. A file that satisfies none of
 * them still produces a hit, but a suppressed one, counted and not reported
 * unless the caller asks for it with --include-no-context.
 */
final class Needle
{
    /** @param array<int, string> $context */
    public function __construct(
        public string $text,
        public string $matchKind,
        public string $confidence,
        public array $context = [],
        public bool $githubSearchable = false,
        public bool $wholeName = false,
    ) {}
}

/** @return array<int, Needle> */
function needlesFor(array $candidate): array
{
    $symbol = $candidate['symbol'];
    $kind = $candidate['kind'];

    return match ($kind) {
        'class', 'constant', 'property' => classNeedles($symbol),
        'method' => methodNeedles($symbol),
        'function' => functionNeedles($symbol),
        'route' => routeNeedles($symbol),
        'command' => commandNeedles($symbol),
        'tool' => toolNeedles($symbol),
        'view' => viewNeedles($symbol),
        default => genericNeedles($symbol),
    };
}

/**
 * A class name, in every form it is written down in.
 *
 * The escaped variants matter more than they look. A queued job's payload, a
 * polymorphic `*_type` column, a cached config and a JSON manifest all carry a
 * class name with its backslashes escaped, and one of those is two levels deep
 * when a payload is itself a JSON string inside JSON.
 */
function classNeedles(string $fqcn): array
{
    $fqcn = ltrim($fqcn, '\\');
    $short = classShortName($fqcn);
    $namespace = str_contains($fqcn, '\\') ? substr($fqcn, 0, strrpos($fqcn, '\\')) : '';

    $needles = [
        new Needle($fqcn, 'fqcn', C_LINKED, [], true),
    ];

    if (str_contains($fqcn, '\\')) {
        $needles[] = new Needle(str_replace('\\', '\\\\', $fqcn), 'fqcn-escaped', C_LINKED, [], true);
        $needles[] = new Needle(str_replace('\\', '\\\\\\\\', $fqcn), 'fqcn-double-escaped', C_LINKED, [], true);
        $needles[] = new Needle(str_replace('\\', '/', $fqcn), 'fqcn-slashed', C_INFERRED);
    }

    // `Short::class` resolves through whatever the file imported, so it is only
    // about this candidate when the file imports this candidate or shares its
    // namespace.
    $context = array_values(array_filter([$fqcn, $namespace === '' ? null : $namespace]));
    $needles[] = new Needle($short . '::class', 'class-const', C_LINKED, $context);
    $needles[] = new Needle($short, 'short-name', C_INFERRED, $context);

    return $needles;
}

function classShortName(string $fqcn): string
{
    $parts = explode('\\', ltrim($fqcn, '\\'));

    return (string) end($parts);
}

/**
 * A method name is the weakest needle this script has, so it is also the most
 * carefully fenced.
 *
 * `handle`, `run`, `boot`, `index`, `store` appear in thousands of unrelated
 * files. Every method needle therefore requires the owning class in context,
 * which is how a `->send(` in a file that has never heard of the candidate's
 * class stays out of the report. A hit is never `linked`: a file can reference
 * two classes that both define `send`, and nothing in a text search can tell
 * which one the call went to.
 */
function methodNeedles(string $symbol): array
{
    [$class, $method] = array_pad(explode('::', $symbol, 2), 2, '');
    $method = ltrim($method, '$');

    if ($method === '') {
        return classNeedles($class);
    }

    if ($class === '') {
        // A method name with no owning class cannot be fenced at all, so these
        // two needles are given a context no file can satisfy. They still get
        // counted, which is the point: the caller sees how much was suppressed
        // and can decide to look with --include-no-context.
        return [
            new Needle('->' . $method . '(', 'method-no-class-context', C_INFERRED, [UNSATISFIABLE_CONTEXT]),
            new Needle('::' . $method . '(', 'method-no-class-context', C_INFERRED, [UNSATISFIABLE_CONTEXT]),
        ];
    }

    $class = ltrim($class, '\\');
    $short = classShortName($class);
    $namespace = str_contains($class, '\\') ? substr($class, 0, strrpos($class, '\\')) : '';
    $context = array_values(array_filter([$class, $short, $namespace === '' ? null : $namespace]));

    return [
        new Needle('->' . $method . '(', 'method-call', C_INFERRED, $context),
        new Needle('::' . $method . '(', 'static-method-call', C_INFERRED, $context),
        new Needle("'" . $method . "'", 'method-as-string', C_INFERRED, $context),
        new Needle('"' . $method . '"', 'method-as-string', C_INFERRED, $context),
        new Needle($short, 'owning-class-short-name', C_INFERRED, array_values(array_filter([$class, $namespace === '' ? null : $namespace]))),
        new Needle($class, 'owning-class-fqcn', C_LINKED, [], true),
    ];
}

function functionNeedles(string $name): array
{
    return [
        new Needle($name . '(', 'function-call', C_INFERRED, [], true),
        new Needle("'" . $name . "'", 'function-as-string', C_INFERRED),
        new Needle('"' . $name . '"', 'function-as-string', C_INFERRED),
    ];
}

/**
 * Route names and URIs. The same route gets written three ways across a fleet:
 * dotted in a `route()` helper, kebab in a URL, snake in a config key.
 */
function routeNeedles(string $name): array
{
    $needles = [new Needle($name, 'route-name', C_LINKED, [], true, true)];

    $dotted = trim($name, '/');
    if (str_contains($dotted, '.')) {
        $needles[] = new Needle(str_replace('.', '-', $dotted), 'route-name-kebab', C_INFERRED, [], false, true);
        $needles[] = new Needle(str_replace('.', '_', $dotted), 'route-name-snake', C_INFERRED, [], false, true);
        $needles[] = new Needle('/' . str_replace('.', '/', $dotted), 'route-uri', C_INFERRED, [], true);
    }

    if (str_contains($name, '/')) {
        $needles[] = new Needle(str_replace('/', '.', trim($name, '/')), 'route-name-dotted', C_INFERRED);
    }

    return $needles;
}

/**
 * A console command signature, which is the single most valuable needle in an
 * ops-knowledge source: a command a person runs by hand once a quarter is
 * unreachable from inside the application and completely alive.
 */
function commandNeedles(string $signature): array
{
    $name = trim(explode(' ', trim($signature))[0]);

    $needles = [
        new Needle($name, 'command-signature', C_LINKED, [], true, true),
        new Needle('artisan ' . $name, 'command-invocation', C_LINKED),
        new Needle('artisan("' . $name . '")', 'command-invocation', C_LINKED),
        new Needle("artisan('" . $name . "')", 'command-invocation', C_LINKED),
    ];

    if (str_contains($name, ':')) {
        $needles[] = new Needle(str_replace(':', '-', $name), 'command-name-kebab', C_INFERRED);
        $needles[] = new Needle(str_replace(':', '_', $name), 'command-name-snake', C_INFERRED);
    }

    return $needles;
}

function toolNeedles(string $name): array
{
    $needles = [new Needle($name, 'tool-name', C_LINKED, [], true, true)];

    if (str_contains($name, '_')) {
        $needles[] = new Needle(str_replace('_', '-', $name), 'tool-name-kebab', C_INFERRED);
    }
    if (str_contains($name, '-')) {
        $needles[] = new Needle(str_replace('-', '_', $name), 'tool-name-snake', C_INFERRED);
    }

    return $needles;
}

function viewNeedles(string $name): array
{
    $dotted = str_replace('/', '.', preg_replace('/\.blade\.php$/', '', $name) ?? $name);

    return [
        new Needle($dotted, 'view-name', C_LINKED, [], true, true),
        new Needle(str_replace('.', '/', $dotted), 'view-path', C_INFERRED),
    ];
}

function genericNeedles(string $symbol): array
{
    return [
        new Needle($symbol, 'literal', C_LINKED, [], true, true),
        new Needle("'" . $symbol . "'", 'literal-string', C_LINKED),
    ];
}

// ---------------------------------------------------------------- the scanner

/**
 * A matched line that is safe to put in JSON.
 *
 * A repository holds files that are not valid UTF-8: a fixture, a vendored
 * binary with a text extension, a file saved in a legacy encoding. One such
 * snippet makes json_encode fail and takes the whole run's output with it, so
 * bytes that cannot be UTF-8 are replaced here, where exactly one line is lost
 * instead of the report.
 */
function utf8Safe(string $text): string
{
    if (preg_match('//u', $text) === 1) {
        return $text;
    }

    return (string) preg_replace('/[\x80-\xFF]/', '?', $text);
}

final class Hit
{
    public function __construct(
        public string $source,
        public string $root,
        public string $path,
        public int $line,
        public string $matchKind,
        public string $confidence,
        public string $snippet,
        public string $needle,
    ) {
        $this->path = utf8Safe($this->path);
        $this->snippet = utf8Safe($this->snippet);
    }

    public function toArray(bool $withSnippet): array
    {
        $out = [
            'source' => $this->source,
            'root' => $this->root,
            'path' => $this->path,
            'line' => $this->line,
            'matchKind' => $this->matchKind,
            'confidence' => $this->confidence,
            'needle' => $this->needle,
        ];
        if ($withSnippet) {
            $out['snippet'] = $this->snippet;
        }

        return $out;
    }
}

/**
 * Finds the files that could hold a reference, then reads only those.
 *
 * Two stages, because the second one is expensive and the first one is cheap.
 * `grep -rlF -f needles` over a tree of a hundred thousand files takes seconds
 * and names the handful that contain any needle at all; the per-line, per-needle
 * work with its context checks then runs over that handful. Without grep the
 * walker does the same job in PHP, correctly and much more slowly.
 */
final class Scanner
{
    // Occurrences of one needle kept from one file. A generated file that names a
    // symbol two hundred times is one piece of evidence, not two hundred.
    private const HITS_PER_NEEDLE_PER_FILE = 5;

    public int $filesWalked = 0;
    public int $filesShortlisted = 0;
    public int $filesRead = 0;
    /** @var array<int, string> */
    public array $notes = [];

    public bool $useGrep;
    public string $prefilter = 'walker';

    public function __construct(private array $opts)
    {
        // Three tools can do the prefilter, and which one is installed changes
        // the cost of a run by more than an order of magnitude. Over a tree of a
        // hundred thousand files, ripgrep and ugrep answer in seconds; the BSD
        // grep that ships with macOS took a minute and a half on the same tree
        // with the same patterns. Prefer whichever fast one is here, and report
        // which one ran, so a slow run has a visible reason.
        if ($opts['grep']) {
            foreach (['rg', 'ugrep', 'grep'] as $tool) {
                if (self::onPath($tool)) {
                    $this->prefilter = $tool;
                    break;
                }
            }
        }

        $this->useGrep = $this->prefilter !== 'walker';

        if ($opts['grep'] && ! $this->useGrep) {
            $this->notes[] = 'no rg, ugrep or grep on PATH; using the built-in walker, which is slower';
        } elseif ($this->prefilter === 'grep') {
            $this->notes[] = 'prefiltering with grep. Over a fleet-sized root that is minutes rather '
                . 'than seconds; installing ripgrep (brew install ripgrep) makes the same run fast';
        }
    }

    private static function onPath(string $tool): bool
    {
        exec('command -v ' . escapeshellarg($tool) . ' 2>/dev/null', $out, $code);

        return $code === 0;
    }

    /**
     * @param array<int, Needle> $needles
     * @param array<int, string> $extensions
     * @return array<int, string> absolute paths worth reading
     */
    public function shortlist(string $root, array $needles, array $extensions, array $exclude): array
    {
        if (! is_dir($root)) {
            if (is_file($root)) {
                return [$root];
            }
            $this->notes[] = "root does not exist: {$root}";

            return [];
        }

        return $this->useGrep
            ? $this->shortlistWithGrep($root, $needles, $extensions, $exclude)
            : $this->shortlistByWalking($root, $needles, $extensions, $exclude);
    }

    private function shortlistWithGrep(string $root, array $needles, array $extensions, array $exclude): array
    {
        $patternFile = tempnam(sys_get_temp_dir(), 'refidx');
        if ($patternFile === false) {
            $this->notes[] = 'could not create a temporary file for the grep prefilter; walking instead';

            return $this->shortlistByWalking($root, $needles, $extensions, $exclude);
        }

        $texts = [];
        foreach ($needles as $n) {
            // grep -f reads one pattern per line, so a needle containing a
            // newline cannot go through it. None of the needle builders make one.
            if (! str_contains($n->text, "\n")) {
                $texts[$n->text] = true;
            }
        }
        file_put_contents($patternFile, implode("\n", array_keys($texts)) . "\n");

        $cmd = $this->prefilterCommand($patternFile, $root, $extensions, $exclude);

        $lines = [];
        exec($cmd, $lines, $code);
        @unlink($patternFile);

        // These tools exit 1 when they matched nothing, which is not an error here.
        if ($code > 1) {
            $this->notes[] = "{$this->prefilter} exited {$code} over {$root}; walking instead";

            return $this->shortlistByWalking($root, $needles, $extensions, $exclude);
        }

        $files = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '' && is_file($line) && ! $this->tooBig($line)) {
                $files[] = $line;
            }
        }
        $this->filesShortlisted += count($files);

        return $files;
    }

    /**
     * The prefilter command for whichever tool is installed.
     *
     * All three read one literal pattern per line from a file and print the
     * paths of files containing any of them. Their flags for "do not guess at
     * encoding", "skip these directories" and "only these extensions" differ.
     * ripgrep and ugrep also honour ignore files by default, which would hide a
     * reference in a committed-but-ignored path, so both are told not to.
     *
     * @param array<int, string> $extensions
     * @param array<int, string> $exclude
     */
    private function prefilterCommand(string $patternFile, string $root, array $extensions, array $exclude): string
    {
        $patterns = escapeshellarg($patternFile);
        $target = escapeshellarg($root);

        if ($this->prefilter === 'rg') {
            // ripgrep skips binary files on its own and --no-messages keeps it
            // from saying so on every one.
            $cmd = 'rg --files-with-matches --fixed-strings --no-messages --no-ignore --hidden '
                . '--file=' . $patterns;
            foreach ($exclude as $dir) {
                $cmd .= ' --glob=' . escapeshellarg('!' . trim((string) $dir, '/') . '/');
            }
            foreach ($extensions as $ext) {
                $cmd .= ' --glob=' . escapeshellarg($ext === '' ? '*' : '*.' . $ext);
            }

            return $cmd . ' -- ' . $target . ' 2>/dev/null';
        }

        // ugrep takes grep's own flags, plus the ignore-file behaviour to turn off.
        $cmd = $this->prefilter === 'ugrep'
            ? 'ugrep -rlIF --no-ignore-files --hidden -f ' . $patterns
            : 'grep -rlIF -f ' . $patterns;

        foreach ($exclude as $dir) {
            $cmd .= ' --exclude-dir=' . escapeshellarg((string) $dir);
        }
        foreach ($extensions as $ext) {
            $cmd .= ' --include=' . escapeshellarg($ext === '' ? '*' : '*.' . $ext);
        }

        return $cmd . ' ' . $target . ' 2>/dev/null';
    }

    private function shortlistByWalking(string $root, array $needles, array $extensions, array $exclude): array
    {
        $wanted = array_fill_keys(array_map('strtolower', $extensions), true);
        $anyExtension = isset($wanted['']) || $extensions === [];
        $texts = array_map(static fn (Needle $n) => $n->text, $needles);

        $files = [];
        $stack = [$root];
        while ($stack !== []) {
            $dir = array_pop($stack);
            $entries = @scandir($dir);
            if ($entries === false) { continue; }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') { continue; }
                $full = $dir . DIRECTORY_SEPARATOR . $entry;

                if (is_link($full)) { continue; }

                if (is_dir($full)) {
                    if (in_array($entry, $exclude, true)) { continue; }
                    $stack[] = $full;
                    continue;
                }

                if (! $anyExtension) {
                    $ext = strtolower((string) pathinfo($full, PATHINFO_EXTENSION));
                    if (! isset($wanted[$ext])) { continue; }
                }
                if ($this->tooBig($full)) { continue; }

                $this->filesWalked++;
                $contents = @file_get_contents($full);
                if ($contents === false) { continue; }

                foreach ($texts as $text) {
                    if (str_contains($contents, $text)) {
                        $files[] = $full;
                        break;
                    }
                }
            }
        }

        $this->filesShortlisted += count($files);

        return $files;
    }

    /**
     * Whether a match sits on name boundaries rather than inside a longer name.
     *
     * The characters a name is built from are letters, digits, and the three
     * separators a name of this kind uses: a dot, a hyphen and an underscore. A
     * colon is deliberately NOT one of them, so `inventory:sync` still matches
     * inside `Schedule::command('inventory:sync')`.
     */
    private static function boundedAt(string $contents, int $offset, int $length): bool
    {
        $before = $offset > 0 ? $contents[$offset - 1] : '';
        $afterAt = $offset + $length;
        $after = $afterAt < strlen($contents) ? $contents[$afterAt] : '';

        return ! self::isNameCharacter($before) && ! self::isNameCharacter($after);
    }

    private static function isNameCharacter(string $character): bool
    {
        return $character !== '' && preg_match('/[A-Za-z0-9._-]/', $character) === 1;
    }

    /**
     * The 1-indexed line holding a byte offset, by bisection over the line-start
     * offsets.
     *
     * @param array<int, int> $lineStarts
     */
    private static function lineAt(array $lineStarts, int $offset): int
    {
        $low = 0;
        $high = count($lineStarts) - 1;
        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);
            if ($lineStarts[$mid] <= $offset) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        return $low + 1;
    }

    private function tooBig(string $path): bool
    {
        $size = @filesize($path);

        return $size !== false && $size > $this->opts['max-file-kb'] * 1024;
    }

    /**
     * Reads one shortlisted file once and turns it into hits for every
     * candidate at the same time.
     *
     * Reading per candidate would mean reading the same file fifteen times. The
     * file is read once, and a context check is memoised across candidates that
     * share a requirement.
     *
     * @param array<int, array<int, Needle>> $needlesByCandidate
     * @return array<int, array{hits: array<int, Hit>, suppressed: int}>
     */
    public function examine(string $source, string $root, string $path, array $needlesByCandidate): array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            return [];
        }
        $this->filesRead++;

        $relative = ltrim(substr($path, strlen(rtrim($root, '/'))), '/');
        if ($relative === '') {
            $relative = basename($path);
        }

        // Byte offset of the start of every line, computed once. Looking a hit's
        // line number up from its offset by bisection costs nothing; scanning
        // every line for every needle instead costs a run that takes minutes
        // when one candidate's name is a common word.
        $lineStarts = [0];
        $at = 0;
        while (($at = strpos($contents, "\n", $at)) !== false) {
            $lineStarts[] = ++$at;
        }

        $satisfied = [];
        $results = [];

        foreach ($needlesByCandidate as $index => $needles) {
            $hits = [];
            $suppressed = 0;

            foreach ($needles as $needle) {
                if (! str_contains($contents, $needle->text)) {
                    continue;
                }

                $inContext = true;
                if ($needle->context !== []) {
                    $key = implode("\x1f", $needle->context);
                    if (! array_key_exists($key, $satisfied)) {
                        $satisfied[$key] = false;
                        foreach ($needle->context as $required) {
                            if ($required !== UNSATISFIABLE_CONTEXT && str_contains($contents, $required)) {
                                $satisfied[$key] = true;
                                break;
                            }
                        }
                    }
                    $inContext = $satisfied[$key];
                }

                if (! $inContext) {
                    $suppressed++;
                    if (! $this->opts['include-no-context']) {
                        continue;
                    }
                }

                $matchKind = $inContext ? $needle->matchKind : $needle->matchKind . '-no-context';
                $confidence = $inContext ? $needle->confidence : C_INFERRED;

                // One hit per LINE, and at most a handful per file. A needle that
                // appears two hundred times in one generated file says nothing
                // the first few occurrences did not.
                $seenLines = [];
                $offset = 0;
                while (count($seenLines) < self::HITS_PER_NEEDLE_PER_FILE
                    && ($offset = strpos($contents, $needle->text, $offset)) !== false) {
                    $found = $offset;
                    $offset += strlen($needle->text);

                    // A dotted route name, a command signature and a tool name
                    // are whole names, and a plain substring search makes one of
                    // them match inside a longer name. `github.callback` found
                    // 43 `linked` hits across a fleet, every one of them another
                    // application's `auth.github.callback`. So these needles
                    // only count when what sits either side of the match is not
                    // part of a name.
                    if ($needle->wholeName && ! self::boundedAt($contents, $found, strlen($needle->text))) {
                        continue;
                    }

                    $line = self::lineAt($lineStarts, $found);
                    if (isset($seenLines[$line])) {
                        continue;
                    }
                    $seenLines[$line] = true;

                    $start = $lineStarts[$line - 1];
                    $end = $lineStarts[$line] ?? strlen($contents);
                    $hits[] = new Hit(
                        $source,
                        $root,
                        $relative,
                        $line,
                        $matchKind,
                        $confidence,
                        trim(substr(trim(substr($contents, $start, $end - $start)), 0, 160)),
                        $needle->text,
                    );
                }
            }

            if ($hits !== [] || $suppressed > 0) {
                $results[$index] = ['hits' => $hits, 'suppressed' => $suppressed];
            }
        }

        return $results;
    }
}

// ---------------------------------------------------------------- config

function readConfig(string $path): array
{
    if (! is_file($path) || ! is_readable($path)) {
        fwrite(STDERR, "cannot read config: {$path}\nRun --print-config for a starter.\n");
        exit(1);
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (! is_array($decoded)) {
        fwrite(STDERR, "config is not valid JSON: {$path}\n");
        exit(1);
    }

    $sources = $decoded['sources'] ?? [];
    if (! is_array($sources) || $sources === []) {
        fwrite(STDERR, "config names no sources. Nothing to search.\n");
        exit(2);
    }

    foreach (array_keys($sources) as $name) {
        if (! in_array($name, SOURCE_KINDS, true)) {
            fwrite(STDERR, "config names an unknown source '{$name}'. Sources are: " . implode(', ', SOURCE_KINDS) . "\n");
            exit(2);
        }
    }

    return [
        'selfRoots' => array_map('rtrim_slash', (array) ($decoded['selfRoots'] ?? [])),
        'sources' => $sources,
    ];
}

function rtrim_slash(string $path): string
{
    return rtrim($path, '/');
}

function sourceEnabled(string $name, array $config, array $opts): bool
{
    if (! isset($config['sources'][$name])) { return false; }
    if ($opts['only'] !== [] && ! in_array($name, $opts['only'], true)) { return false; }
    if (in_array($name, $opts['skip'], true)) { return false; }

    return true;
}

/**
 * A path that looks like another checkout of the application under test,
 * somewhere selfRoots did not name. Returns the warning to show, or null.
 *
 * A directory merely NAMED after the application is not one: a notes tree has a
 * folder per project. What makes it a checkout is a manifest sitting in it, so
 * that is the test, memoised per directory because a shortlist can hold
 * thousands of paths under the same few roots.
 */
function selfLeak(string $path, array $selfRoots): ?string
{
    static $checked = [];

    foreach ($selfRoots as $root) {
        $name = basename($root);
        if ($name === '') {
            continue;
        }

        $at = strpos($path, '/' . $name . '/');
        if ($at === false) {
            continue;
        }

        $candidate = substr($path, 0, $at + strlen($name) + 1);
        if (! array_key_exists($candidate, $checked)) {
            $checked[$candidate] = is_file($candidate . '/composer.json')
                || is_file($candidate . '/artisan')
                || is_dir($candidate . '/.git')
                || is_file($candidate . '/.git');
        }

        if ($checked[$candidate]) {
            return "'{$candidate}' is another checkout of the application under test, and selfRoots "
                . 'does not name it. Its hits are the application quoting itself: add it to selfRoots '
                . 'and run again';
        }
    }

    return null;
}

/** True when this path lives inside the application under test. */
function isSelf(string $path, array $selfRoots): bool
{
    return underAnyPath($path, $selfRoots);
}

/** @param array<int, string> $prefixes */
function underAnyPath(string $path, array $prefixes): bool
{
    foreach ($prefixes as $prefix) {
        if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
            return true;
        }
    }

    return false;
}

// ---------------------------------------------------------------- file sources

/**
 * Runs one filesystem-backed source over every candidate at once.
 *
 * @param array<int, array<int, Needle>> $needlesByCandidate
 * @return array{hits: array<int, array<int, Hit>>, suppressed: array<int, int>}
 */
function runFileSource(
    string $name,
    array $sourceConfig,
    array $needlesByCandidate,
    Scanner $scanner,
    array $selfRoots,
): array {
    $roots = array_map('rtrim_slash', (array) ($sourceConfig['roots'] ?? []));
    $exclude = (array) ($sourceConfig['exclude'] ?? DEFAULT_EXCLUDE);
    // Absolute paths to skip, as opposed to directory names to skip anywhere.
    // The run's OWN output is the first thing to put here: a report written
    // under an ops-knowledge root is read on the next run as evidence, and a
    // candidate then looks alive because the last run said it might be.
    $excludePaths = array_map('rtrim_slash', (array) ($sourceConfig['excludePaths'] ?? []));
    $extensions = (array) ($sourceConfig['extensions'] ?? (DEFAULT_EXTENSIONS[$name] ?? DEFAULT_EXTENSIONS['repos']));

    $union = [];
    foreach ($needlesByCandidate as $needles) {
        foreach ($needles as $needle) { $union[] = $needle; }
    }

    $hits = [];
    $suppressed = [];

    foreach ($roots as $root) {
        $targets = $name === 'deploy'
            ? deployTargets($root, (array) ($sourceConfig['paths'] ?? DEFAULT_DEPLOY_PATHS))
            : [$root];

        foreach ($targets as $target) {
            foreach ($scanner->shortlist($target, $union, $extensions, $exclude) as $file) {
                // References inside the application under test are the static
                // analyser's job, not this script's. Counting them here would
                // report an application as evidence of its own liveness.
                if (isSelf($file, $selfRoots) && $name !== 'deploy' && $name !== 'contracts') {
                    continue;
                }

                if (underAnyPath($file, $excludePaths)) {
                    continue;
                }

                // A checkout of the application under test that selfRoots did
                // not name reads as external evidence about itself, and the hits
                // look perfect: its own route names, its own class names, its
                // own tests. A live run found 21 `linked` hits for one route in
                // a worktree under a hidden `.worktrees` directory. So any path
                // holding a directory named like a selfRoot is flagged.
                if (! isSelf($file, $selfRoots)) {
                    $leak = selfLeak($file, $selfRoots);
                    if ($leak !== null) {
                        $scanner->notes[$leak] = $leak;
                    }
                }

                foreach ($scanner->examine($name, $root, $file, $needlesByCandidate) as $index => $found) {
                    foreach ($found['hits'] as $hit) { $hits[$index][] = $hit; }
                    $suppressed[$index] = ($suppressed[$index] ?? 0) + $found['suppressed'];
                }
            }
        }
    }

    return ['hits' => $hits, 'suppressed' => $suppressed];
}

/**
 * The deploy source reads named paths rather than whole trees, because a CI
 * workflow and a crontab are the only two files in a repository that prove a
 * command still runs on a schedule, and walking the repository to find them
 * would re-scan the application.
 *
 * @param array<int, string> $paths
 * @return array<int, string>
 */
function deployTargets(string $root, array $paths): array
{
    $targets = [];
    foreach ($paths as $relative) {
        $full = $root . '/' . ltrim((string) $relative, '/');
        if (is_dir($full) || is_file($full)) { $targets[] = $full; }
    }

    return $targets;
}

// ---------------------------------------------------------------- github

/**
 * Code search across an organization, through `gh search code`.
 *
 * Two things make this source different from the filesystem ones. It is rate
 * limited hard — GitHub allows roughly ten code searches a minute — and it
 * costs nothing to resume, so a run that hits its budget writes what it has to
 * a state file and the next run picks up the queries it never sent.
 *
 * Only `linked` needles are searched. A short class name or a method name would
 * spend the whole budget returning other people's code.
 */
final class GitHubSearch
{
    public int $queriesMade = 0;
    public int $queriesSkipped = 0;
    public int $pending = 0;
    public int $selfHitsDropped = 0;
    /** @var array<int, string> */
    public array $notes = [];
    private array $state = ['version' => SCHEMA_VERSION, 'queries' => []];

    public function __construct(private array $opts, private array $sourceConfig)
    {
        if ($this->opts['state'] !== null && is_file($this->opts['state'])) {
            $decoded = json_decode((string) file_get_contents($this->opts['state']), true);
            if (is_array($decoded) && isset($decoded['queries']) && is_array($decoded['queries'])) {
                $this->state = $decoded;
            } else {
                $this->notes[] = 'state file could not be read as JSON; starting a fresh index';
            }
        }
    }

    public function available(): bool
    {
        exec('command -v gh 2>/dev/null', $out, $code);
        if ($code !== 0) {
            $this->notes[] = 'gh is not installed, so the github source did not run. Install it with: brew install gh';

            return false;
        }

        exec('gh auth status 2>&1', $authOut, $authCode);
        if ($authCode !== 0) {
            $this->notes[] = 'gh is installed but not authenticated, so the github source did not run. Run: gh auth login';

            return false;
        }

        return true;
    }

    /**
     * @param array<int, array<int, Needle>> $needlesByCandidate
     * @return array{hits: array<int, array<int, Hit>>}
     */
    public function run(array $needlesByCandidate): array
    {
        $org = (string) ($this->sourceConfig['org'] ?? '');
        $repos = array_values(array_filter((array) ($this->sourceConfig['repos'] ?? [])));
        $extra = trim((string) ($this->sourceConfig['extraQualifiers'] ?? ''));

        // The organization holds the repository under test, and code search has
        // no equivalent of selfRoots: without this, the application's own
        // repository is returned as external evidence about itself, and the
        // hits look flawless because they are its own call sites.
        $excluded = [];
        foreach ((array) ($this->sourceConfig['excludeRepos'] ?? []) as $repo) {
            $excluded[strtolower(trim((string) $repo))] = true;
        }
        if ($excluded === []) {
            $this->notes[] = 'the github source names no excludeRepos, so the repository under test is '
                . 'searched alongside the others and will return its own call sites';
        }

        if ($org === '' && $repos === []) {
            $this->notes[] = 'the github source names neither an org nor repos, so it did not run';

            return ['hits' => []];
        }

        $hits = [];

        foreach ($needlesByCandidate as $index => $needles) {
            foreach ($needles as $needle) {
                if ($needle->confidence !== C_LINKED || ! $needle->githubSearchable) {
                    continue;
                }

                $cached = $this->state['queries'][$needle->text] ?? null;
                if ($cached === null) {
                    if ($this->queriesMade >= $this->opts['github-limit']) {
                        $this->pending++;
                        continue;
                    }
                    $cached = $this->query($needle->text, $org, $repos, $extra);
                    $this->state['queries'][$needle->text] = $cached;
                } else {
                    $this->queriesSkipped++;
                }

                foreach ($cached['results'] ?? [] as $result) {
                    $repository = (string) ($result['repository'] ?? $org);
                    $bare = strtolower((string) (strrchr($repository, '/') ?: $repository));
                    if (isset($excluded[strtolower($repository)]) || isset($excluded[ltrim($bare, '/')])) {
                        $this->selfHitsDropped++;
                        continue;
                    }

                    $hits[$index][] = new Hit(
                        'github',
                        $repository,
                        (string) ($result['path'] ?? ''),
                        0,
                        $needle->matchKind,
                        $needle->confidence,
                        (string) ($result['snippet'] ?? ''),
                        $needle->text,
                    );
                }
            }
        }

        $this->save();

        return ['hits' => $hits];
    }

    private function query(string $needle, string $org, array $repos, string $extra): array
    {
        $cmd = 'gh search code ' . escapeshellarg($needle);
        if ($repos !== []) {
            foreach ($repos as $repo) { $cmd .= ' --repo=' . escapeshellarg((string) $repo); }
        } elseif ($org !== '') {
            $cmd .= ' --owner=' . escapeshellarg($org);
        }
        if ($extra !== '') { $cmd .= ' ' . $extra; }
        $cmd .= ' --limit=30 --json repository,path,textMatches 2>&1';

        // GitHub's code search allows about ten queries a minute and answers an
        // eleventh with a 403 rather than a wait. Seven seconds between queries
        // keeps a long run inside that, and a secondary-limit message is waited
        // out rather than retried immediately.
        for ($attempt = 0; $attempt <= 2; $attempt++) {
            if ($this->queriesMade > 0 || $attempt > 0) {
                sleep($attempt === 0 ? 7 : 35);
            }
            $lines = [];
            exec($cmd, $lines, $code);
            $this->queriesMade++;
            $raw = implode("\n", $lines);

            if ($code === 0) {
                $decoded = json_decode($raw, true);
                if (! is_array($decoded)) {
                    $this->notes[] = "gh search code returned no JSON for '{$needle}'";

                    return ['at' => gmdate('c'), 'results' => [], 'error' => 'non-json'];
                }

                $results = [];
                foreach ($decoded as $item) {
                    if (! is_array($item)) { continue; }
                    $snippet = '';
                    foreach ((array) ($item['textMatches'] ?? []) as $match) {
                        if (is_array($match) && isset($match['fragment'])) {
                            $snippet = utf8Safe(trim(substr((string) $match['fragment'], 0, 160)));
                            break;
                        }
                    }
                    $results[] = [
                        'repository' => (string) (($item['repository']['nameWithOwner'] ?? $item['repository']['name'] ?? '')),
                        'path' => (string) ($item['path'] ?? ''),
                        'snippet' => $snippet,
                    ];
                }

                return ['at' => gmdate('c'), 'results' => $results];
            }

            if (stripos($raw, 'rate limit') === false && stripos($raw, '403') === false) {
                $this->notes[] = "gh search code failed for '{$needle}': " . trim(substr($raw, 0, 200));

                return ['at' => gmdate('c'), 'results' => [], 'error' => 'failed'];
            }
        }

        $this->notes[] = "gh search code stayed rate limited for '{$needle}'; it is still pending";
        unset($this->state['queries'][$needle]);
        $this->pending++;

        return ['at' => gmdate('c'), 'results' => []];
    }

    private function save(): void
    {
        if ($this->opts['state'] === null) { return; }

        $json = json_encode(
            $this->state,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        if ($json === false) {
            // Silence here used to cost a whole run's resumability: the file was
            // simply never written and nothing said so.
            $this->notes[] = 'could not encode the state file (' . json_last_error_msg()
                . '); this run is not resumable';

            return;
        }

        if (@file_put_contents($this->opts['state'], $json . "\n") === false) {
            $this->notes[] = 'could not write the state file; this run is not resumable';
        }
    }
}

// ---------------------------------------------------------------- database

/**
 * The database source, which is the one place a class name hides where no
 * amount of code reading will find it.
 *
 * A polymorphic `*_type` column, `notifications.type` and the `displayName`
 * inside a queued job's payload all store a fully-qualified class name as a
 * string. Rename or delete that class and rows that have been sitting there
 * since last year stop resolving.
 *
 * Two rules shape this source. It reads DISTINCT values and nothing else, so no
 * row ever leaves the database: the question is "which class names appear in
 * this column", and the answer is a set of class names. And it does not connect
 * unless the caller hands it a DSN explicitly, because the default is to print
 * the SQL and let a human choose where to run it.
 */
final class DatabaseSource
{
    /** @var array<int, array{label: string, sql: string}> */
    public array $statements = [];
    /** @var array<int, string> */
    public array $notes = [];
    public bool $ran = false;
    /** @var array<string, array<int, string>> */
    public array $distinctValues = [];

    public function __construct(private array $opts, private array $sourceConfig)
    {
        $this->statements = $this->buildStatements();
    }

    /** @return array<int, array{label: string, sql: string}> */
    private function buildStatements(): array
    {
        $driver = strtolower((string) ($this->sourceConfig['driver'] ?? 'mysql'));
        $out = [];

        $plain = array_merge(
            (array) ($this->sourceConfig['morphTypeColumns'] ?? []),
            (array) ($this->sourceConfig['typeColumns'] ?? []),
            (array) ($this->sourceConfig['extraColumns'] ?? []),
        );

        foreach ($plain as $target) {
            $parsed = $this->splitColumn((string) $target);
            if ($parsed === null) { continue; }
            [$table, $column] = $parsed;
            $out[] = [
                'label' => "{$table}.{$column}",
                'sql' => sprintf(
                    'SELECT DISTINCT %s AS value FROM %s WHERE %s IS NOT NULL',
                    $this->quote($column, $driver),
                    $this->quote($table, $driver),
                    $this->quote($column, $driver),
                ),
            ];
        }

        foreach ((array) ($this->sourceConfig['payloadColumns'] ?? []) as $target) {
            $parsed = $this->splitColumn((string) $target);
            if ($parsed === null) { continue; }
            [$table, $column] = $parsed;
            $col = $this->quote($column, $driver);
            $expression = match ($driver) {
                'pgsql', 'postgres', 'postgresql' => "({$col}::jsonb) ->> 'displayName'",
                'sqlite' => "json_extract({$col}, '\$.displayName')",
                default => "JSON_UNQUOTE(JSON_EXTRACT({$col}, '\$.displayName'))",
            };
            $out[] = [
                'label' => "{$table}.{$column} displayName",
                'sql' => sprintf(
                    'SELECT DISTINCT %s AS value FROM %s',
                    $expression,
                    $this->quote($table, $driver),
                ),
            ];
        }

        if ($out === []) {
            $this->notes[] = 'the database source names no columns, so it produced no SQL';
        }

        return $out;
    }

    /** @return array{0: string, 1: string}|null */
    private function splitColumn(string $target): ?array
    {
        $parts = explode('.', trim($target));
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            $this->notes[] = "skipped '{$target}': write a column as table.column";

            return null;
        }
        foreach ($parts as $part) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $part)) {
                $this->notes[] = "skipped '{$target}': table and column names must be plain identifiers";

                return null;
            }
        }

        return [$parts[0], $parts[1]];
    }

    private function quote(string $identifier, string $driver): string
    {
        return match ($driver) {
            'pgsql', 'postgres', 'postgresql' => '"' . $identifier . '"',
            'sqlite' => '"' . $identifier . '"',
            default => '`' . $identifier . '`',
        };
    }

    public function sqlScript(): string
    {
        $lines = [
            '-- Reference evidence from stored class names. Read-only: every',
            '-- statement returns DISTINCT values from one column and no row data.',
            '-- Run these against a replica or a restored snapshot, not production.',
            '',
        ];
        foreach ($this->statements as $statement) {
            $lines[] = '-- ' . $statement['label'];
            $lines[] = $statement['sql'] . ';';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Connects only when handed a DSN, and refuses a remote host unless the
     * caller said so out loud. A DSN that came from a copied .env points at
     * production more often than anyone expects.
     */
    public function execute(): void
    {
        $env = $this->opts['db-dsn-env'];
        if ($env === null) { return; }

        $dsn = getenv($env);
        if ($dsn === false || trim($dsn) === '') {
            $this->notes[] = "environment variable {$env} is empty, so no SQL was run";

            return;
        }

        if (! $this->opts['allow-remote-db'] && ! $this->looksLocal($dsn)) {
            $this->notes[] = "the DSN in {$env} does not name a local host. Nothing was run. "
                . 'Point it at a replica or a restored snapshot, or pass --allow-remote-db if you are sure.';

            return;
        }

        if (! class_exists(\PDO::class)) {
            $this->notes[] = 'PDO is not available in this PHP build, so no SQL was run';

            return;
        }

        $user = $this->opts['db-user-env'] !== null ? (getenv($this->opts['db-user-env']) ?: null) : null;
        $pass = $this->opts['db-pass-env'] !== null ? (getenv($this->opts['db-pass-env']) ?: null) : null;

        try {
            $pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        } catch (\Throwable $e) {
            // Never echo the DSN or the driver's message verbatim: both can
            // carry a username, a host or a password.
            $this->notes[] = 'could not connect with the DSN in ' . $env . ' (' . get_class($e) . ')';

            return;
        }

        $this->ran = true;
        foreach ($this->statements as $statement) {
            if (! str_starts_with(strtoupper(ltrim($statement['sql'])), 'SELECT')) {
                continue;
            }
            try {
                $rows = $pdo->query($statement['sql']);
                $values = [];
                foreach ($rows === false ? [] : $rows as $row) {
                    $value = $row['value'] ?? null;
                    if (is_string($value) && $value !== '') { $values[$value] = true; }
                }
                $this->distinctValues[$statement['label']] = array_keys($values);
            } catch (\Throwable $e) {
                $this->notes[] = $statement['label'] . ' failed (' . get_class($e) . ')';
            }
        }
    }

    private function looksLocal(string $dsn): bool
    {
        if (str_starts_with($dsn, 'sqlite:')) { return true; }

        if (preg_match('/host=([^;]+)/i', $dsn, $m)) {
            $host = strtolower(trim($m[1]));

            return in_array($host, ['127.0.0.1', 'localhost', '::1', '[::1]'], true);
        }

        if (preg_match('/unix_socket=/i', $dsn)) { return true; }

        return false;
    }

    /**
     * A stored class name matches a candidate when it equals one of its linked
     * needles, once the escaping is undone. These hits are `linked`: a row
     * holding a class name is not a coincidence.
     *
     * @param array<int, array<int, Needle>> $needlesByCandidate
     * @return array<int, array<int, Hit>>
     */
    public function hits(array $needlesByCandidate): array
    {
        $hits = [];

        foreach ($this->distinctValues as $label => $values) {
            foreach ($values as $value) {
                $unescaped = str_replace(['\\\\\\\\', '\\\\'], '\\', $value);
                foreach ($needlesByCandidate as $index => $needles) {
                    foreach ($needles as $needle) {
                        if ($needle->confidence !== C_LINKED) { continue; }
                        if ($value === $needle->text || $unescaped === $needle->text) {
                            $hits[$index][] = new Hit(
                                'database',
                                'database',
                                (string) $label,
                                0,
                                'stored-' . $needle->matchKind,
                                C_LINKED,
                                $value,
                                $needle->text,
                            );

                            // One hit per stored value per candidate, then on to
                            // the next candidate: the same class name can be the
                            // owning class of another candidate in the same run.
                            continue 2;
                        }
                    }
                }
            }
        }

        return $hits;
    }
}

// ---------------------------------------------------------------- verdicts

/**
 * What the index can say about one candidate.
 *
 * The ordering is deliberate and only ever goes one way. A contract hit outranks
 * everything because removing a published name breaks somebody else's code
 * whether or not anything calls it today. A linked hit means something outside
 * the application names this symbol. Inferred hits alone mean a human or a model
 * has to look. No hits means no hits: the candidate keeps whatever verdict the
 * static analysis gave it, and this script adds nothing to it.
 *
 * @param array<int, Hit> $hits
 */
function verdictFor(array $hits): string
{
    $linked = false;
    $inferred = false;
    $contractLinked = false;
    $contractInferred = false;

    foreach ($hits as $hit) {
        if ($hit->confidence === C_LINKED) {
            $linked = true;
            if ($hit->source === 'contracts') { $contractLinked = true; }
        } else {
            $inferred = true;
            if ($hit->source === 'contracts') { $contractInferred = true; }
        }
    }

    if ($contractLinked) { return V_CONTRACT; }
    if ($contractInferred) { return V_CONTRACT_MAYBE; }
    if ($linked) { return V_ALIVE; }
    if ($inferred) { return V_REVIEW; }

    return V_NONE;
}

function verdictMeaning(string $verdict): string
{
    return match ($verdict) {
        V_CONTRACT => 'named in a published contract: removal is a breaking change',
        V_CONTRACT_MAYBE => 'something close to this name is in a published contract. Read the hit before deciding',
        V_ALIVE => 'referenced outside the application: not dead',
        V_REVIEW => 'only ambiguous references: a person decides',
        default => 'nothing outside the application referenced it. This is not evidence of death',
    };
}

// ---------------------------------------------------------------- output

function renderReport(array $result, array $opts): string
{
    $out = [];
    $out[] = 'Reference index';
    $out[] = str_repeat('=', 15);
    $out[] = '';
    $out[] = 'candidates    ' . count($result['candidates']) . ' from ' . $result['candidateSource']['path']
        . ' (' . $result['candidateSource']['format'] . ')';
    $out[] = 'sources run   ' . (implode(', ', $result['sourcesRun']) ?: 'none');
    if ($result['sourcesSkipped'] !== []) {
        $out[] = 'not run       ' . implode(', ', $result['sourcesSkipped']);
    }
    $budget = $result['budget'];
    $out[] = 'files read    ' . $budget['filesRead'] . ' of ' . $budget['filesShortlisted'] . ' shortlisted'
        . ($budget['usedGrep']
            ? ' (' . $budget['prefilter'] . ' prefilter)'
            : ' (' . $budget['filesWalked'] . ' walked)');
    if (($budget['githubQueries'] ?? 0) > 0 || ($budget['githubPending'] ?? 0) > 0) {
        $out[] = 'github        ' . $budget['githubQueries'] . ' queries, '
            . $budget['githubCached'] . ' from state, ' . $budget['githubPending'] . ' still pending';
    }
    $out[] = '';

    $byVerdict = [];
    foreach ($result['candidates'] as $candidate) {
        $byVerdict[$candidate['indexVerdict']][] = $candidate;
    }

    foreach ([V_CONTRACT, V_CONTRACT_MAYBE, V_ALIVE, V_REVIEW, V_NONE] as $verdict) {
        $group = $byVerdict[$verdict] ?? [];
        if ($group === []) { continue; }

        $out[] = strtoupper($verdict) . ' (' . count($group) . ') — ' . verdictMeaning($verdict);
        $out[] = str_repeat('-', 72);

        foreach ($group as $candidate) {
            $counts = $candidate['hitCounts'];
            $line = '  ' . $candidate['symbol'] . '  [' . $candidate['kind'] . ']';
            if ($candidate['staticVerdict'] !== '') {
                $line .= '  static: ' . $candidate['staticVerdict'];
            }
            $out[] = $line;
            $out[] = '      ' . $counts['linked'] . ' linked, ' . $counts['inferred'] . ' inferred, '
                . $counts['noContextSuppressed'] . ' suppressed for want of context'
                . ($counts['droppedOverMaxHits'] > 0
                    ? ', ' . $counts['droppedOverMaxHits'] . ' not shown (over --max-hits)'
                    : '');

            $shown = 0;
            foreach ($candidate['hits'] as $hit) {
                if ($shown >= 6) {
                    $out[] = '      … ' . (count($candidate['hits']) - $shown) . ' more';
                    break;
                }
                $where = $hit['path'] . ($hit['line'] > 0 ? ':' . $hit['line'] : '');
                $out[] = sprintf('      %-9s %-26s %s %s', $hit['source'], $hit['matchKind'], $hit['confidence'], $where);
                if ($opts['snippet'] && ($hit['snippet'] ?? '') !== '') {
                    $out[] = '                ' . $hit['snippet'];
                }
                $shown++;
            }
            $out[] = '';
        }
    }

    if (($result['database']['sql'] ?? []) !== []) {
        $out[] = 'DATABASE SOURCE';
        $out[] = str_repeat('-', 72);
        $out[] = $result['database']['ran']
            ? '  ran against the DSN provided, ' . count($result['database']['distinctValues']) . ' column(s) read'
            : '  not run. The SQL below returns DISTINCT values only and no row data.';
        $out[] = '';
        $out[] = $result['database']['sqlScript'];
    }

    if ($result['notes'] !== []) {
        $out[] = 'NOTES';
        $out[] = str_repeat('-', 72);
        foreach ($result['notes'] as $note) { $out[] = '  ' . $note; }
        $out[] = '';
    }

    $out[] = 'Evidence here only moves a candidate from dead toward unknown or alive.';
    $out[] = 'An empty result is an empty result, never a death certificate.';
    $out[] = '';

    return implode("\n", $out);
}

function renderTsv(array $result, array $opts): string
{
    $header = ['symbol', 'kind', 'index_verdict', 'source', 'root', 'path', 'line', 'match_kind', 'confidence', 'needle'];
    if ($opts['snippet']) { $header[] = 'snippet'; }

    $lines = [implode("\t", $header)];

    foreach ($result['candidates'] as $candidate) {
        if ($candidate['hits'] === []) {
            $row = [$candidate['symbol'], $candidate['kind'], $candidate['indexVerdict'], '', '', '', '', '', '', ''];
            if ($opts['snippet']) { $row[] = ''; }
            $lines[] = implode("\t", $row);
            continue;
        }
        foreach ($candidate['hits'] as $hit) {
            $row = [
                $candidate['symbol'], $candidate['kind'], $candidate['indexVerdict'],
                $hit['source'], $hit['root'], $hit['path'], (string) $hit['line'],
                $hit['matchKind'], $hit['confidence'], $hit['needle'],
            ];
            if ($opts['snippet']) { $row[] = str_replace(["\t", "\n"], ' ', (string) ($hit['snippet'] ?? '')); }
            $lines[] = implode("\t", $row);
        }
    }

    return implode("\n", $lines) . "\n";
}

// ---------------------------------------------------------------- main

$config = readConfig($opts['config']);
$candidates = readCandidates($opts['candidates']);

if ($candidates['rows'] === []) {
    fwrite(STDERR, "no candidates were read from {$opts['candidates']}\n");
    exit(1);
}

$needlesByCandidate = [];
foreach ($candidates['rows'] as $index => $candidate) {
    $needlesByCandidate[$index] = needlesFor($candidate);
}

// A needle of a few characters with no context requirement cannot be fenced,
// which makes it both the slowest thing this script can search for and the
// least conclusive. Say so before the run rather than after it.
$shortNeedleNotes = [];
foreach ($needlesByCandidate as $index => $needles) {
    foreach ($needles as $needle) {
        if ($needle->context === [] && strlen($needle->text) < 8 && ! str_contains($needle->text, ':')) {
            $shortNeedleNotes["'{$needle->text}' is only " . strlen($needle->text)
                . ' characters and cannot be fenced by context: expect false positives, a large hit'
                . ' count and a slow run for ' . $candidates['rows'][$index]['symbol']] = true;
        }
    }
}

$scanner = new Scanner($opts);
$hitsByCandidate = [];
$suppressedByCandidate = [];
$notes = [];
$sourcesRun = [];
$sourcesSkipped = [];
$githubStats = ['queries' => 0, 'cached' => 0, 'pending' => 0, 'selfHitsDropped' => 0];
$database = ['sql' => [], 'sqlScript' => '', 'ran' => false, 'distinctValues' => []];

foreach (SOURCE_KINDS as $name) {
    if (! sourceEnabled($name, $config, $opts)) {
        if (isset($config['sources'][$name])) { $sourcesSkipped[] = $name; }
        continue;
    }

    $sourceConfig = (array) $config['sources'][$name];

    if ($name === 'github') {
        $search = new GitHubSearch($opts, $sourceConfig);
        if (! $search->available()) {
            $notes = array_merge($notes, $search->notes);
            $sourcesSkipped[] = $name;
            continue;
        }
        $found = $search->run($needlesByCandidate);
        foreach ($found['hits'] as $index => $hits) {
            foreach ($hits as $hit) { $hitsByCandidate[$index][] = $hit; }
        }
        $githubStats = [
            'queries' => $search->queriesMade,
            'cached' => $search->queriesSkipped,
            'pending' => $search->pending,
            'selfHitsDropped' => $search->selfHitsDropped,
        ];
        $notes = array_merge($notes, $search->notes);
        $sourcesRun[] = $name;
        continue;
    }

    if ($name === 'database') {
        $db = new DatabaseSource($opts, $sourceConfig);
        $db->execute();
        foreach ($db->hits($needlesByCandidate) as $index => $hits) {
            foreach ($hits as $hit) { $hitsByCandidate[$index][] = $hit; }
        }
        $database = [
            'sql' => $db->statements,
            'sqlScript' => $db->sqlScript(),
            'ran' => $db->ran,
            'distinctValues' => $db->distinctValues,
        ];
        $notes = array_merge($notes, $db->notes);
        $sourcesRun[] = $name;
        continue;
    }

    $found = runFileSource($name, $sourceConfig, $needlesByCandidate, $scanner, $config['selfRoots']);
    foreach ($found['hits'] as $index => $hits) {
        foreach ($hits as $hit) { $hitsByCandidate[$index][] = $hit; }
    }
    foreach ($found['suppressed'] as $index => $count) {
        $suppressedByCandidate[$index] = ($suppressedByCandidate[$index] ?? 0) + $count;
    }
    $sourcesRun[] = $name;
}

$notes = array_merge($notes, array_values($scanner->notes), array_keys($shortNeedleNotes));

$rendered = [];
foreach ($candidates['rows'] as $index => $candidate) {
    $hits = $hitsByCandidate[$index] ?? [];

    usort($hits, static function (Hit $a, Hit $b): int {
        return [$a->confidence === C_LINKED ? 0 : 1, $a->source, $a->path, $a->line]
           <=> [$b->confidence === C_LINKED ? 0 : 1, $b->source, $b->path, $b->line];
    });

    $linked = 0;
    $inferred = 0;
    foreach ($hits as $hit) {
        $hit->confidence === C_LINKED ? $linked++ : $inferred++;
    }

    // Truncate AFTER counting, so the counts stay honest and the report says
    // how many hits it is not showing. Linked hits sort first, so what survives
    // is the evidence worth reading.
    $dropped = 0;
    if (count($hits) > $opts['max-hits']) {
        $dropped = count($hits) - $opts['max-hits'];
        $hits = array_slice($hits, 0, $opts['max-hits']);
    }

    $rendered[] = [
        'symbol' => $candidate['symbol'],
        'kind' => $candidate['kind'],
        'location' => $candidate['location'],
        'staticVerdict' => $candidate['staticVerdict'],
        'entryPointEvidence' => $candidate['entryPoint'],
        'prodEvidence' => $candidate['prodEvidence'],
        'priorDecision' => $candidate['decision'],
        'reason' => $candidate['reason'],
        'extra' => $candidate['extra'],
        'indexVerdict' => verdictFor($hits),
        'hitCounts' => [
            'linked' => $linked,
            'inferred' => $inferred,
            'noContextSuppressed' => $suppressedByCandidate[$index] ?? 0,
            'droppedOverMaxHits' => $dropped,
        ],
        'needles' => array_map(
            static fn (Needle $n) => ['text' => $n->text, 'matchKind' => $n->matchKind, 'confidence' => $n->confidence],
            $needlesByCandidate[$index],
        ),
        'hits' => array_map(static fn (Hit $h) => $h->toArray($opts['snippet']), $hits),
    ];
}

$result = [
    'schemaVersion' => SCHEMA_VERSION,
    'generatedAt' => gmdate('c'),
    'candidateSource' => [
        'path' => $opts['candidates'],
        'format' => $candidates['format'],
        'columns' => $candidates['columns'],
        'headers' => $candidates['headers'],
        'rows' => count($candidates['rows']),
    ],
    'sourcesRun' => $sourcesRun,
    'sourcesSkipped' => $sourcesSkipped,
    'includeNoContext' => $opts['include-no-context'],
    'budget' => [
        'filesWalked' => $scanner->filesWalked,
        'filesShortlisted' => $scanner->filesShortlisted,
        'filesRead' => $scanner->filesRead,
        'usedGrep' => $scanner->useGrep,
        'prefilter' => $scanner->prefilter,
        'githubQueries' => $githubStats['queries'],
        'githubCached' => $githubStats['cached'],
        'githubPending' => $githubStats['pending'],
        'githubSelfHitsDropped' => $githubStats['selfHitsDropped'],
    ],
    'candidates' => $rendered,
    'database' => $database,
    'notes' => $notes,
];

if ($opts['json']) {
    $json = json_encode(
        $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
    );
    if ($json === false) {
        fwrite(STDERR, 'could not encode the result as JSON: ' . json_last_error_msg() . "\n");
        exit(1);
    }
    fwrite(STDOUT, $json . "\n");
    exit(0);
}

fwrite(STDOUT, $opts['tsv'] ? renderTsv($result, $opts) : renderReport($result, $opts));
exit(0);
