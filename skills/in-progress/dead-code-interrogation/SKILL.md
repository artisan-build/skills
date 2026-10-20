---
name: dead-code-interrogation
description: Turn the dead-code evidence that cannot decide into a small set of questions for a human, grouped so one answer settles a whole category, and record the answers in an append-only ledger later tooling can read as evidence. Use after a dead-code or tombstone scan leaves candidates unresolved, when the user asks what they need to confirm before deleting code, when a candidate might be a webhook, a package's public API, an annually-run job, or something not switched on yet, or when a previous answer about a candidate needs looking up. Reads only; decides nothing.
---

# Dead-code interrogation

A dead-code hunt ends with three kinds of candidate. Some are plainly alive, because something
outside the application names them. Some are not questions at all, because nothing about them is in
doubt yet. The rest are stuck on something a person knows in one sentence and no tool can work out:
whether that endpoint is how a payment provider talks to the business, whether anybody has run that
command since the move off the old host, whether the half-finished class is waiting to be switched on
or was abandoned in March.

This skill sorts those into categories, asks one question per category, and writes what comes back
into a ledger that keeps who said it and when.

It decides nothing. The answer vocabularies contain no value meaning `dead`, deliberately. A person
saying "we do not need that any more" is a fact about a business with a name and a date on it, which
is a different kind of fact from a static analyser's verdict, and the ledger keeps the two apart so
that whatever deletes code later has to make its own decision.

## Prerequisites

PHP 8.2 or newer. A reference index to read, produced by the `dead-code-reference-index` skill:

```bash
php ../dead-code-reference-index/scripts/reference-index.php \
  --candidates=candidates.tsv --config=sources.json --json > index.json
```

If there is no index yet, call the Skill tool with "dead-code-reference-index" first. Asking a person
a question the index could have answered wastes the only expensive thing in this loop.

Git history, feature flags and planning documents sharpen the questions but are optional, and the
script says in its output when it did not read them.

## 1. Build the question set

```bash
php scripts/triage-questions.php --index=index.json \
  --repo=/checkouts/widget-shop \
  --roadmap=/notes/roadmap \
  --package-root=/checkouts/widget-shop/packages/widget-sdk \
  --json > questions.json
```

Leave `--json` off to read it yourself. What each input adds:

`--repo` is a git checkout the script reads history from. For every candidate with a `file:line` it
finds when the file was added and, separately, when the candidate's own name first appeared in that
file. The second is the one that matters: a method added last week inside a file from 2019 reads as
old by file date and is a week old in fact.

`--roadmap` is a file or directory of planning documents. A candidate whose short name appears in one
is probably waiting rather than abandoned. Repeatable.

`--package-root` marks a directory as a package's public API. Without it the script looks for the
nearest `composer.json` above each candidate and treats anything that is not the application's own
manifest as a package.

`--app-root` is where relative candidate locations resolve from, and where feature-flag patterns are
read. It defaults to `--repo`, then to the working directory.

`--recent-days` sets how new counts as new, and defaults to 90. Set it against the repository's own
age: on an application whose history starts 62 days ago, the default put all fifteen candidates of a
live run in the not-yet-used category and none of them was really new.

## 2. Read the categories

Six categories, each with one question and its own answer vocabulary.
[CATEGORIES.md](CATEGORIES.md) gives the assignment rule for each.

| Category | The question it asks | Why a tool cannot answer it |
| --- | --- | --- |
| `contract-or-package-api` | is this still part of what you publish? | a published name has consumers nobody can enumerate from inside the repository |
| `route-never-hit` | does an external client post to this? | a route reached only from outside is unreachable from every entry point and alive |
| `rare-but-critical` | does this run on a cycle you have not observed yet? | thirty days of telemetry says nothing about an annual process |
| `not-yet-used` | is this waiting to be switched on? | code not used yet looks exactly like code not used any more |
| `manual-operation` | is it still run that way? | a runbook proves a command was run that way, not that it still is |
| `ambiguous-evidence` | is that hit really about this symbol? | a text search cannot tell one `handle` from another |

A candidate can land in several, and each question is still worth asking because they have different
answers. A billing reconciler named in a runbook is both rare-but-critical and a manual operation.

Candidates in no category come back in one of two lists. `settled` means code or data outside the
application names the candidate by a form of its name that cannot be anything else, so there is
nothing to ask. `unasked` means nothing was found and no category fits; those keep whatever verdict
the static analysis gave them, and the absence of a question is not a verdict of its own.

## 3. Ask the questions

Put the question in front of a person with the evidence the script gathered under it, once per
category rather than once per candidate. That grouping is the whole point: fifteen candidates in six
categories is six questions, and asking fifteen is how a reviewer stops reading.

Three things to get right when you ask.

Quote the evidence, not a summary of it. Each candidate under a question comes with the hits that
put it there, with their file, line and matched text. A reviewer answering "is that hit really about
this symbol?" needs the line, and a reviewer told "an ambiguous reference was found" has to go and
look it up.

Say what you do not know. Where the script could not read git history or a roadmap, it says so in the
evidence lines. Pass that on rather than letting an absence read as a finding.

Never offer `dead` as an answer, or paraphrase one of the vocabularies into it. The strongest answers
available are statements about a business: never published, abandoned, no longer run, superseded, not
this symbol. Record those as what they are.

## 4. Record the answers

One answer for a whole category, fanned out to every candidate under it:

```bash
php scripts/answers-ledger.php --ledger=answers.tsv append-group \
  --questions=questions.json \
  --question-id=q-manual-operation \
  --answer=still-run \
  --who="the operations lead" \
  --evidence="confirmed in the 2026-10-19 standup"
```

One answer for one candidate, when the reviewer splits a category:

```bash
php scripts/answers-ledger.php --ledger=answers.tsv append \
  --candidate='widgets:reconcile' \
  --question-id=q-manual-operation \
  --category=manual-operation \
  --question='is it still run that way?' \
  --answer=no-longer-run \
  --who="the operations lead" \
  --evidence="the January pipeline replaced it"
```

An answer outside its category's vocabulary is a misuse and exits 2 without writing anything. That is
what stops `dead` from being recorded here by accident.

The ledger is append-only with supersession, which is to say a row is never edited. A correction is a
new row with the same candidate and question, and `show` reports the newest while counting how many
it superseded, so what the earlier answer claimed and when it changed both survive.

```bash
php scripts/answers-ledger.php --ledger=answers.tsv show
php scripts/answers-ledger.php --ledger=answers.tsv verify
```

`verify` checks structure: the header, the cell count, a date in `YYYY-MM-DD`, a named answerer, a
known category, an answer inside that category's vocabulary. It exits 1 and lists every problem when
one fails. Whether an answer is true is not something a script can check.

[LEDGER.md](LEDGER.md) documents the columns and how deletion tooling should read them.

## 5. What to do with what is left

A candidate with a recorded answer has evidence attached that outlives this run, and whatever decides
about deletion later can read it instead of asking again. A candidate whose answer is `unknown` is
still unknown, and `unknown` with a name and a date against it is worth more than silence: it says
somebody looked.

Candidates still unresolved go back to the index with a wider config, or stay where they are. The
design rule behind both of these skills runs one way only. Evidence can move a candidate from dead to
unknown or alive, and nothing here can move one toward dead.

## What this cannot do

It cannot ask the questions for you. The script builds the set and records the answers; getting a
person to answer is the part that needs a person.

It cannot tell whether an answer is true. `verify` checks that a row is well formed, dated and
attributed, and no further.

It cannot find a category nobody configured. The critical-path vocabulary ships with the words of a
general business cycle, and a domain with its own long cycles (a school year, a growing season, a
regulatory filing window) needs those words added to a config before the category fires.

It cannot read a reference the index did not find. Garbage in: a candidate with no hits because the
index was pointed at the wrong roots looks the same here as a candidate with no hits because nothing
references it.

It cannot authorise a deletion, and neither can the ledger. The ledger is evidence for that decision,
with a name and a date on every row.
