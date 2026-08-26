# Skill conventions

## Determinism first

A skill is prose that steers a model. Prose is the wrong tool for arithmetic, joins, and reconciling
totals: the same input has to produce the same number every run, and a model re-deriving a join by
hand costs tokens and drifts.

So: **anything that can be computed is computed by a script.** The model's job is to decide what to
run, read the structured output, and reason about what it means. If you find yourself writing
"add up the resource totals and compare them to…" into a `SKILL.md`, that paragraph is a bug report
against a missing script.

The reverse also holds. Do not script judgment: which finding matters to this user, what to do about
it, and whether an inferred match is really right are the model's work, and hard-coding them produces
confident nonsense.

## What a script owes its caller

- **A `--json` mode.** Human-readable output is the default; `--json` emits one object on stdout and
  nothing else, so a model can parse it without stripping banners.
- **Honest exit codes.** `0` success, `1` a real failure, `2` misuse (bad flags). A script that
  cannot reach its dependency exits non-zero with a message naming the missing tool and how to get it.
- **No secrets on disk, ever.** Never write a token, connection string, password, or build command to
  a file or into its own output. Where an upstream tool returns a payload containing credentials,
  narrow the request so the credential is never fetched rather than fetching and stripping it.
- **No writes the caller did not ask for.** Reporting scripts read. If a script can change remote
  state, that belongs in a separate script with a separate name, and the `SKILL.md` says so.
- **Portability.** POSIX shell or PHP 8.2+. PHP is a fair assumption for a Laravel audience and is
  already installed anywhere the Laravel tooling runs. Do not add a package manager, a lockfile, or a
  dependency to run one report.

## Money

Integer cents everywhere: in the code, in the JSON, in the intermediate math. Dollars appear only at
the moment a number is rendered for a human. Never float, never round mid-calculation.

Carry the currency alongside the amount. Do not assume USD.

## Missing data and inference

Three states, and they are never collapsed:

- **linked**: a real identifier joined the two records.
- **inferred**: a heuristic matched them, usually by name. Always labelled as such in output.
- **unattributed**: neither worked.

An unattributed line is not an error to be swept up: it is often the most interesting thing in the
report, because nothing nobody can name is anything anybody is watching. Surface it.

Never fold an inferred number into a headline total without saying so. A report that prints one
confident figure built partly on guesses is worse than one that prints two figures and a caveat.

## Reconcile, then report

When an upstream API gives both the parts and the total, add the parts and compare. If they disagree,
say so in the output rather than picking a side. A silent discrepancy is how a cost report loses the
right to be believed.
