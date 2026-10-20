# The categories

Six categories, each with one question, its own answer vocabulary, and a rule deciding which
candidates land in it. A candidate can land in several or in none.

The rules are deterministic and live in the script. What a person makes of a category, and how the
question is phrased to them, is not.

## contract-or-package-api

Asked when the reference index returned the verdict `contract` or `contract-possible`, or when the
candidate's file belongs to a package rather than to the application.

Package membership is decided by `--package-root` when one is given. Otherwise the script walks up
from the candidate's file looking for a `composer.json`, and treats any manifest that is not the
application's own as a package boundary.

Answers: `keep-published`, `deprecate-first`, `never-published`, `unknown`. A published name has
consumers nobody can list from inside the repository. Removal needs a major version or a
deprecation window.

## route-never-hit

Asked when the candidate's kind is `route` and no `linked` hit came from the `repos` or `github`
sources, meaning no code anywhere that was searched names the route.

Answers: `external-consumer`, `internal-only`, `unknown`. A route reached only from outside is
unreachable from every static entry point and alive. A webhook endpoint and an integration nobody
documented look identical from here, and telemetry on a quiet application cannot separate them
either.

## rare-but-critical

Asked when the candidate's name or location contains a word from the critical-path vocabulary.
Matching is on word boundaries, with camel case split first, so `PurgeAccountJob` offers `purge` and
`account` while `taxonomy` does not count as `tax`. Hyphenated terms are matched against the whole
string.

The shipped vocabulary covers annual billing and renewals, invoices and dunning, chargebacks,
disputes and refunds, tax and year-end, payouts and settlement, backup and restore, failover and
disaster recovery, migration and rollback, export, account deletion and retention, audit and
compliance, incidents and escalation.

Override it in a config when a domain has its own long cycles:

```json
{ "criticalVocabulary": ["semester", "enrolment", "harvest", "filing", "accreditation"] }
```

Answers: `needed-in-cycle`, `superseded`, `unknown`. Code that runs once a year, or only during an
incident, is the code you least want tombstoned and the code least likely to appear in any
observation window.

## not-yet-used

Asked when any of three things holds: git says the candidate's name appeared in its file within
`--recent-days` (90 by default); the candidate's file contains a feature-flag pattern; the
candidate's short name appears in a planning document given with `--roadmap`.

Flag patterns searched: `Feature::active`, `Feature::inactive`, `Feature::for`, `Feature::when`,
`Pennant`, `@feature`, `feature_flag`, `featureFlag`, `config('features.`, `config("features.`.

Git history is read twice per candidate. The commit that added the file, and the oldest commit whose
diff changed how often the candidate's short name occurs in that file, which is the one that
introduced it. The age the rule uses comes from the second when it exists.

Answers: `planned-keep`, `abandoned`, `unknown`. Code not used yet looks exactly like code not
used any more, and a static analyser has no way to tell the future from the past.

Set `--recent-days` against the repository's own age. On a young repository the default of 90 days
catches everything: a live run over an application whose history starts 62 days ago put all fifteen
candidates in this category, and none of them was really new.

## manual-operation

Asked when the candidate's kind is `command` or `job`, or when the index found a hit in the `ops` or
`deploy` source.

Answers: `still-run`, `no-longer-run`, `unknown`. A command somebody runs by hand once a quarter
is unreachable from every entry point and completely alive. A runbook that mentions it proves it
was run that way, which is a claim about the past.

## ambiguous-evidence

Asked when the candidate has no `linked` hits but does have inferred hits, or matches that were
suppressed for want of context.

Answers: `is-referenced`, `not-this-symbol`, `unknown`. This is where the reference index's false
positives live. A reviewer reading one quoted line settles it, which is why the evidence for this
category drops the linked hits and shows only the ambiguous ones.

## Settled and unasked

A candidate in no category comes back in one of two lists.

`settled` means a `linked` hit from the `repos`, `github` or `database` source names it. Code or data
outside the application refers to the symbol by a form of its name that cannot be anything else, and
there is nothing to ask. Hits from `ops` and `deploy` do not settle anything, which is why a command
in a runbook still gets the manual-operation question.

`unasked` means nothing was found and no category fits. These keep whatever verdict the static
analysis gave them. Having no question to ask about a candidate is not a verdict about it.
