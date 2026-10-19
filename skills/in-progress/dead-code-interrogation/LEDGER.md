# The answers ledger

A tab-separated file, append-only, one row per answer. Created with its header row on first write.

| Column | Contents |
| --- | --- |
| `candidate` | the symbol the answer is about, exactly as the candidates file spelled it |
| `question_id` | the question's id from the question set, such as `q-manual-operation` |
| `category` | the category, which fixes which answers are legal |
| `question` | the question as it was asked |
| `answer` | one value from that category's vocabulary |
| `who` | who answered. A person, a role, or a team |
| `date` | `YYYY-MM-DD`, UTC, defaulting to today |
| `evidence` | what the answer rested on, such as a conversation, a document, a dashboard, or a hit from the reference index |

Tabs and newlines inside any value become single spaces on write, so one answer can never split into
two rows.

## Append-only, with supersession

A row is never edited or removed. A correction is a new row with the same `candidate` and
`question_id`, and the newest row wins. `show` reports the current answers and counts how many rows
were superseded, so an answer that changed leaves both the old claim and the date it changed.

Writes take an exclusive lock, so two agents working the same candidate set cannot interleave
half-written rows.

## The vocabularies

| Category | Answers |
| --- | --- |
| `contract-or-package-api` | `keep-published`, `deprecate-first`, `never-published`, `unknown` |
| `route-never-hit` | `external-consumer`, `internal-only`, `unknown` |
| `rare-but-critical` | `needed-in-cycle`, `superseded`, `unknown` |
| `not-yet-used` | `planned-keep`, `abandoned`, `unknown` |
| `manual-operation` | `still-run`, `no-longer-run`, `unknown` |
| `ambiguous-evidence` | `is-referenced`, `not-this-symbol`, `unknown` |

None of them contains a value meaning `dead`. An answer outside its category's vocabulary exits 2 and
writes nothing.

`unknown` is in every vocabulary. "We do not know" is a real answer, it is worth a name and a date,
and a category where everybody answers `unknown` is telling you something about the candidate.

## Reading it as evidence

Tooling that deletes code can treat this ledger as input, under three rules.

Read the newest row per `candidate` and `question_id` and ignore the rest. Earlier rows are history.

Treat every answer as a human statement with an owner, not as a verdict. `no-longer-run` from an
operations lead on a dated row is good evidence that a command is not scheduled anywhere. It is not
the same object as a static analyser's reachability result, and collapsing the two is how a
confirmation turns into an authorisation nobody gave.

A candidate with no row has not been asked about. Absence of an answer is not an answer.

## Example

```
candidate	question_id	category	question	answer	who	date	evidence
widgets:reconcile	q-manual-operation	manual-operation	is it still run that way?	still-run	the operations lead	2026-10-19	confirmed in the 2026-10-19 standup
orders.export	q-route-never-hit	route-never-hit	does an external client post to it?	external-consumer	the integrations team	2026-10-19	the shipping provider posts to it hourly
widgets:reconcile	q-manual-operation	manual-operation	is it still run that way?	no-longer-run	the operations lead	2026-11-02	the January pipeline replaced it
```

The third row supersedes the first. `show` reports `no-longer-run` for `widgets:reconcile` and counts
one superseded row.
