---
"artisan-build-skills": minor
---

First release: the repository scaffold and `laravel-cloud-cost`.

`laravel-cloud-cost` breaks a Laravel Cloud billing period down by application, reconciles the parts
against the published total, and reports findings for the resources that usually turn out to be
waste. It needs only the `cloud` CLI, authenticated, and it reads without changing anything.

Attached resources carry no link back to the application that uses them, so those lines are matched
by naming convention and labelled `inferred`. A `--map` file pins any the matcher gets wrong.
