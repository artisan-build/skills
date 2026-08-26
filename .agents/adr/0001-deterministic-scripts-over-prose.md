# 0001: Deterministic scripts over prose for anything computable

**Status**: accepted
**Date**: 2026-08-26

## Context

The first skill in this repo (`laravel-cloud-cost`) reconciles a billing period across five resource
families, joins them to applications, and checks that the parts sum to the published total. That is
arithmetic over a few hundred records.

Written as `SKILL.md` prose, it would run differently every time: the model would re-derive the join,
sometimes normalise a name one way and sometimes another, and burn a large amount of context holding
raw JSON it only needed in aggregate. Two runs on the same billing period could disagree about the
bill, which is the one thing a cost report cannot do.

## Decision

Anything computable is computed by a script under the skill's `scripts/`. The `SKILL.md` decides
what to run and interprets the result. Scripts take `--json` and exit non-zero on failure.

Judgment stays with the model: which findings matter, whether an inferred attribution is right, and
what the user should actually do.

## Consequences

- Skills in this repo carry executable code, so they are reviewed like code and not only read like
  prose.
- Portability becomes a real constraint. We accept POSIX shell and PHP 8.2+, and no dependencies
  beyond what the skill's own prerequisite CLI already required.
- A change in an upstream API breaks a script loudly rather than degrading a model's answer quietly.
  That is the trade we want.
