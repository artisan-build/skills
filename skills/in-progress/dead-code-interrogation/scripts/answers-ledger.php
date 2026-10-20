#!/usr/bin/env php
<?php

/**
 * answers-ledger.php
 *
 * Keeps the append-only record of what a person said about a dead-code
 * candidate, so that the next tool along does not have to ask again.
 *
 * The ledger is the durable half of this skill. A question set is disposable —
 * run the index again and you get a new one — but "the finance team confirmed on
 * the 19th that the annual reconciler still runs every January" is a fact with a
 * date and an owner, and losing it costs somebody the same conversation twice.
 *
 * Append-only, with supersession. A row is never edited: a correction is a new
 * row with the same candidate and question, and the newest row wins. What the
 * earlier answer claimed, and when it changed, both survive.
 *
 * Nothing in here writes a `dead` verdict. The vocabulary has no such value, by
 * design: a person saying "we do not need that any more" is recorded as their
 * statement, and later tooling reading this ledger is reading evidence, not a
 * decision it can skip making.
 *
 * Exit codes: 0 success, 1 failure, 2 misuse.
 */

declare(strict_types=1);

const SCHEMA_VERSION = 1;

const COLUMNS = ['candidate', 'question_id', 'category', 'question', 'answer', 'who', 'date', 'evidence'];

/**
 * Every answer this ledger accepts, by the category it answers.
 *
 * `unknown` is in every list on purpose. "We do not know" is a real answer, it
 * is worth recording with a name against it, and a category where everybody
 * answers `unknown` is telling you something about the candidate.
 */
const VOCABULARY = [
    'contract-or-package-api' => ['keep-published', 'deprecate-first', 'never-published', 'unknown'],
    'route-never-hit' => ['external-consumer', 'internal-only', 'unknown'],
    'rare-but-critical' => ['needed-in-cycle', 'superseded', 'unknown'],
    'not-yet-used' => ['planned-keep', 'abandoned', 'unknown'],
    'manual-operation' => ['still-run', 'no-longer-run', 'unknown'],
    'ambiguous-evidence' => ['is-referenced', 'not-this-symbol', 'unknown'],
];

function usage(): string
{
    return <<<TXT
    Usage: php answers-ledger.php --ledger=FILE <command> [options]

    Commands:
      append          record one answer
      append-group    record the same answer for every candidate under one
                      question in a question set
      show            what the ledger currently says, newest row per candidate
                      and question
      verify          check the file's structure without changing it

    Options:
      --ledger=FILE     the ledger. Created with a header row if it is missing.
      --candidate=X     the candidate the answer is about           (append)
      --question-id=Q   the question id from the question set       (append, append-group)
      --category=C      the category, which fixes the vocabulary    (append)
      --question=TEXT   the question as it was asked                (append)
      --answer=A        one of the category's answers               (append, append-group)
      --who=NAME        who answered. A person, a role, or a team.  (append, append-group)
      --date=YYYY-MM-DD when they answered                  (default: today, UTC)
      --evidence=TEXT   what the answer rested on: a document, a conversation,
                        a dashboard, a reference-index hit
      --questions=FILE  the question set JSON from triage-questions.php
                                                                    (append-group)
      --json            emit one JSON object on stdout and nothing else
      -h, --help        this text

    Append-only. Never rewrites a row; a correction is a new row with the same
    candidate and question, and `show` reports the newest.

    Vocabularies:
    TXT . "\n" . vocabularyHelp();
}

function vocabularyHelp(): string
{
    $lines = [];
    foreach (VOCABULARY as $category => $answers) {
        $lines[] = sprintf('      %-24s %s', $category, implode(' | ', $answers));
    }

    return implode("\n", $lines) . "\n";
}

$opts = [
    'ledger' => null,
    'candidate' => null,
    'question-id' => null,
    'category' => null,
    'question' => null,
    'answer' => null,
    'who' => null,
    'date' => null,
    'evidence' => '',
    'questions' => null,
    'json' => false,
];
$command = null;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '-h' || $arg === '--help') { fwrite(STDOUT, usage()); exit(0); }
    if ($arg === '--json') { $opts['json'] = true; continue; }
    if (preg_match('/^--(ledger|candidate|question-id|category|question|answer|who|date|evidence|questions)=(.*)$/', $arg, $m)) {
        $opts[$m[1]] = $m[2];
        continue;
    }
    if (! str_starts_with($arg, '-') && $command === null) {
        $command = $arg;
        continue;
    }
    fwrite(STDERR, "unknown option: {$arg}\n\n" . usage());
    exit(2);
}

if ($opts['ledger'] === null) {
    fwrite(STDERR, "--ledger=FILE is required\n\n" . usage());
    exit(2);
}
if (! in_array($command, ['append', 'append-group', 'show', 'verify'], true)) {
    fwrite(STDERR, "give one command: append, append-group, show, verify\n\n" . usage());
    exit(2);
}

// ---------------------------------------------------------------- the file

function clean(string $value): string
{
    // Tabs and newlines would break the row, so they become spaces rather than
    // silently splitting one answer into two.
    return trim((string) preg_replace('/\s+/u', ' ', str_replace(["\t", "\r", "\n"], ' ', $value)));
}

/** @return array<int, array<string, string>> */
function readLedger(string $path): array
{
    if (! is_file($path)) { return []; }

    $lines = preg_split('/\r\n|\r|\n/', (string) file_get_contents($path)) ?: [];
    $rows = [];
    foreach ($lines as $number => $line) {
        if (trim($line) === '') { continue; }
        $cells = explode("\t", $line);
        if ($number === 0 && ($cells[0] ?? '') === 'candidate') { continue; }
        $row = [];
        foreach (COLUMNS as $i => $column) {
            $row[$column] = trim((string) ($cells[$i] ?? ''));
        }
        $row['_line'] = (string) ($number + 1);
        $row['_cells'] = (string) count($cells);
        $rows[] = $row;
    }

    return $rows;
}

function appendRows(string $path, array $rows): int
{
    $new = ! is_file($path);
    $handle = @fopen($path, 'a');
    if ($handle === false) {
        fwrite(STDERR, "cannot write to the ledger: {$path}\n");
        exit(1);
    }

    // The lock matters: two agents working the same candidate set would
    // otherwise interleave half-written rows.
    if (! flock($handle, LOCK_EX)) {
        fclose($handle);
        fwrite(STDERR, "cannot lock the ledger: {$path}\n");
        exit(1);
    }

    if ($new) {
        fwrite($handle, implode("\t", COLUMNS) . "\n");
    }

    $written = 0;
    foreach ($rows as $row) {
        $cells = [];
        foreach (COLUMNS as $column) {
            $cells[] = clean((string) ($row[$column] ?? ''));
        }
        fwrite($handle, implode("\t", $cells) . "\n");
        $written++;
    }

    flock($handle, LOCK_UN);
    fclose($handle);

    return $written;
}

function today(): string
{
    return gmdate('Y-m-d');
}

function validAnswer(string $category, string $answer): bool
{
    $allowed = VOCABULARY[$category] ?? null;

    return $allowed !== null && in_array($answer, $allowed, true);
}

// ---------------------------------------------------------------- commands

function commandAppend(array $opts): array
{
    foreach (['candidate', 'question-id', 'category', 'answer', 'who'] as $required) {
        if (($opts[$required] ?? null) === null || trim((string) $opts[$required]) === '') {
            fwrite(STDERR, "append needs --{$required}\n");
            exit(2);
        }
    }

    $category = (string) $opts['category'];
    if (! isset(VOCABULARY[$category])) {
        fwrite(STDERR, "unknown category '{$category}'. Known categories:\n" . vocabularyHelp());
        exit(2);
    }
    if (! validAnswer($category, (string) $opts['answer'])) {
        fwrite(STDERR, "'{$opts['answer']}' is not an answer to a {$category} question. Use one of: "
            . implode(' | ', VOCABULARY[$category]) . "\n");
        exit(2);
    }

    $date = (string) ($opts['date'] ?? '') !== '' ? (string) $opts['date'] : today();
    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        fwrite(STDERR, "--date must be YYYY-MM-DD\n");
        exit(2);
    }

    $row = [
        'candidate' => (string) $opts['candidate'],
        'question_id' => (string) $opts['question-id'],
        'category' => $category,
        'question' => (string) ($opts['question'] ?? ''),
        'answer' => (string) $opts['answer'],
        'who' => (string) $opts['who'],
        'date' => $date,
        'evidence' => (string) $opts['evidence'],
    ];

    $written = appendRows((string) $opts['ledger'], [$row]);

    return ['appended' => $written, 'rows' => [$row]];
}

/**
 * One answer for a whole category, which is the point of grouping the questions
 * in the first place. The question set names the candidates, so the person
 * answers once and every candidate under that question gets its own row.
 */
function commandAppendGroup(array $opts): array
{
    foreach (['questions', 'question-id', 'answer', 'who'] as $required) {
        if (($opts[$required] ?? null) === null || trim((string) $opts[$required]) === '') {
            fwrite(STDERR, "append-group needs --{$required}\n");
            exit(2);
        }
    }

    $path = (string) $opts['questions'];
    if (! is_file($path)) {
        fwrite(STDERR, "cannot read the question set: {$path}\n");
        exit(1);
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (! is_array($decoded) || ! isset($decoded['groups'])) {
        fwrite(STDERR, "that file is not a question set. Produce one with: triage-questions.php --json\n");
        exit(1);
    }

    $group = null;
    foreach ((array) $decoded['groups'] as $candidateGroup) {
        if (is_array($candidateGroup) && ($candidateGroup['id'] ?? '') === $opts['question-id']) {
            $group = $candidateGroup;
            break;
        }
    }
    if ($group === null) {
        $ids = [];
        foreach ((array) $decoded['groups'] as $candidateGroup) {
            if (is_array($candidateGroup)) { $ids[] = (string) ($candidateGroup['id'] ?? ''); }
        }
        fwrite(STDERR, "no question '{$opts['question-id']}' in that set. It holds: " . implode(', ', $ids) . "\n");
        exit(2);
    }

    $category = (string) ($group['category'] ?? '');
    if (! validAnswer($category, (string) $opts['answer'])) {
        fwrite(STDERR, "'{$opts['answer']}' is not an answer to a {$category} question. Use one of: "
            . implode(' | ', VOCABULARY[$category] ?? []) . "\n");
        exit(2);
    }

    $date = (string) ($opts['date'] ?? '') !== '' ? (string) $opts['date'] : today();
    if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        fwrite(STDERR, "--date must be YYYY-MM-DD\n");
        exit(2);
    }

    $evidence = (string) $opts['evidence'];
    $rows = [];
    foreach ((array) ($group['candidates'] ?? []) as $candidate) {
        if (! is_array($candidate) || ! isset($candidate['symbol'])) { continue; }
        $rows[] = [
            'candidate' => (string) $candidate['symbol'],
            'question_id' => (string) $opts['question-id'],
            'category' => $category,
            'question' => (string) ($group['question'] ?? ''),
            'answer' => (string) $opts['answer'],
            'who' => (string) $opts['who'],
            'date' => $date,
            'evidence' => $evidence !== '' ? $evidence : implode(' | ', (array) ($candidate['evidence'] ?? [])),
        ];
    }

    if ($rows === []) {
        fwrite(STDERR, "that question has no candidates under it\n");
        exit(1);
    }

    $written = appendRows((string) $opts['ledger'], $rows);

    return ['appended' => $written, 'rows' => $rows];
}

/**
 * The current state of the ledger: for each candidate and question, the newest
 * row, with the superseded ones counted so nothing looks like it was lost.
 */
function commandShow(array $opts): array
{
    $rows = readLedger((string) $opts['ledger']);

    $current = [];
    $superseded = 0;
    foreach ($rows as $row) {
        $key = $row['candidate'] . "\x1f" . $row['question_id'];
        if (isset($current[$key])) { $superseded++; }
        $current[$key] = $row;
    }

    $answers = [];
    foreach ($current as $row) {
        if ($opts['candidate'] !== null && $row['candidate'] !== $opts['candidate']) { continue; }
        unset($row['_line'], $row['_cells']);
        $answers[] = $row;
    }

    usort($answers, static fn ($a, $b) => [$a['candidate'], $a['question_id']] <=> [$b['candidate'], $b['question_id']]);

    return [
        'ledger' => (string) $opts['ledger'],
        'rowsTotal' => count($rows),
        'answersCurrent' => count($answers),
        'rowsSuperseded' => $superseded,
        'answers' => $answers,
    ];
}

/**
 * Structural checks only. Whether an answer is TRUE is not something a script
 * can know; whether a row is well formed, dated, attributed and inside its
 * category's vocabulary is.
 */
function commandVerify(array $opts): array
{
    $path = (string) $opts['ledger'];
    if (! is_file($path)) {
        return ['ok' => false, 'problems' => ["the ledger does not exist yet: {$path}"], 'rows' => 0];
    }

    $lines = preg_split('/\r\n|\r|\n/', (string) file_get_contents($path)) ?: [];
    $problems = [];

    $header = explode("\t", (string) ($lines[0] ?? ''));
    if ($header !== COLUMNS) {
        $problems[] = 'the header row is ' . implode(', ', $header) . ' but should be ' . implode(', ', COLUMNS);
    }

    $rows = readLedger($path);
    foreach ($rows as $row) {
        $at = 'line ' . $row['_line'];
        if ((int) $row['_cells'] !== count(COLUMNS)) {
            $problems[] = "{$at}: {$row['_cells']} cells, expected " . count(COLUMNS);
        }
        if ($row['candidate'] === '') { $problems[] = "{$at}: no candidate"; }
        if ($row['who'] === '') { $problems[] = "{$at}: no who"; }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['date'])) {
            $problems[] = "{$at}: date '{$row['date']}' is not YYYY-MM-DD";
        }
        if (! isset(VOCABULARY[$row['category']])) {
            $problems[] = "{$at}: unknown category '{$row['category']}'";
        } elseif (! validAnswer($row['category'], $row['answer'])) {
            $problems[] = "{$at}: '{$row['answer']}' is not an answer to a {$row['category']} question";
        }
    }

    return ['ok' => $problems === [], 'problems' => $problems, 'rows' => count($rows)];
}

// ---------------------------------------------------------------- main

$result = match ($command) {
    'append' => ['command' => 'append'] + commandAppend($opts),
    'append-group' => ['command' => 'append-group'] + commandAppendGroup($opts),
    'show' => ['command' => 'show'] + commandShow($opts),
    'verify' => ['command' => 'verify'] + commandVerify($opts),
};

$result = ['schemaVersion' => SCHEMA_VERSION] + $result;

if ($opts['json']) {
    $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        fwrite(STDERR, "could not encode the result as JSON\n");
        exit(1);
    }
    fwrite(STDOUT, $json . "\n");
    exit(($result['ok'] ?? true) ? 0 : 1);
}

switch ($command) {
    case 'append':
    case 'append-group':
        fwrite(STDOUT, "appended {$result['appended']} row(s) to {$opts['ledger']}\n");
        foreach ($result['rows'] as $row) {
            fwrite(STDOUT, '  ' . $row['candidate'] . '  ' . $row['answer'] . '  (' . $row['who'] . ', ' . $row['date'] . ")\n");
        }
        break;

    case 'show':
        fwrite(STDOUT, sprintf(
            "%d row(s), %d current answer(s), %d superseded\n\n",
            $result['rowsTotal'],
            $result['answersCurrent'],
            $result['rowsSuperseded'],
        ));
        foreach ($result['answers'] as $row) {
            fwrite(STDOUT, sprintf(
                "%-40s %-24s %-18s %s %s\n",
                $row['candidate'],
                $row['category'],
                $row['answer'],
                $row['date'],
                $row['who'],
            ));
            if ($row['evidence'] !== '') {
                fwrite(STDOUT, '    evidence: ' . $row['evidence'] . "\n");
            }
        }
        break;

    case 'verify':
        if ($result['ok']) {
            fwrite(STDOUT, "{$result['rows']} row(s), structure holds\n");
            break;
        }
        fwrite(STDERR, count($result['problems']) . " problem(s):\n");
        foreach ($result['problems'] as $problem) {
            fwrite(STDERR, '  ' . $problem . "\n");
        }
        exit(1);
}

exit(0);
