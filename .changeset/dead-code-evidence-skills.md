---
"artisan-build-skills": minor
---

Two new in-progress skills for raising confidence about dead-code candidates before anything is
tombstoned or deleted. Both sit outside the application under test, and neither can conclude that
anything is dead.

`dead-code-reference-index` searches for references a static analyser inside one application cannot
see. A job class dispatched by a sibling app, a console command run from a quarterly runbook, a route
a payment provider posts to, a class name sitting in a `notifications.type` column since last March:
all of them are unreachable from inside and alive. Its `scripts/reference-index.php` takes a
candidates TSV (column names resolved by alias, so a producer renaming a column does not break it) or
a plain symbol list, plus a config naming the search sources, and reports every hit with its source,
file, line, match kind and confidence. Sources are other repository roots, ops-knowledge directories,
deploy and CI config read by named path rather than by walking a tree, published contracts, GitHub
organization code search through `gh`, and class names stored as strings in a database. Class names
are matched in four escapings, because a queued payload and a polymorphic column hold
`App\\Jobs\\Sync` while source holds `App\Jobs\Sync`. Short class names and method names are fenced
by an import or namespace in the same file, and matches that fail the fence are counted and withheld
unless `--include-no-context` asks for them, because that is where the false positives live. The
verdicts are `contract`, `contract-possible`, `alive-elsewhere`, `needs-review` and
`no-external-evidence`; none of them is `dead`.

The database source emits `SELECT DISTINCT` over one column per statement and runs nothing unless
handed a PDO DSN in a named environment variable, and refuses a DSN whose host is not local without
`--allow-remote-db`. The GitHub source searches only unambiguous needles, paces itself against the
ten-queries-a-minute code search limit, and resumes from a `--state` file.

`dead-code-interrogation` turns what the index could not settle into the smallest set of questions a
person has to answer, grouped so one answer settles a category rather than a candidate.
`scripts/triage-questions.php` reads the index JSON and sorts candidates into six categories: a
package's published API, a route nothing in your own code calls, the rare-but-critical paths of a
business cycle longer than any observation window, not-yet-used versus no-longer-used (from git
history for the commit that introduced the name, feature-flag patterns, and planning documents),
commands and jobs something outside the application reaches, and hits too ambiguous to read as
evidence. `scripts/answers-ledger.php` keeps the answers in an append-only TSV with the candidate,
question, answer, who answered, the date and the evidence it rested on, correcting by supersession
rather than by editing a row. No category's answer vocabulary contains a value meaning `dead`, and an
answer outside its vocabulary exits 2 without writing.

Both were shaped against a live read-only run over 149 local checkouts, an ops-knowledge tree, one
application's CI and `.cloud` config, and an organization's GitHub code search. Five things that run
found and the scripts now handle: the prefilter picks ripgrep or ugrep over grep when either is
installed, because BSD grep took a minute and a half over the same tree that ripgrep answered in
seven seconds; route names, command signatures and tool names only match on name boundaries, after
`github.callback` returned 43 `linked` hits that were all another application's
`auth.github.callback`; a search root holding the run's own earlier output needs `excludePaths`, or
the report is read back as evidence; the `github` source needs `excludeRepos` naming the repository
under test, because code search has no `selfRoots` and returned 39 of the application's own call
sites; and a hit from a directory that looks like another checkout of the application is flagged in
`notes`, after a worktree under a hidden `.worktrees` directory supplied 21 `linked` hits for one
route.

Both are in `skills/in-progress/`, so neither ships in the plugin yet.
