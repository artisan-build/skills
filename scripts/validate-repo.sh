#!/usr/bin/env bash
set -uo pipefail

# Enforces the structural invariants described in CLAUDE.md.
# Exit 0 clean, 1 if any invariant is violated.

REPO="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO"

PROMOTED=(engineering operations)
UNPROMOTED=(in-progress deprecated)
fails=0

fail() { echo "FAIL: $*" >&2; fails=$((fails + 1)); }
ok()   { echo "  ok: $*"; }

plugin_json=".claude-plugin/plugin.json"

echo "== promoted skills =="
for bucket in "${PROMOTED[@]}"; do
  [ -d "skills/$bucket" ] || continue
  [ -f "skills/$bucket/README.md" ] || fail "skills/$bucket/README.md is missing"
  for dir in skills/"$bucket"/*/; do
    [ -d "$dir" ] || continue
    name="$(basename "$dir")"
    before=$fails

    [ -f "$dir/SKILL.md" ] || { fail "$name: no SKILL.md"; continue; }
    [ -f "$dir/agents/openai.yaml" ] || fail "$name: no agents/openai.yaml"

    # frontmatter name must match the folder name
    fm_name="$(awk 'NR>1 && /^---$/{exit} /^name:[[:space:]]/{sub(/^name:[[:space:]]*/,""); print; exit}' "$dir/SKILL.md")"
    [ "$fm_name" = "$name" ] || fail "$name: SKILL.md frontmatter name is '$fm_name'"

    grep -q '^description:[[:space:]]*[^[:space:]]' "$dir/SKILL.md" || fail "$name: SKILL.md has no description"

    grep -q "\"\./skills/$bucket/$name\"" "$plugin_json" || fail "$name: not listed in $plugin_json"
    grep -q "$name/SKILL.md)" "skills/$bucket/README.md" || fail "$name: not linked from skills/$bucket/README.md"
    grep -q "skills/$bucket/$name/SKILL.md" README.md || fail "$name: not linked from the top-level README.md"
    [ -f "docs/$bucket/$name.md" ] || fail "$name: no docs page at docs/$bucket/$name.md"

    # user-invoked is set in both harnesses or neither
    if grep -q '^disable-model-invocation:[[:space:]]*true' "$dir/SKILL.md"; then
      grep -q 'allow_implicit_invocation:[[:space:]]*false' "$dir/agents/openai.yaml" \
        || fail "$name: disable-model-invocation without allow_implicit_invocation: false"
    else
      grep -q 'allow_implicit_invocation:[[:space:]]*false' "$dir/agents/openai.yaml" \
        && fail "$name: allow_implicit_invocation: false without disable-model-invocation"
    fi

    [ "$fails" -eq "$before" ] && ok "$name"
  done
done

echo "== unpromoted skills stay out of the shipped set =="
for bucket in "${UNPROMOTED[@]}"; do
  [ -d "skills/$bucket" ] || continue
  for dir in skills/"$bucket"/*/; do
    [ -d "$dir" ] || continue
    name="$(basename "$dir")"
    grep -q "\"\./skills/$bucket/$name\"" "$plugin_json" && fail "$name: unpromoted but listed in $plugin_json"
    [ -f "docs/$bucket/$name.md" ] && fail "$name: unpromoted but has a docs page"
    ok "$name (not shipped, as intended)"
  done
done

echo "== scripts parse =="
while IFS= read -r f; do
  bash -n "$f" || fail "$f does not parse"
done < <(find skills scripts -name '*.sh' 2>/dev/null)

if command -v php >/dev/null 2>&1; then
  while IFS= read -r f; do
    php -l "$f" >/dev/null || fail "$f does not parse"
  done < <(find skills -name '*.php' 2>/dev/null)
else
  echo "  skipped php -l (php not installed)"
fi

echo "== no obvious secrets =="
if grep -rInE '(sk-[A-Za-z0-9]{20,}|ghp_[A-Za-z0-9]{20,}|-----BEGIN [A-Z ]*PRIVATE KEY)' \
     --exclude-dir=.git --exclude-dir=node_modules . ; then
  fail "possible secret committed"
fi

echo
if [ "$fails" -gt 0 ]; then
  echo "$fails problem(s)" >&2
  exit 1
fi
echo "all invariants hold"
