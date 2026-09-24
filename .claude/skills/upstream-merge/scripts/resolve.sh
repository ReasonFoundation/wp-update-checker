#!/usr/bin/env bash
# resolve.sh - resolve the mechanical conflicts of an in-progress upstream merge.
# Usage: .claude/skills/upstream-merge/scripts/resolve.sh
#   Run after `git merge upstream/master` stops with conflicts.
# Output: one JSON object on stdout. Stages what it resolves; never commits.
#
# What it does, in order:
#   1. Refuses to take upstream's side of any Puc/ file that we changed for more
#      than the namespace rename (reported as "customized").
#   2. Takes upstream's side of every other conflicted Puc/ file and the new loader.
#   3. Renames YahnisElsts\PluginUpdateChecker -> ReasonDev\PluginUpdateChecker in
#      Puc/, the loader and README.md, conflicted or not (new upstream files too).
#      The pattern also matches the doubled backslashes in string constants, such as
#      the Autoloader's DEFAULT_NS_PREFIX.
#   4. Re-adds our loader's `require_once __DIR__ . '/reason-...'` lines.
#   5. Resolves README.md hunks whose two sides differ only by namespace/version.
#   6. Resolves composer.json hunks by keeping our side with the loader renamed.
#   7. Updates old-version references in reason-*.php and tests/.

set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

if ! git rev-parse -q --verify MERGE_HEAD >/dev/null; then
  jq -nc '{status:"error",message:"no merge in progress; run git merge upstream/master first"}'
  exit 1
fi

loader_on() { git ls-tree --name-only "$1" | grep -E '^load-v[0-9]+p[0-9]+\.php$' | head -1; }
OLD_LOADER=$(loader_on HEAD)
NEW_LOADER=$(loader_on MERGE_HEAD)
OLD=${OLD_LOADER#load-}; OLD=${OLD%.php}
NEW=${NEW_LOADER#load-}; NEW=${NEW%.php}
BASE=$(git merge-base HEAD MERGE_HEAD)

conflicted() { git diff --name-only --diff-filter=U; }
rename_ns() { perl -pi -e 's/YahnisElsts(\\+)PluginUpdateChecker/ReasonDev$1PluginUpdateChecker/g' "$@"; }

# 1. Which of our Puc/ files carry more than the namespace rename?
CUSTOMIZED=""
for f in $(git ls-tree -r --name-only HEAD Puc); do
  ours=$(git show "HEAD:$f" | perl -pe 's/ReasonDev(\\+)PluginUpdateChecker/YahnisElsts$1PluginUpdateChecker/g')
  if ! base=$(git show "$BASE:$f" 2>/dev/null); then
    CUSTOMIZED="$CUSTOMIZED$f"$'\n'  # added by us, not in upstream
  elif [ "$ours" != "$base" ]; then
    CUSTOMIZED="$CUSTOMIZED$f"$'\n'
  fi
done
# Map a HEAD path (Puc/vOLD/...) to its merged path (Puc/vNEW/...).
is_customized() {
  local p=${1/Puc\/$NEW\//Puc/$OLD/}
  printf '%s' "$CUSTOMIZED" | grep -qxF "$p"
}

# 2. Take upstream's side of the mechanical conflicts.
TOOK_THEIRS=0
for f in $(conflicted | grep -E "^(Puc/|$NEW_LOADER\$)" || true); do
  if is_customized "$f"; then continue; fi
  git checkout --theirs -- "$f"
  TOOK_THEIRS=$((TOOK_THEIRS + 1))
done

# 3. Namespace rename across everything upstream owns.
find Puc -type f -name '*.php' -print0 | xargs -0 perl -pi -e 's/YahnisElsts(\\+)PluginUpdateChecker/ReasonDev$1PluginUpdateChecker/g'
rename_ns "$NEW_LOADER" README.md

# 4. Re-add our require_once lines, taken from our side's loader, after `new Autoloader();`.
REQUIRES=$(git show "HEAD:$OLD_LOADER" | grep -E "^require_once __DIR__ \. '/reason-" || true)
ADDED_REQUIRES=0
if [ -n "$REQUIRES" ] && ! grep -qF "$(printf '%s' "$REQUIRES" | head -1)" "$NEW_LOADER"; then
  REQUIRES="$REQUIRES" perl -0pi -e 's/(new Autoloader\(\);\n)\n/$1\n$ENV{REQUIRES}\n\n/' "$NEW_LOADER"
  ADDED_REQUIRES=$(printf '%s\n' "$REQUIRES" | wc -l | tr -d ' ')
fi

# 5. README.md: keep upstream's side of hunks that match ours once namespace and
#    version are normalized; leave the rest for a human.
if conflicted | grep -qx README.md; then
  perl -0pi -e '
    sub norm { my $s = shift; $s =~ s/ReasonDev|YahnisElsts/NS/g; $s =~ s/v\d+p\d+/vXpY/g; $s }
    s{<<<<<<< [^\n]*\n(.*?)=======\n(.*?)>>>>>>> [^\n]*\n}{ norm($1) eq norm($2) ? $2 : $& }gse;
  ' README.md
fi

# 6. composer.json: our side (it carries the Reason classmap), loader renamed.
if conflicted | grep -qx composer.json; then
  perl -0pi -e 's{<<<<<<< [^\n]*\n(.*?)=======\n.*?>>>>>>> [^\n]*\n}{$1}gs' composer.json
fi
perl -pi -e "s/load-v\\d+p\\d+\\.php/$NEW_LOADER/g" composer.json

# 7. Old-version references in our own files (docblocks, @return types, comments).
if [ "$OLD" != "$NEW" ]; then
  for f in reason-*.php tests/*.php; do
    [ -f "$f" ] || continue
    perl -pi -e "s/load-$OLD\\.php/$NEW_LOADER/g; s/\\\\$OLD\\\\/\\\\$NEW\\\\/g" "$f"
  done
fi

# Stage every file we touched that is now free of conflict markers.
for f in $(conflicted) $(git diff --name-only -- Puc) "$NEW_LOADER" composer.json README.md reason-*.php tests/*.php; do
  [ -f "$f" ] || continue
  grep -qE '^(<<<<<<<|>>>>>>>) ' "$f" || git add -- "$f"
done

REMAINING=$(conflicted | jq -R . | jq -sc .)
CUSTOM_JSON=$(printf '%s' "$CUSTOMIZED" | sed '/^$/d' | jq -R . | jq -sc .)
LEFTOVER_OLD=$(git ls-files "Puc/$OLD" | jq -R . | jq -sc .)
[ "$OLD" = "$NEW" ] && LEFTOVER_OLD='[]'

jq -nc --arg old "$OLD" --arg new "$NEW" --argjson took "$TOOK_THEIRS" --argjson req "$ADDED_REQUIRES" \
  --argjson remaining "$REMAINING" --argjson custom "$CUSTOM_JSON" --argjson leftover "$LEFTOVER_OLD" \
  '{status:(if ($remaining|length) == 0 then "resolved" else "needs_manual" end),
    old_version:$old, new_version:$new, took_upstream_side:$took, requires_readded:$req,
    customized_puc_files:$custom, still_conflicted:$remaining, leftover_old_version_files:$leftover}'
