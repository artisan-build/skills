---
name: dead-code-reference-index
description: Search for references to dead-code candidates outside the application they live in, so a symbol that is only unreachable from inside stops looking dead. Use when the user has a list of unused or possibly-dead symbols, asks whether anything else still calls a class, job, command, route, or MCP tool, wants to check a tombstone or deletion candidate before removing it, or mentions a dead-code report, an unused-public scan, or a list of unreferenced classes. Searches other repositories, runbooks, CI and deploy config, published contracts, GitHub code search, and stored class names in a database. Reads only.
---

# Dead-code reference index

A static analyser running inside one application tells you what that application cannot reach. That
is a narrower claim than it looks. A job class dispatched by a sibling app, a console command run
from a quarterly runbook, a route a payment provider posts to, a class name sitting in a
`notifications.type` column since last March: every one of those is unreachable from inside and
entirely alive.

This skill goes looking for the references the analyser could not see, over sources the user names,
and reports each hit with where it came from and how much it is worth. It only ever adds evidence of
life. Nothing it produces makes a candidate deader than it already looked, and an empty result means
nothing was found rather than that nothing exists.

This skill only reads. It never edits a candidate's code, never tombstones anything, and never
writes to a database. The database source prints SQL for someone else to run unless it is handed a
DSN on purpose.

## Prerequisites

PHP 8.2 or newer, which runs the script. Nothing else is required.

Two sources need a tool, and both are skipped with a note when it is missing rather than failing the
run:

```bash
grep --version   # a prefilter; without it the script walks the trees itself, much more slowly
gh auth status   # needed only for the github source
```

## 1. Get the candidates

The script takes two shapes of input and works out which it has.

A candidates TSV with a header row is the normal case, including the one a tombstone toolkit
emits with columns for the symbol, its `file:line`, its kind, the static verdict, entry-point
evidence, production evidence, a final decision and a reason. Column names are matched by alias on a
normalised form, so a producer that renames `static verdict` to `verdict` or `file:line` to
`location` still works, and any column the script does not recognise is carried through untouched.
Check what it understood before you trust a run:

```bash
php scripts/reference-index.php --candidates=candidates.tsv --config=sources.json --json \
  | php -r '$d = json_decode(stream_get_contents(STDIN), true); print_r($d["candidateSource"]);'
```

A plain list of one symbol per line also works, optionally with the symbol's kind after a tab.
Use it when there is no toolkit output yet, or to chase a handful of names someone asked about. With
no kind given, the script infers one from the shape of the symbol: a backslash makes it a class, a
colon makes it a console command, dots make it a route name.

## 2. Write the config

Every search root is the user's to supply. The script assumes no paths, no organization, and no
database.

```bash
php scripts/reference-index.php --print-config > sources.json
```

Then fill it in. Each source is optional, and leaving one out is how you skip it.
[CONFIG.md](CONFIG.md) documents every field. `repos` searches other checkouts, which is where a sibling application dispatching your job lives.
`ops` searches runbooks, playbooks, operational notes and saved scripts, where a console command
signature is the match that matters. `deploy` reads named paths inside a repository (CI workflows,
`.cloud/config.json`, Procfiles, crontabs) rather than walking it, because a scheduled command
proves itself in one of about ten files. `contracts` searches OpenAPI documents, tool manifests and
docs directories, and a hit there means something different from the others: the name is published,
so removing it breaks somebody you cannot enumerate. `github` runs organization-wide code search
through `gh`. `database` looks for class names stored as strings.

`selfRoots` names the application under test. References inside it are excluded from `repos` and
`ops`, because counting an application as evidence of its own liveness is the mistake this skill
exists to avoid. The `deploy` and `contracts` sources are deliberately exempt: an application's own
CI workflow and its own published OpenAPI document are both external evidence about its code.

## 3. Run it

```bash
php scripts/reference-index.php --candidates=candidates.tsv --config=sources.json
```

Add `--json` to compute over the result, or `--tsv` for the hit table alone. Both are documented in
[OUTPUT.md](OUTPUT.md). Useful flags:

| Flag | What it buys |
| --- | --- |
| `--only=repos,ops` | one or two sources while you are still shaping the config |
| `--include-no-context` | the suppressed matches, which is where the false positives are |
| `--github-limit=N` `--state=FILE` | a bounded, resumable GitHub search |
| `--no-snippet` | drops the matched line, for output you are passing on |

A run over a hundred thousand files takes seconds with the grep prefilter in place. The GitHub source
is the slow one, because code search allows roughly ten queries a minute.

## 4. Read the verdicts

Each candidate comes back with one of five verdicts. None of them is `dead`.

| Verdict | What it means | What to do |
| --- | --- | --- |
| `contract` | a published contract names it exactly | removal is a breaking change. It needs a deprecation window, not a tombstone |
| `contract-possible` | a contract holds something close to the name | read the hit. A route name turned into a URI is a guess about URI shape |
| `alive-elsewhere` | something outside the application names it unambiguously | the candidate is wrong. Take it off the list |
| `needs-review` | only ambiguous references | a person or a model decides, with the hits in front of them |
| `no-external-evidence` | nothing matched | the candidate keeps whatever verdict static analysis gave it. **This is not a death certificate** |

Say that last row out loud when you report. "The index found nothing" and "the symbol is dead" are
different sentences, and the whole design of this skill rests on not confusing them. If the candidate
needs more confidence than the index could give it, the next step is the `dead-code-interrogation`
skill, which turns what is left into questions for a human.

## 5. Read the confidences honestly

Every hit carries a confidence, and the difference decides how much weight it can take.

A `linked` hit cannot plausibly be another symbol: a fully-qualified class name in any of its
escapings, a console command signature, a route name with its dots, an MCP tool name, a class name
read out of a database column.

An `inferred` hit could belong to something else: a short class name, a method name, a route spelled
kebab instead of dotted, a URI built from a route name. These are worth reading and are never worth
reporting as proof on their own.

A candidate with no hits at all is unattributed in the house sense, which the script reports as
`no-external-evidence`.

Never fold an inferred hit into a confident sentence. "Two repositories import this class" and "a
file somewhere mentions a class with the same short name" are both true of the same report and only
one of them settles anything.

## What the matcher does, and where it is wrong

[MATCHING.md](MATCHING.md) lists every match kind. The parts worth knowing before you read a report:

A class name is searched in four escapings, because the same name is written four ways across a
fleet. `App\Jobs\SyncOrders` in source, `App\\Jobs\\SyncOrders` in a JSON payload or a morph column,
`App\\\\Jobs\\\\SyncOrders` when that JSON is itself a string inside JSON, and `App/Jobs/SyncOrders`
in the occasional config. A dead-code hunt that searches only the first form misses every queued job
and every polymorphic row.

Short class names and method names are fenced by context. `handle`, `run`, `index` and `store` appear
in thousands of unrelated files, so a method needle only counts in a file that also names the owning
class, and a short class name only counts in a file that imports the candidate or shares its
namespace. Matches that fail the fence are suppressed and counted, and `hitCounts.noContextSuppressed`
tells you how many. Pass `--include-no-context` when you want to look at them; they come back tagged
`-no-context` so they cannot be mistaken for the fenced ones.

A method hit is never `linked`. One file can reference two classes that both define `send`, and no
text search can say which one a call went to.

Route names are searched dotted, kebab, snake, and as a URI built from the dots. The URI form is a
guess about how the route is spelled in a URL and is marked inferred for that reason.

## The database source

A class name stored as a string is the reference no amount of code reading will find. Polymorphic
`*_type` columns, `notifications.type`, and the `displayName` inside a queued job's payload all hold
fully-qualified class names, usually with escaped backslashes. Rename or delete that class and rows
that have been sitting there for a year stop resolving.

By default the source generates SQL and runs nothing:

```bash
php scripts/reference-index.php --candidates=candidates.tsv --config=sources.json --only=database
```

Hand the SQL to the user, or run it yourself against a replica or a restored snapshot. Every statement
is a `SELECT DISTINCT` over one column, so what comes back is a set of class names and never a row of
anybody's data.

To let the script run it, put a PDO DSN in an environment variable and name the variable:

```bash
DEAD_CODE_DSN='mysql:host=127.0.0.1;dbname=app_replica' \
  php scripts/reference-index.php --candidates=candidates.tsv --config=sources.json \
    --only=database --db-dsn-env=DEAD_CODE_DSN --db-user-env=DB_USER --db-pass-env=DB_PASS
```

A DSN whose host is not `127.0.0.1`, `localhost` or a local socket is refused with a message. That
default is there because the host in a copied `.env` is production more often than anyone expects.
`--allow-remote-db` overrides it, and the only right place to point it is a replica. Never run these
statements against a production primary, and never pass a credential on the command line where it
would land in a shell history.

## The GitHub source

`gh search code` over an organization finds the consumer nobody remembered, including in repositories
nobody has cloned. Two things shape how it is used.

Only `linked` needles are searched. A short class name would spend the whole budget returning other
people's code.

The rate limit is about ten queries a minute, so the script paces itself and caps a run with
`--github-limit`. Give it a `--state=FILE` and the run becomes resumable: queries already answered
come out of the state file, and `budget.githubPending` counts the ones that have not been sent yet.
Run it again with the same state file and it picks those up.

```bash
php scripts/reference-index.php --candidates=candidates.tsv --config=sources.json \
  --only=github --github-limit=20 --state=.reference-index-state.json
```

GitHub code search also only indexes default branches, so a reference on a long-lived feature branch
is invisible to it.

## What this cannot do

It cannot prove absence. Everything above is about finding references, and finding none is the one
result this skill cannot interpret for you.

It reads text, not call graphs. A reference means the name appears somewhere a human put it, not that
the line executes.

It cannot see a private repository nobody has cloned, unless `gh` can reach it and the github source
is configured. It cannot see a reference that lives only on a feature branch either.

It cannot see a consumer that builds the name at runtime. `$class = 'App\\Jobs\\' . $type;` defeats
every needle here, and that is the case the design rules call unknown rather than dead.

It cannot tell a stale reference from a live one. A runbook mentioning a command proves the command
was run that way once. Whether anybody still runs it is a question for a person, which is what the
`dead-code-interrogation` skill is for.

Short-name and method matching produce false positives by construction. The fencing limits them and
the confidence labels mark them. Neither removes them.

## Hand the result on

When the index leaves candidates unresolved, call the Skill tool with
"dead-code-interrogation" and give it this run's JSON. It groups what is left into questions a
person can answer once per category, and records the answers as evidence.
