# Operations

Skills for running what is already built: cost, deploys, incidents, data.

## User-invoked

Reachable only when you type them (`disable-model-invocation: true` in Claude Code,
`policy.allow_implicit_invocation: false` in `agents/openai.yaml` for Codex).

None yet.

## Model-invoked

Model- or user-reachable.

- **[laravel-cloud-cost](./laravel-cloud-cost/SKILL.md)**: Break a Laravel Cloud bill down by
  application and environment, reconcile it against the published total, and surface the resources
  quietly costing money. Reads through the `cloud` CLI and changes nothing.
