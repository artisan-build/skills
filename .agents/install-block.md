# The canonical install block

One install story, one wording. `README.md`, `.changeset/*`, and every page under `docs/` must say
**this** and nothing else. Change it here first, then propagate.

This repo is **not** in Claude Code's official marketplace. It is its own single-plugin marketplace,
so Claude Code users add the marketplace first. Say so plainly rather than implying a one-command
install that does not exist.

## Claude Code: the plugin

<canonical-block name="claude-code">

```
/plugin marketplace add artisan-build/skills
/plugin install artisan-build-skills@artisan-build
```

The plugin is a managed, read-only bundle. `/plugin update` pulls new versions when we ship them.

</canonical-block>

## Codex, and other agents: skills.sh

<canonical-block name="skills-sh-whole-set">

```bash
npx skills@latest add artisan-build/skills
```

Pick the skills you want and which agents to install them on. The files land in your project as
ordinary files you own and can edit.

</canonical-block>

…and the single-skill form wherever one skill is named on its own:

<canonical-block name="skills-sh-one-skill">

```bash
npx skills@latest add artisan-build/skills --skill=<name>
```

```bash
npx skills@latest update <name>
```

</canonical-block>

## Anywhere else: clone it

<canonical-block name="manual">

```bash
git clone https://github.com/artisan-build/skills.git
ln -s "$PWD/skills/skills/operations/laravel-cloud-cost" ~/.claude/skills/laravel-cloud-cost
```

A skill is a folder with a `SKILL.md`. Any harness that reads Agent Skills will find it once the
folder is on its skills path.

</canonical-block>

## The routes are exclusive

The plugin is a bundle you subscribe to. skills.sh writes files you own. Installing both leaves the
user with every skill twice: always say "pick one".
