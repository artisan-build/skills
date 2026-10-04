# artisan-build-skills

## 0.2.0

### Minor Changes

- [`57422b8`](https://github.com/artisan-build/skills/commit/57422b8757fcd1216f1d01969c251019578a4ec0) - First release: the repository scaffold and `laravel-cloud-cost`.

  `laravel-cloud-cost` breaks a Laravel Cloud billing period down by application, reconciles the parts
  against the published total, and reports findings for the resources that usually turn out to be
  waste. It needs only the `cloud` CLI, authenticated, and it reads without changing anything.

  Attached resources carry no link back to the application that uses them, so those lines are matched
  by naming convention and labelled `inferred`. A `--map` file pins any the matcher gets wrong.

## 0.1.0

Initial scaffold.
