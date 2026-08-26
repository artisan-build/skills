# Artisan Build Skills

Agent skills for shipping and operating Laravel applications.

These are the skills we use on our own work. They are built to a house style: a skill hands anything
computable to a **deterministic script**, so the same input gives the same answer every run and the
model spends its attention on judgment rather than arithmetic. They work in any harness that reads
Agent Skills, and they depend on nothing beyond the CLI each one already needs.

## Installation

Pick one route. Installing two leaves you with every skill twice.

<details>
<summary><strong>Claude Code</strong></summary>

```
/plugin marketplace add artisan-build/skills
/plugin install artisan-build-skills@artisan-build
```

The plugin is a managed, read-only bundle. `/plugin update` pulls new versions when we ship them.

</details>

<details>
<summary><strong>Codex, and other agents</strong></summary>

```bash
npx skills@latest add artisan-build/skills
```

Pick the skills you want and which agents to install them on. The files land in your project as
ordinary files you own and can edit.

</details>

<details>
<summary><strong>By hand</strong></summary>

```bash
git clone https://github.com/artisan-build/skills.git
ln -s "$PWD/skills/skills/operations/laravel-cloud-cost" ~/.claude/skills/laravel-cloud-cost
```

A skill is a folder with a `SKILL.md`. Any harness that reads Agent Skills will find it once the
folder is on its skills path.

</details>

## The skills

### Operations

Running what is already built.

**Model-invoked** (the agent can reach for these on its own):

- **[laravel-cloud-cost](./skills/operations/laravel-cloud-cost/SKILL.md)**: Break a Laravel Cloud
  bill down by application and environment, reconcile it against the published total, and surface the
  resources quietly costing money: databases that never scale down, caches sized for a launch that
  has not happened, and the ones nobody can name at all. Needs only the `cloud` CLI, authenticated.
  Reads; changes nothing. [Docs](./docs/operations/laravel-cloud-cost.md)

### Engineering

Building and changing code. Nothing here yet.

## What a skill looks like here

```
skills/<bucket>/<skill-name>/
  SKILL.md              frontmatter plus the instructions the model follows
  agents/openai.yaml    Codex metadata
  scripts/              the deterministic part
  *.md                  reference material the SKILL.md points at
```

Two house rules shape most of what you will read in them:

**Anything computable is computed.** Joins, totals, and reconciliation live in a script. If a
`SKILL.md` ever tells a model to add numbers up, that is a bug. See
[.agents/adr/0001](./.agents/adr/0001-deterministic-scripts-over-prose.md).

**Guesses are labelled as guesses.** Where a skill cannot get a real answer it says which parts are
inferred, and it never folds an inference into a confident headline number. See
[.agents/skill-conventions.md](./.agents/skill-conventions.md).

## Contributing

`CLAUDE.md` holds the conventions, `CONTEXT.md` the vocabulary. `scripts/validate-repo.sh` enforces
the structure and CI runs it. Every change to what a skill does needs a changeset (`npx changeset`).

## Licence

MIT, Artisan Build, Inc.
