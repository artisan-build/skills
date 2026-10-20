#!/usr/bin/env php
<?php

/**
 * triage-questions.php
 *
 * Turns a reference index into the smallest set of questions a person has to
 * answer, grouped so that one answer settles a whole class of candidate.
 *
 * The evidence a dead-code hunt produces splits three ways. Some candidates are
 * plainly alive: something outside the application names them. Some are plainly
 * ambiguous for a reason a person can resolve in one sentence — "yes, that
 * webhook is how our payment provider talks to us", "no, nobody has run that
 * command since we moved off Forge". The rest are not questions at all, because
 * nothing about them is in doubt yet.
 *
 * Asking fifteen questions one candidate at a time wastes the only expensive
 * thing in this loop, which is the person's attention. So this script sorts
 * candidates into categories, each with ONE question, and leaves the phrasing
 * and the judgment to whoever is running it.
 *
 * It never decides that anything is dead, and the answer vocabularies it offers
 * contain no such value. A human saying "we do not need that any more" is
 * recorded as a human saying so, which is a different kind of fact from a static
 * analyser's verdict, and the ledger keeps them apart.
 *
 * Reads only. Exit codes: 0 success, 1 failure, 2 misuse.
 */

declare(strict_types=1);

const SCHEMA_VERSION = 1;

/**
 * The categories, their question, and the answers that settle them.
 *
 * No vocabulary contains `dead`. The closest any of them gets is a person
 * stating a fact about their own business — that a thing was never published,
 * was abandoned, or is no longer run — and that is recorded as their statement.
 */
const CATEGORIES = [
    'contract-or-package-api' => [
        'question' => 'These names are published outside this codebase: in a package\'s public API, an OpenAPI document, a tool manifest or a docs page. Removing any of them is a breaking change for somebody who is not in this repository. Is each one still part of what you publish?',
        'why' => 'A published name has consumers you cannot enumerate from here. Removal needs a major version or a deprecation window, not a tombstone.',
        'answers' => ['keep-published', 'deprecate-first', 'never-published', 'unknown'],
    ],
    'route-never-hit' => [
        'question' => 'Nothing in your own code calls these routes. That is what a webhook endpoint looks like, and also what an integration nobody told you about looks like. For each one: does an external client post to it?',
        'why' => 'A route reached only from outside is unreachable from every static entry point and completely alive. Telemetry on a quiet application cannot tell the two apart.',
        'answers' => ['external-consumer', 'internal-only', 'unknown'],
    ],
    'rare-but-critical' => [
        'question' => 'These names sit in the parts of a business that run on a cycle longer than any observation window: annual billing, disputes and chargebacks, tax periods, account deletion, disaster recovery, data export. For each one: does it run on a cycle you have not observed yet?',
        'why' => 'Thirty days of telemetry says nothing about an annual process, and the code that only runs during an incident is the code you least want tombstoned.',
        'answers' => ['needed-in-cycle', 'superseded', 'unknown'],
    ],
    'not-yet-used' => [
        'question' => 'These were added recently, or sit behind a feature flag, or are named in a roadmap document. Code that is not used yet looks exactly like code that is not used any more. For each one: is this waiting to be switched on?',
        'why' => 'A static analyser cannot tell the future from the past. Git history and a flag name can.',
        'answers' => ['planned-keep', 'abandoned', 'unknown'],
    ],
    'manual-operation' => [
        'question' => 'These are commands and jobs that something outside the application reaches: a runbook, a CI workflow, a crontab, another application, or a person at a terminal. For each one: is it still run that way?',
        'why' => 'A command run by hand once a quarter is unreachable from inside the application and alive. A runbook that mentions it proves it was run, not that it still is.',
        'answers' => ['still-run', 'no-longer-run', 'unknown'],
    ],
    'ambiguous-evidence' => [
        'question' => 'The only references found for these are ambiguous: a short class name, a method name, a route spelled differently. Each hit is quoted below. For each one: is that hit really about this symbol?',
        'why' => 'A text search cannot tell one `handle` from another. This is where the index\'s false positives live, and a person reading one line settles it.',
        'answers' => ['is-referenced', 'not-this-symbol', 'unknown'],
    ],
];

// Words that mark a part of a business whose cycle is longer than any
// observation window. Overridable in the config with "criticalVocabulary".
const DEFAULT_CRITICAL_VOCABULARY = [
    'annual', 'yearly', 'renewal', 'renew', 'invoice', 'billing', 'dunning',
    'chargeback', 'dispute', 'refund', 'credit', 'proration', 'tax', 'vat',
    'w9', '1099', 'yearend', 'year-end', 'quarter', 'statement', 'payout',
    'backup', 'restore', 'recovery', 'failover', 'disaster', 'rollback',
    'migrate', 'migration', 'export', 'gdpr', 'ccpa', 'erasure', 'deletion',
    'delete-account', 'close-account', 'offboard', 'retention', 'purge',
    'audit', 'compliance', 'incident', 'escalation', 'reconcile', 'settlement',
];

// Patterns that mean a symbol's reachability is gated on a flag rather than on
// a call site.
const FLAG_PATTERNS = [
    'Feature::active', 'Feature::inactive', 'Feature::for', 'Feature::when',
    'Pennant', '@feature', 'feature_flag', 'featureFlag',
    "config('features.", 'config("features.',
];

function usage(): string
{
    return <<<TXT
    Usage: php triage-questions.php --index=FILE [options]

      --index=FILE        the reference index JSON, from the dead-code-reference-index
                          skill (`reference-index.php --json`). Required: the
                          questions are shaped by what the index did and did not find.
      --candidates=FILE   a candidates TSV or plain symbol list, for candidates the
                          index run did not cover. Anything already in the index is
                          taken from there.
      --config=FILE       JSON overriding the critical-path vocabulary and the
                          package roots. See CATEGORIES.md.
      --repo=PATH         a git checkout to read history from. Without it, the
                          not-yet-used category rests on flags and roadmap text alone.
      --app-root=PATH     the application's root, used to tell a package's public API
                          from application code  (default: --repo, else the cwd)
      --package-root=PATH a directory whose contents are a package's public API.
                          Repeatable. Without it, packages are detected by looking for
                          the nearest composer.json above each candidate.
      --roadmap=PATH      a file or directory of planning documents. A candidate named
                          in one is probably not-yet-used. Repeatable.
      --recent-days=N     how new is new enough to ask whether it is not-yet-used
                          rather than no-longer-used                  (default: 90)
      --ledger-template   emit unanswered ledger rows as TSV instead of the questions
      --json              emit one JSON object on stdout and nothing else
      -h, --help          this text

    Decides nothing. Groups the evidence that cannot decide into questions, and
    never offers `dead` as an answer to any of them.

    TXT;
}

$opts = [
    'index' => null,
    'candidates' => null,
    'config' => null,
    'repo' => null,
    'app-root' => null,
    'package-root' => [],
    'roadmap' => [],
    'recent-days' => 90,
    'ledger-template' => false,
    'json' => false,
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '-h' || $arg === '--help') { fwrite(STDOUT, usage()); exit(0); }
    if ($arg === '--json')             { $opts['json'] = true; continue; }
    if ($arg === '--ledger-template')  { $opts['ledger-template'] = true; continue; }
    if (preg_match('/^--(index|candidates|config|repo|app-root)=(.+)$/', $arg, $m)) {
        $opts[$m[1]] = $m[2];
        continue;
    }
    if (preg_match('/^--(package-root|roadmap)=(.+)$/', $arg, $m)) {
        $opts[$m[1]][] = rtrim($m[2], '/');
        continue;
    }
    if (preg_match('/^--recent-days=([0-9]+)$/', $arg, $m)) {
        $opts['recent-days'] = (int) $m[1];
        continue;
    }
    fwrite(STDERR, "unknown option: {$arg}\n\n" . usage());
    exit(2);
}

if ($opts['index'] === null) {
    fwrite(STDERR, "--index=FILE is required\n\n" . usage());
    exit(2);
}
if ($opts['json'] && $opts['ledger-template']) {
    fwrite(STDERR, "--json and --ledger-template are mutually exclusive\n");
    exit(2);
}

// ---------------------------------------------------------------- inputs

function readIndex(string $path): array
{
    if (! is_file($path) || ! is_readable($path)) {
        fwrite(STDERR, "cannot read the reference index: {$path}\n");
        exit(1);
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (! is_array($decoded) || ! isset($decoded['candidates']) || ! is_array($decoded['candidates'])) {
        fwrite(STDERR, "that file is not a reference index. Produce one with: reference-index.php --json\n");
        exit(1);
    }

    return $decoded;
}

/**
 * The same header aliasing the reference index uses, so a candidates file works
 * with either script and a renamed column breaks neither.
 */
const COLUMN_ALIASES = [
    'symbol' => ['symbol', 'candidate', 'name', 'fqcn', 'identifier'],
    'location' => ['file line', 'location', 'file', 'where', 'path', 'site'],
    'kind' => ['kind', 'type', 'symbol kind', 'category'],
    'staticVerdict' => ['static verdict', 'verdict', 'static', 'static analysis', 'static result'],
];

function normaliseHeader(string $raw): string
{
    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower(trim($raw))));
}

/** @return array<int, array<string, string>> */
function readExtraCandidates(?string $path): array
{
    if ($path === null) { return []; }
    if (! is_file($path) || ! is_readable($path)) {
        fwrite(STDERR, "cannot read candidates file: {$path}\n");
        exit(1);
    }

    $lines = array_values(array_filter(
        preg_split('/\r\n|\r|\n/', (string) file_get_contents($path)) ?: [],
        static fn ($l) => trim($l) !== '' && ! str_starts_with(ltrim($l), '#'),
    ));
    if ($lines === []) { return []; }

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

    $isHeader = isset($mapped['symbol'])
        && (count($mapped) >= 2 || in_array(normaliseHeader($first[0] ?? ''), ['symbol', 'candidate'], true));

    $rows = [];
    foreach (array_slice($lines, $isHeader ? 1 : 0) as $line) {
        $cells = explode("\t", $line);
        if ($isHeader) {
            $get = static fn (string $f): string => isset($mapped[$f]) ? trim((string) ($cells[$mapped[$f]] ?? '')) : '';
            $row = [
                'symbol' => $get('symbol'),
                'kind' => $get('kind'),
                'location' => $get('location'),
                'staticVerdict' => $get('staticVerdict'),
            ];
        } else {
            $row = [
                'symbol' => trim($cells[0] ?? ''),
                'kind' => trim($cells[1] ?? ''),
                'location' => '',
                'staticVerdict' => '',
            ];
        }
        if ($row['symbol'] !== '') { $rows[] = $row; }
    }

    return $rows;
}

function readConfig(?string $path): array
{
    $defaults = [
        'criticalVocabulary' => DEFAULT_CRITICAL_VOCABULARY,
        'packageRoots' => [],
    ];

    if ($path === null) { return $defaults; }
    if (! is_file($path)) {
        fwrite(STDERR, "cannot read config: {$path}\n");
        exit(1);
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (! is_array($decoded)) {
        fwrite(STDERR, "config is not valid JSON: {$path}\n");
        exit(1);
    }

    return [
        'criticalVocabulary' => array_values(array_filter(array_map(
            'strval',
            (array) ($decoded['criticalVocabulary'] ?? $defaults['criticalVocabulary']),
        ))),
        'packageRoots' => array_map(static fn ($p) => rtrim((string) $p, '/'), (array) ($decoded['packageRoots'] ?? [])),
    ];
}

// ---------------------------------------------------------------- evidence

/** The file part of a `file:line` location, or '' when there is none. */
function locationFile(string $location): string
{
    if ($location === '') { return ''; }
    $parts = explode(':', $location);
    if (count($parts) > 1 && preg_match('/^[0-9]+$/', (string) end($parts))) {
        array_pop($parts);
    }

    return implode(':', $parts);
}

function shortName(string $symbol): string
{
    $symbol = explode('::', $symbol)[0];
    $parts = explode('\\', ltrim($symbol, '\\'));

    return (string) end($parts);
}

/**
 * Git history for one candidate: when its file first appeared, and when its own
 * name first appeared in that file.
 *
 * The second is the one that matters and the one a reviewer forgets to look at.
 * A method added last week inside a file from 2019 reads as old by file date and
 * is a week old in fact.
 */
function gitHistory(?string $repo, string $file, string $symbol): array
{
    if ($repo === null || $file === '' || ! is_dir($repo . '/.git')) {
        return ['available' => false];
    }

    $cd = 'git -C ' . escapeshellarg($repo) . ' ';

    $fileAdded = gitLine($cd . 'log -1 --diff-filter=A --format=%H%x09%aI%x09%s -- ' . escapeshellarg($file));

    // -S finds the commits that changed how often the string occurs; the oldest
    // of those is the one that introduced it.
    $out = [];
    exec($cd . 'log --format=%H%x09%aI%x09%s -S' . escapeshellarg(shortName($symbol))
        . ' -- ' . escapeshellarg($file) . ' 2>/dev/null', $out);
    $introduced = $out === [] ? null : parseGitLine((string) end($out));

    $newest = $introduced ?? $fileAdded;

    return [
        'available' => true,
        'fileAdded' => $fileAdded,
        'symbolIntroduced' => $introduced,
        'ageDays' => $newest === null ? null : (int) floor((time() - strtotime($newest['date'])) / 86400),
    ];
}

function gitLine(string $cmd): ?array
{
    $out = [];
    exec($cmd . ' 2>/dev/null', $out);

    return $out === [] ? null : parseGitLine((string) $out[0]);
}

function parseGitLine(string $line): ?array
{
    $parts = explode("\t", $line);
    if (count($parts) < 2) { return null; }

    return ['commit' => substr($parts[0], 0, 12), 'date' => $parts[1], 'subject' => $parts[2] ?? ''];
}

/** Flag patterns in the candidate's own file. */
function flagEvidence(?string $appRoot, string $file): array
{
    if ($appRoot === null || $file === '') { return []; }

    $full = str_starts_with($file, '/') ? $file : $appRoot . '/' . $file;
    if (! is_file($full) || filesize($full) > 512 * 1024) { return []; }

    $contents = (string) file_get_contents($full);
    $found = [];
    foreach (FLAG_PATTERNS as $pattern) {
        if (str_contains($contents, $pattern)) { $found[] = $pattern; }
    }

    return $found;
}

/**
 * A candidate named in a planning document. Searched on the short name, because
 * a roadmap entry says "ship the widget reconciler", not the FQCN.
 *
 * @param array<int, string> $roots
 */
function roadmapEvidence(array $roots, string $symbol): array
{
    $needle = shortName($symbol);
    if ($needle === '' || strlen($needle) < 4) { return []; }

    $hits = [];
    foreach ($roots as $root) {
        $files = is_dir($root) ? listFiles($root) : (is_file($root) ? [$root] : []);
        foreach ($files as $file) {
            $contents = @file_get_contents($file);
            if ($contents === false) { continue; }
            if (str_contains($contents, $needle)) {
                $hits[] = ltrim(substr($file, strlen($root)), '/') ?: basename($file);
            }
        }
    }

    return array_slice(array_unique($hits), 0, 10);
}

/** @return array<int, string> */
function listFiles(string $root): array
{
    $files = [];
    $stack = [$root];
    while ($stack !== []) {
        $dir = array_pop($stack);
        foreach ((array) @scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.git') { continue; }
            $full = $dir . '/' . $entry;
            if (is_link($full)) { continue; }
            if (is_dir($full)) { $stack[] = $full; continue; }
            $ext = strtolower((string) pathinfo($full, PATHINFO_EXTENSION));
            if (in_array($ext, ['md', 'txt', 'rst', 'org', 'yml', 'yaml', 'json'], true)
                && (@filesize($full) ?: 0) <= 512 * 1024) {
                $files[] = $full;
            }
        }
    }

    return $files;
}

/**
 * Whether this candidate is part of a package's public API rather than
 * application code.
 *
 * A declared package root settles it. Otherwise the nearest composer.json above
 * the file decides: one that is not the application's own means the file belongs
 * to something installed, published, or split out, and deleting from it is
 * somebody else's breaking change.
 *
 * @param array<int, string> $packageRoots
 */
function packageEvidence(array $packageRoots, ?string $appRoot, string $file): ?string
{
    if ($file === '') { return null; }

    $full = str_starts_with($file, '/') ? $file : rtrim((string) $appRoot, '/') . '/' . $file;

    foreach ($packageRoots as $root) {
        if ($root !== '' && str_starts_with($full, $root . '/')) {
            return $root;
        }
    }

    if ($appRoot === null) { return null; }

    $appManifest = rtrim($appRoot, '/') . '/composer.json';
    $dir = dirname($full);
    $guard = 0;
    while ($guard++ < 40 && $dir !== '/' && $dir !== '' && str_starts_with($dir, rtrim($appRoot, '/'))) {
        $manifest = $dir . '/composer.json';
        if (is_file($manifest) && realpath($manifest) !== realpath($appManifest)) {
            return ltrim(substr($dir, strlen(rtrim($appRoot, '/'))), '/') ?: $dir;
        }
        $dir = dirname($dir);
    }

    return null;
}

/** @param array<int, string> $vocabulary */
function criticalWords(array $vocabulary, string $symbol, string $location): array
{
    $haystack = strtolower($symbol . ' ' . $location);
    // Split the haystack on anything that is not a letter or digit so `PurgeJob`
    // and `purge_job` both offer the word `purge`, and a word only matches a
    // word. Substring matching here puts `taxonomy` in the tax category.
    $words = array_filter(preg_split('/[^a-z0-9]+/', (string) preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $symbol . ' ' . $location)) ?: []);
    $words = array_map('strtolower', $words);

    $found = [];
    foreach ($vocabulary as $term) {
        $term = strtolower($term);
        if ($term === '') { continue; }
        if (in_array($term, $words, true)) { $found[] = $term; continue; }
        // A hyphenated term such as `delete-account` cannot survive the split,
        // so it is matched against the whole haystack instead.
        if (str_contains($term, '-') && str_contains($haystack, $term)) { $found[] = $term; }
    }

    return array_values(array_unique($found));
}

// ---------------------------------------------------------------- categorise

/**
 * Which categories this candidate belongs to, and why.
 *
 * A candidate can land in several: a billing command named in a runbook is both
 * rare-but-critical and a manual operation, and both questions are worth asking
 * because they have different answers. A candidate in none of them is settled
 * and is left alone.
 */
function categorise(array $candidate, array $evidence, array $config, int $recentDays): array
{
    $assigned = [];
    $verdict = (string) ($candidate['indexVerdict'] ?? 'no-external-evidence');
    $kind = (string) ($candidate['kind'] ?? 'symbol');
    $hits = (array) ($candidate['hits'] ?? []);
    $counts = (array) ($candidate['hitCounts'] ?? []);

    $sources = [];
    $linkedSources = [];
    foreach ($hits as $hit) {
        $sources[(string) ($hit['source'] ?? '')] = true;
        if (($hit['confidence'] ?? '') === 'linked') {
            $linkedSources[(string) ($hit['source'] ?? '')] = true;
        }
    }

    if (in_array($verdict, ['contract', 'contract-possible'], true)) {
        $assigned['contract-or-package-api'][] = 'the index found it in a published contract';
    }
    if ($evidence['package'] !== null) {
        $assigned['contract-or-package-api'][] = 'it lives in ' . $evidence['package'] . ', which publishes its own API';
    }

    if ($kind === 'route' && ! isset($linkedSources['repos']) && ! isset($linkedSources['github'])) {
        $assigned['route-never-hit'][] = 'no code in any searched repository names this route';
    }

    if ($evidence['criticalWords'] !== []) {
        $assigned['rare-but-critical'][] = 'its name carries ' . implode(', ', $evidence['criticalWords']);
    }

    $age = $evidence['git']['ageDays'] ?? null;
    if ($age !== null && $age <= $recentDays) {
        $assigned['not-yet-used'][] = "introduced {$age} days ago";
    }
    if ($evidence['flags'] !== []) {
        $assigned['not-yet-used'][] = 'its file uses ' . implode(', ', $evidence['flags']);
    }
    if ($evidence['roadmap'] !== []) {
        $assigned['not-yet-used'][] = 'named in ' . implode(', ', $evidence['roadmap']);
    }

    if (in_array($kind, ['command', 'job'], true)) {
        $assigned['manual-operation'][] = "it is a {$kind}, which a person or a scheduler can reach directly";
    }
    if (isset($sources['ops'])) {
        $assigned['manual-operation'][] = 'an ops-knowledge document mentions it';
    }
    if (isset($sources['deploy'])) {
        $assigned['manual-operation'][] = 'a deploy or CI configuration mentions it';
    }

    $linked = (int) ($counts['linked'] ?? 0);
    $inferred = (int) ($counts['inferred'] ?? 0);
    $suppressed = (int) ($counts['noContextSuppressed'] ?? 0);
    if ($linked === 0 && ($inferred > 0 || $suppressed > 0)) {
        $assigned['ambiguous-evidence'][] = $inferred . ' inferred hit(s), ' . $suppressed . ' suppressed for want of context';
    }

    return $assigned;
}

/**
 * A candidate nothing needs to be asked about.
 *
 * Code or data outside the application names it, by a form of its name that
 * cannot be anything else. That is the end of the question — but only when the
 * evidence came from code or data. A runbook or a CI file proves it WAS reached
 * that way, which is a different claim, so those stay in manual-operation.
 */
function isSettled(array $candidate, array $categories): bool
{
    if ($categories !== []) { return false; }

    foreach ((array) ($candidate['hits'] ?? []) as $hit) {
        if (($hit['confidence'] ?? '') === 'linked'
            && in_array($hit['source'] ?? '', ['repos', 'github', 'database'], true)) {
            return true;
        }
    }

    return false;
}

// ---------------------------------------------------------------- questions

function questionId(string $category): string
{
    return 'q-' . $category;
}

function buildGroups(array $candidates, array $opts, array $config): array
{
    $appRoot = $opts['app-root'] ?? $opts['repo'] ?? getcwd();
    if ($appRoot === false) { $appRoot = null; }

    $groups = [];
    $settled = [];
    $unasked = [];

    foreach ($candidates as $candidate) {
        $symbol = (string) $candidate['symbol'];
        $location = (string) ($candidate['location'] ?? '');
        $file = locationFile($location);

        $evidence = [
            'git' => gitHistory($opts['repo'], $file, $symbol),
            'flags' => flagEvidence($appRoot, $file),
            'roadmap' => roadmapEvidence($opts['roadmap'], $symbol),
            'package' => packageEvidence(
                array_merge($opts['package-root'], $config['packageRoots']),
                $appRoot,
                $file,
            ),
            'criticalWords' => criticalWords($config['criticalVocabulary'], $symbol, $location),
        ];

        $categories = categorise($candidate, $evidence, $config, $opts['recent-days']);

        if ($categories === []) {
            if (isSettled($candidate, $categories)) {
                $settled[] = [
                    'symbol' => $symbol,
                    'indexVerdict' => $candidate['indexVerdict'] ?? '',
                    'why' => 'referenced outside the application by a name that cannot be anything else',
                ];
            } else {
                $unasked[] = [
                    'symbol' => $symbol,
                    'indexVerdict' => $candidate['indexVerdict'] ?? '',
                    'why' => 'no evidence either way, and no category fits. Nothing here is a question for a person',
                ];
            }
            continue;
        }

        foreach ($categories as $category => $reasons) {
            $groups[$category]['candidates'][] = [
                'symbol' => $symbol,
                'kind' => $candidate['kind'] ?? '',
                'location' => $location,
                'staticVerdict' => $candidate['staticVerdict'] ?? '',
                'indexVerdict' => $candidate['indexVerdict'] ?? '',
                'reasons' => $reasons,
                'evidence' => evidenceSummary($candidate, $evidence, $category),
            ];
        }
    }

    $ordered = [];
    foreach (CATEGORIES as $category => $meta) {
        if (! isset($groups[$category])) { continue; }
        $ordered[] = [
            'id' => questionId($category),
            'category' => $category,
            'question' => $meta['question'],
            'why' => $meta['why'],
            'answers' => $meta['answers'],
            'candidates' => $groups[$category]['candidates'],
        ];
    }

    return ['groups' => $ordered, 'settled' => $settled, 'unasked' => $unasked];
}

/**
 * The evidence lines a person needs in order to answer, and nothing else.
 *
 * Each category is answerable from a different slice of what was found, so the
 * summary is cut to that slice. A reviewer asked about a published contract does
 * not need a runbook quote.
 */
function evidenceSummary(array $candidate, array $evidence, string $category): array
{
    $lines = [];

    $relevant = match ($category) {
        'contract-or-package-api' => ['contracts'],
        'manual-operation' => ['ops', 'deploy'],
        'route-never-hit' => ['contracts', 'ops', 'deploy', 'database'],
        'ambiguous-evidence' => ['repos', 'ops', 'deploy', 'contracts', 'github', 'database'],
        default => ['repos', 'ops', 'deploy', 'contracts', 'github', 'database'],
    };

    $shown = 0;
    foreach ((array) ($candidate['hits'] ?? []) as $hit) {
        if (! in_array($hit['source'] ?? '', $relevant, true)) { continue; }
        if ($category === 'ambiguous-evidence' && ($hit['confidence'] ?? '') === 'linked') { continue; }
        if ($shown++ >= 5) { $lines[] = '…more hits in the reference index'; break; }

        $where = (string) ($hit['path'] ?? '');
        if ((int) ($hit['line'] ?? 0) > 0) { $where .= ':' . $hit['line']; }
        $line = sprintf('%s %s (%s) %s', $hit['source'] ?? '?', $hit['matchKind'] ?? '?', $hit['confidence'] ?? '?', $where);
        if (($hit['snippet'] ?? '') !== '') { $line .= ' — ' . $hit['snippet']; }
        $lines[] = $line;
    }

    if ($category === 'not-yet-used') {
        $git = $evidence['git'];
        if (($git['available'] ?? false) === false) {
            $lines[] = 'git history not read: pass --repo=PATH to add it';
        } else {
            $introduced = $git['symbolIntroduced'] ?? $git['fileAdded'] ?? null;
            $lines[] = $introduced === null
                ? 'git found no commit introducing this name in that file'
                : sprintf('introduced %s in %s — %s', substr((string) $introduced['date'], 0, 10), $introduced['commit'], $introduced['subject']);
        }
        foreach ($evidence['flags'] as $flag) { $lines[] = 'feature flag pattern in its file: ' . $flag; }
        foreach ($evidence['roadmap'] as $doc) { $lines[] = 'named in planning document: ' . $doc; }
    }

    if ($category === 'contract-or-package-api' && $evidence['package'] !== null) {
        $lines[] = 'belongs to the package at ' . $evidence['package'];
    }

    if ($category === 'rare-but-critical') {
        $lines[] = 'vocabulary match: ' . implode(', ', $evidence['criticalWords']);
    }

    if ($lines === []) {
        $lines[] = 'no evidence in this slice: that absence is what the question is about';
    }

    return $lines;
}

// ---------------------------------------------------------------- output

function renderQuestions(array $result): string
{
    $out = [];
    $out[] = 'Questions the evidence cannot answer';
    $out[] = str_repeat('=', 36);
    $out[] = '';
    $out[] = sprintf(
        '%d candidate(s): %d need an answer, %d settled by the index, %d with nothing to ask.',
        $result['counts']['candidates'],
        $result['counts']['questioned'],
        $result['counts']['settled'],
        $result['counts']['unasked'],
    );
    $out[] = '';

    if ($result['groups'] === []) {
        $out[] = 'No questions. Either the index settled everything or nothing it found is ambiguous.';
        $out[] = '';
    }

    foreach ($result['groups'] as $i => $group) {
        $out[] = sprintf('%d. [%s] %s', $i + 1, $group['category'], $group['question']);
        $out[] = '';
        $out[] = '   Why it is being asked: ' . $group['why'];
        $out[] = '   Answer with one of: ' . implode(' | ', $group['answers']);
        $out[] = '   Ledger question id: ' . $group['id'];
        $out[] = '';

        foreach ($group['candidates'] as $candidate) {
            $header = '   - ' . $candidate['symbol'];
            if ($candidate['location'] !== '') { $header .= '  (' . $candidate['location'] . ')'; }
            $out[] = $header;
            $out[] = '     because: ' . implode('; ', $candidate['reasons']);
            foreach ($candidate['evidence'] as $line) {
                $out[] = '     · ' . $line;
            }
        }
        $out[] = '';
    }

    if ($result['settled'] !== []) {
        $out[] = 'SETTLED BY THE INDEX — alive, no question needed';
        $out[] = str_repeat('-', 72);
        foreach ($result['settled'] as $row) {
            $out[] = '  ' . $row['symbol'] . '  (' . $row['indexVerdict'] . ')';
        }
        $out[] = '';
    }

    if ($result['unasked'] !== []) {
        $out[] = 'NOTHING TO ASK';
        $out[] = str_repeat('-', 72);
        $out[] = '  No external reference and no category fits. These keep whatever verdict';
        $out[] = '  the static analysis gave them. Silence here is not a death certificate.';
        foreach ($result['unasked'] as $row) {
            $out[] = '  ' . $row['symbol'] . '  (' . $row['indexVerdict'] . ')';
        }
        $out[] = '';
    }

    $out[] = 'Record each answer with answers-ledger.php. No answer in any vocabulary above';
    $out[] = 'says `dead`: this step gathers evidence, it does not authorise a deletion.';
    $out[] = '';

    return implode("\n", $out);
}

function renderLedgerTemplate(array $result): string
{
    $lines = ["candidate\tquestion_id\tcategory\tquestion\tanswer\twho\tdate\tevidence"];

    foreach ($result['groups'] as $group) {
        foreach ($group['candidates'] as $candidate) {
            $lines[] = implode("\t", [
                $candidate['symbol'],
                $group['id'],
                $group['category'],
                str_replace(["\t", "\n"], ' ', $group['question']),
                '',
                '',
                '',
                str_replace(["\t", "\n"], ' ', implode(' | ', $candidate['evidence'])),
            ]);
        }
    }

    return implode("\n", $lines) . "\n";
}

// ---------------------------------------------------------------- main

$index = readIndex($opts['index']);
$config = readConfig($opts['config']);

$candidates = [];
$seen = [];
foreach ($index['candidates'] as $candidate) {
    if (! is_array($candidate) || ! isset($candidate['symbol'])) { continue; }
    $candidates[] = $candidate;
    $seen[(string) $candidate['symbol']] = true;
}

$added = 0;
foreach (readExtraCandidates($opts['candidates']) as $extra) {
    if (isset($seen[$extra['symbol']])) { continue; }
    $candidates[] = $extra + [
        'indexVerdict' => 'not-indexed',
        'hits' => [],
        'hitCounts' => ['linked' => 0, 'inferred' => 0, 'noContextSuppressed' => 0],
    ];
    $seen[$extra['symbol']] = true;
    $added++;
}

if ($candidates === []) {
    fwrite(STDERR, "the reference index holds no candidates\n");
    exit(1);
}

$built = buildGroups($candidates, $opts, $config);

$questioned = [];
foreach ($built['groups'] as $group) {
    foreach ($group['candidates'] as $candidate) { $questioned[$candidate['symbol']] = true; }
}

$result = [
    'schemaVersion' => SCHEMA_VERSION,
    'generatedAt' => gmdate('c'),
    'index' => [
        'path' => $opts['index'],
        'generatedAt' => $index['generatedAt'] ?? null,
        'sourcesRun' => $index['sourcesRun'] ?? [],
        'sourcesSkipped' => $index['sourcesSkipped'] ?? [],
    ],
    'inputs' => [
        'repo' => $opts['repo'],
        'appRoot' => $opts['app-root'] ?? $opts['repo'],
        'roadmap' => $opts['roadmap'],
        'packageRoots' => array_merge($opts['package-root'], $config['packageRoots']),
        'recentDays' => $opts['recent-days'],
        'candidatesAddedFromFile' => $added,
    ],
    'counts' => [
        'candidates' => count($candidates),
        'questioned' => count($questioned),
        'settled' => count($built['settled']),
        'unasked' => count($built['unasked']),
        'groups' => count($built['groups']),
    ],
    'groups' => $built['groups'],
    'settled' => $built['settled'],
    'unasked' => $built['unasked'],
];

if ($opts['json']) {
    $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        fwrite(STDERR, "could not encode the question set as JSON\n");
        exit(1);
    }
    fwrite(STDOUT, $json . "\n");
    exit(0);
}

fwrite(STDOUT, $opts['ledger-template'] ? renderLedgerTemplate($result) : renderQuestions($result));
exit(0);
