# Model-invoked vs user-invoked

Every `SKILL.md` in this repo is a skill. The axis that splits them is **invocation**, meaning who can
reach it.

- **User-invoked**: reachable only by the human typing its name. Set `disable-model-invocation: true`
  in the frontmatter (Claude Code) and `policy.allow_implicit_invocation: false` in
  `agents/openai.yaml` (Codex). The `description` is human-facing: a one-line summary read by a person
  browsing slash commands. Strip trigger lists.
- **Model-invoked**: reachable by model or user. The default. Omit `disable-model-invocation` and the
  `policy` block. The `description` is model-facing and keeps rich trigger phrasing ("Use when the
  user asks to…, mentions…") so auto-invocation fires.

The test for model-invoked: *could the model usefully reach for this on its own, without being asked?*
Reuse is a reason to extract a skill, not a reason to make it model-invoked.

A skill is user-invoked in **both** harnesses or neither. The frontmatter flag and the `openai.yaml`
policy are set together or not at all.

Reserve user-invoked for skills that spend real money, touch production, or run long. Anything that
only reads and reports should be model-invoked.

## Dependencies between skills

Express a dependency as an explicit instruction to call the Skill tool by name
(`Call the Skill tool with "laravel-cloud-deploy"`), not as a `../other-skill/FILE.md` cross-reference
and not as a bare `/name` left for the model to interpret. Shared reference material lives inside the
skill that owns it.

This only works when the named skill is model-invoked. A user-invoked skill can never be reached this
way. When a step depends on one, phrase it as an instruction for the human: "tell the user to run
`/<name>`".
