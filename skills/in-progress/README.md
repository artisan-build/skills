# In progress

Public on purpose, feedback wanted, not shipped in the plugin and not documented under `docs/`.

A skill lands here while it is being shaped against real work. It graduates to `engineering/` or
`operations/` once it has been run enough times to know what it actually does, at which point it
picks up a README entry, a `plugin.json` entry, and a docs page.

## User-invoked

Reachable only when you type them (`disable-model-invocation: true` in Claude Code,
`policy.allow_implicit_invocation: false` in `agents/openai.yaml` for Codex).

None yet.

## Model-invoked

Model- or user-reachable.

- **[dead-code-reference-index](./dead-code-reference-index/SKILL.md)**: Search for references to
  dead-code candidates outside the application they live in: other checkouts, runbooks, CI and
  deploy config, published contracts, GitHub code search, and class names stored as strings in a
  database. Reads only, and its evidence can only move a candidate from dead toward unknown or
  alive.
- **[dead-code-interrogation](./dead-code-interrogation/SKILL.md)**: Group the dead-code evidence
  that cannot decide into one question per category, and keep the answers in an append-only ledger
  with who said it and when. Decides nothing; no answer in any of its vocabularies means `dead`.
