# Artisan Build Skills

Agent skills for shipping and operating Laravel applications. Consumed by Claude Code, Codex, and any
other harness that reads Agent Skills.

## Language

**Skill**:
A folder containing a `SKILL.md` and its supporting files. The unit this repo distributes.

**Bucket**:
A top-level grouping under `skills/`: `engineering`, `operations`, `in-progress`, `deprecated`. A
bucket is either **promoted** (shipped in the plugin and documented) or not.

**Harness**:
The agent runtime loading a skill: Claude Code, Codex, or another Agent Skills reader. Skills here
target no single harness.
_Avoid_: client, agent (the agent is the model acting, the harness is what it runs in)

**Deterministic script**:
An executable under a skill's `scripts/` that produces the same output for the same inputs, with no
model in the loop. The skill calls it; the model reads its output and reasons over it.

**Attribution**:
Assigning a cost line to the application that caused it. Each attributed line carries a
**confidence**: `linked` (a real foreign key), `inferred` (a naming-convention match), or
`unattributed` (neither).

**Finding**:
A named, evidence-carrying observation a skill emits: what it saw, what it costs, and what to check.
A finding is never an instruction to change infrastructure.

## Relationships

- A **Bucket** holds many **Skills**
- A **Skill** may own many **Deterministic scripts**
- A **Deterministic script** emits **Findings**, each carrying an **Attribution** confidence

## Flagged ambiguities

- "cost" means integer cents in every script and payload in this repo. Dollars appear only in
  rendered output for humans. Never float.
