# Working on this repo

This repository is a collection of **agent skills**. It ships no application code. Everything here
is either a skill, a deterministic script a skill calls, or documentation about one of those.

## Layout

Skills are organized into bucket folders under `skills/`:

- `engineering/`: building and changing code
- `operations/`: running what is already built (cost, deploys, incidents, data)
- `in-progress/`: public on purpose, feedback wanted, not shipped in the plugin
- `deprecated/`: no longer used, kept for reference

`engineering/` and `operations/` are the **promoted** buckets. Every skill in a promoted bucket must:

1. have an entry in the top-level `README.md`, with the skill name linked to its `SKILL.md`
2. have an entry in its bucket's `README.md`
3. appear in `.claude-plugin/plugin.json`'s `skills` array
4. have a human-facing page at `docs/<bucket>/<skill-name>.md`

Skills in `in-progress/` and `deprecated/` must appear in **none** of those four places, except their
own bucket `README.md`.

`scripts/validate-repo.sh` enforces all of the above. Run it before opening a PR; CI runs it too.

## Every skill

```
skills/<bucket>/<skill-name>/
  SKILL.md              required: frontmatter (name, description) + the instructions
  agents/openai.yaml    required: Codex UI metadata, and the user-invoked policy when it applies
  scripts/              optional: deterministic work the skill shells out to
  *.md                  optional: reference material the SKILL.md points at
```

The folder name, the `name:` in the frontmatter, and the `display_name` in `agents/openai.yaml`
describe the same skill and must not drift apart.

## Invocation

Every skill is either **user-invoked** or **model-invoked**. See
[.agents/invocation.md](./.agents/invocation.md). Bucket `README.md`s and the top-level `README.md`
group entries under those two headings.

## Determinism first

A skill that can hand a job to a script should hand it to a script. See
[.agents/skill-conventions.md](./.agents/skill-conventions.md) for where that line sits, what a
script owes its caller (exit codes, `--json`, no secrets on disk), and the house rules on money,
missing data, and inference.

## Docs pages

`docs/` mirrors the two promoted buckets. A finished page carries four sections: **What it does**,
**When to reach for it**, **Common questions**, and **It's working if**. The template and the rules
are in [.agents/writing-docs.md](./.agents/writing-docs.md). Re-sync a skill's page whenever its
behaviour changes.

## Install commands

Copied verbatim from [.agents/install-block.md](./.agents/install-block.md). Change the wording
there first, then propagate. Run `claude plugin validate . --strict` after touching either manifest
in `.claude-plugin/`.

## Local development

`scripts/link-skills.sh` symlinks every skill into `~/.claude/skills` and `~/.agents/skills` so a
`git pull` keeps your installed copies current. Re-run it after adding or renaming a skill.

## Changesets

Every change to what a skill does needs a changeset (`npx changeset`). Describe the change from the
point of view of somebody who already has the skill installed.

## Prose

Write for a stranger who has the tool installed and no context. Say what the thing does, then what it
cannot do. Never state a capability that has not been run against a live system, and never illustrate
a skill with real customer data: examples in this repo are synthetic.
