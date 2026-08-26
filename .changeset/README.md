# Changesets

Every change that alters what a skill does needs a changeset. Run `npx changeset`,
pick `patch` / `minor` / `major`, and describe the change from the point of view of
someone who has the skill installed: what will behave differently the next time
they run it.

The release workflow turns merged changesets into a version bump, a `CHANGELOG.md`
entry, and a git tag.
