# artisan-build-skills

## 0.2.0

### Minor Changes

- [#4](https://github.com/artisan-build/skills/pull/4) [`3934541`](https://github.com/artisan-build/skills/commit/393454184bd92ea7bbccd8a1e6355abda5331bf4) Thanks [@edgrosvenor](https://github.com/edgrosvenor)! - Two new in-progress skills for raising confidence about dead-code candidates before anything is
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

- [`57422b8`](https://github.com/artisan-build/skills/commit/57422b8757fcd1216f1d01969c251019578a4ec0) Thanks [@edgrosvenor](https://github.com/edgrosvenor)! - First release: the repository scaffold and `laravel-cloud-cost`.

  `laravel-cloud-cost` breaks a Laravel Cloud billing period down by application, reconciles the parts
  against the published total, and reports findings for the resources that usually turn out to be
  waste. It needs only the `cloud` CLI, authenticated, and it reads without changing anything.

  Attached resources carry no link back to the application that uses them, so those lines are matched
  by naming convention and labelled `inferred`. A `--map` file pins any the matcher gets wrong.

- [#3](https://github.com/artisan-build/skills/pull/3) [`33c60d7`](https://github.com/artisan-build/skills/commit/33c60d73315d14d4d81c4811a537ca10518e8090) Thanks [@edgrosvenor](https://github.com/edgrosvenor)! - laravel-cloud-cost: a new `scripts/cloud-orphans.php` decides which Laravel Cloud resources nothing
  is using, by proving references instead of matching names.

  The cost report matches a resource to an application by name, because the billing data carries no
  application id. That is fine for splitting a bill and misleading for finding an orphan: a database
  still named after the application that migrated off it reads as owned, and a cache named from before
  there was a convention reads as abandoned while production depends on it. On a fifteen-application
  organization, name matching called $50 of $51 of "unattributed" spend ownerless when all of it was in
  use, and missed a $45-per-period cluster that nothing referenced.

  The probe walks every environment with `cloud environment:get` — the only route that returns a
  populated `databaseSchemaId`, `cacheId`, and `websocketApplicationId` — and classifies every
  database cluster, cache, bucket, and websocket cluster as `in-use`, `probable-orphan`,
  `unreferenced-but-active`, `possibly-superseded`, or `unproven`. A cluster is a candidate only when
  no schema in it is referenced. Each candidate carries what it costs per period, joined from the cost
  report, and resources the cost report attributed to the wrong application are listed separately with
  a ready-to-use `--map` object.

  It costs one call per environment, so it runs serially with a pause and backs off on a 429, and it
  prints the call budget before spending it. Read-only, like the rest of the skill. Environment
  variables are read as key names only, through `--fields=environmentVariables.key`, so no value is
  ever fetched.

  Also corrected two things the skill claimed that are not true of CLI v0.6.3: database and cache
  metrics do exist (`database-cluster:metrics`, `cache:metrics`, windows `6h` to `30d`), and
  `environment:get` does return the three attachment ids rather than null. The docs now name which
  metric fields are usable and which come back empty.

  The resource lists can return two entries carrying the same id under different names. That is one
  resource with two records, so the probe decides references, schemas and the verdict once per id and
  bills the cost once, pointing the duplicate entry at the one holding the money with `sameIdAs`. It
  also reconciles the cost it attaches to verdicts against the resource total the cost report
  published, and reports a sum that exceeds it as an error rather than printing a total that is too
  high.

## 0.1.0

Initial scaffold.
