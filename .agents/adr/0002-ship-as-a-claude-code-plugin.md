# 0002: Ship as a Claude Code plugin and as plain skill folders

**Status**: accepted
**Date**: 2026-08-26

## Context

The skills here target more than one harness. Claude Code reads plugins and `~/.claude/skills`;
Codex and others read Agent Skills folders. We want one source of truth, not a per-harness fork.

## Decision

The repository is its own single-plugin marketplace (`.claude-plugin/marketplace.json` plus
`plugin.json`), and every skill is also a plain folder containing a `SKILL.md` that any Agent Skills
reader can load directly. Harness-specific metadata is confined to two places: the `SKILL.md`
frontmatter and `agents/openai.yaml`.

We are not in Claude Code's official marketplace, so the documented Claude Code route adds this
marketplace first. We do not imply otherwise.

## Consequences

- Nothing harness-specific goes into the body of a `SKILL.md`. A skill that names a tool only one
  harness has is a skill that fails silently in the others.
- Three install routes have to stay in sync. They are written once in
  [.agents/install-block.md](../install-block.md) and copied verbatim.
- Applying to the official marketplace later is an additive change, and would only alter the install
  block.
