# Writing a docs page

Every skill in a promoted bucket has a page at `docs/<bucket>/<skill-name>.md`. The docs tree mirrors
`skills/engineering/` and `skills/operations/` only.

A `SKILL.md` is written for a model that is about to do the work. A docs page is written for a human
deciding whether to install it. Do not paste one into the other.

## Sections, in this order

### What it does

Two or three paragraphs. What the skill produces, and by what mechanism. Name the tools it shells out
to. If the skill has a hard prerequisite (an installed CLI, an authenticated account), it goes here in
the first paragraph, not in a footnote.

### When to reach for it

The situations that should make a reader think of this skill. A table of situation-to-outcome works
well. Include at least one line saying when **not** to reach for it, and what to reach for instead.

### Common questions

The questions a real user asks in the first ten minutes. Hunt for them in the skill's own edge cases:
every "if X, then" branch in the `SKILL.md` is a question somebody will ask. Answer honestly,
including "it cannot do that".

### It's working if

Concrete, observable signs the skill did its job. A reader should be able to check each one without
knowing anything about the internals.

## Rules

- No real customer data. Every application name, dollar figure, and identifier in an example is
  invented.
- Do not write install commands into a docs page. They live in `.agents/install-block.md` and are
  rendered by the README.
- State limits in the same breath as capabilities. A page that only lists what the skill can do is
  not finished.
