# Workflow — skills

Project profile for the `multi-agent-build` skill. The coordinator agent reads this FIRST.

This repository ships no application code. Everything in it is a skill, a deterministic script a
skill calls, or documentation about one of those. That shapes every field below: there is no test
suite, no dependency graph, and no runtime to exercise, so the gate is structural and the proof that
a change works is a live run against a real organization.

## Phase & mode
- phase: launched (public repo, installed from the plugin marketplace)
- default mode: B-review-each-PR
- merge_policy: brains merges after verifying, on Ed's per-change authorization. **CI green is NOT a
  sufficient merge gate** — CI is structure + `php -l` + a secret scan only, with no tests and no
  static analysis, so it cannot tell a working script from a broken one.
- merge method: `gh pr merge --squash`

## Hard gate (must be green before review; coordinator verifies on the committed SHA, clean tree)
- command: `bash scripts/validate-repo.sh && node scripts/sync-plugin-version.mjs --check`
- extra suites: none — there is no test suite in this repo. Do not invent one. A behavioural change
  to a skill's script is proved by running it against a real organization, read-only, and keeping the
  output OUT of the repo (it is client data).
- monorepo: no

## CI (the merge gate for Mode A)
- status: **inadequate** — do not gate merges on CI
- minimum bar: CI must include (1) testing and (2) static analysis. This CI has neither.
- workflows/jobs: `.github/workflows/validate.yml` — `scripts/validate-repo.sh` (structure, frontmatter
  and plugin manifest invariants, `bash -n`, `php -l`, a regex secret scan) on PHP 8.5 only, which is the one runtime the fleet deploys;
  `.github/workflows/release.yml` — changesets.

## Dependency install (fresh worktree)
- command: `npm install` (only needed to run changesets or the version check)
- post-install: none. The skills' own scripts are single-file PHP with zero dependencies and run
  straight from a clone.

## Agent-role constraints and exceptions

Fleet bindings come from `~/Herd/brain/agents.json` and are resolved with
`~/Herd/brain/playbooks/resolve-agent-role.md`. Do not copy the global bindings here.

- constraints: a worker changing a skill that shells out to a vendor CLI needs that CLI installed and
  authenticated, plus a real organization to read, or it cannot reach the verdict floor.
- overrides: none
- invocation quirks: none

## Toolchain conformance — the ride-along rule (STANDING, all projects)

Run the project's full conformance command as part of FINALIZING every PR, and let whatever it
changes ride along in that PR as a single isolated commit.

In this repo the conformance command is `bash scripts/validate-repo.sh`, and it is a **checker, not a
formatter**: it changes no files. So the ride-along commit is usually absent, and the honest report is
"run, no-op". Say that rather than omitting it. If the validator ever starts rewriting files, its
output rides along in its own commit titled `validate-repo`.

The rule it embodies still applies: leave things better than you found them, even when the
improvement is not strictly related to the work at hand.

- Applies to every PR regardless of size.
- Scope-discipline language in a brief does NOT suspend this.
- Do not open a separate branch for conformance changes.
- The one exception: changing the validator's own rules, which gets its own branch and PR.

## Ship details
- branch naming: `feat/<slug>`, `fix/<slug>`, `docs/<slug>`
- PR target repo: `artisan-build/skills`, branch `main`
- release / split steps: changesets. `npm run version` runs `changeset version` and then
  `scripts/sync-plugin-version.mjs`, which copies the new version into
  `.claude-plugin/plugin.json`; `--check` fails when the two have drifted.
  **NEVER merge the automated "chore: version skills" PR** (currently #1). Releasing a version is
  Ed's call, per version, and merging it publishes one.

## Plan & coordination
- plan location: the brief in `~/Herd/brain/projects/skills/brief-<slug>.md`
- Solo project: skills (id 103)
- run-log: the decision trail at `~/Herd/brain/projects/skills/decisions-<task-slug>.tsv`

## Stack notes / quirks
- **Every promoted skill must appear in four places** or `validate-repo.sh` fails: the top-level
  `README.md`, its bucket `README.md`, the `skills` array in `.claude-plugin/plugin.json`, and a docs
  page at `docs/<bucket>/<skill-name>.md`. Skills in `in-progress/` and `deprecated/` must appear in
  none of them except their own bucket README.
- The folder name, the `name:` in the SKILL.md frontmatter, and `display_name` in
  `agents/openai.yaml` all describe the same skill and must not drift.
- User-invoked is set in both harnesses or neither: `disable-model-invocation: true` in the
  frontmatter AND `policy.allow_implicit_invocation: false` in `agents/openai.yaml`.
- Skill scripts are single-file PHP 8.2+ with zero dependencies: no composer, no lockfile, no package
  manager to run one report. `--json` emits one object on stdout; exit 0 success, 1 failure, 2 misuse.
  Money is integer cents everywhere.
- **Never commit real customer data.** Every application name, dollar figure, and identifier in this
  repo is synthetic. A live run's output belongs in `~/Herd/brain/projects/skills/`, never here.
- Install commands are copied verbatim from `.agents/install-block.md`. Change the wording there
  first, then propagate, then run `claude plugin validate . --strict`.
- Prose that humans read (docs pages, READMEs) goes through the `human-writing` skill before the PR
  opens, with the checker's before and after counts in the PR body.
