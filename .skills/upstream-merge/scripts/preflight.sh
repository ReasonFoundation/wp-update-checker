#!/usr/bin/env bash
# preflight.sh - fetch upstream plugin-update-checker and dry-run the merge.
# Usage: .skills/upstream-merge/scripts/preflight.sh [--ref <upstream-ref>]
# Output: one JSON object on stdout. Touches nothing but refs/remotes/upstream/*.

set -euo pipefail

UPSTREAM_URL="https://github.com/YahnisElsts/plugin-update-checker.git"
REF="master"
while [ $# -gt 0 ]; do
  case "$1" in
    --ref) REF="$2"; shift 2 ;;
    *) jq -nc --arg f "$1" '{status:"error",message:("unknown flag: " + $f)}'; exit 1 ;;
  esac
done

cd "$(git rev-parse --show-toplevel)"

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  jq -nc '{status:"error",message:"working tree has uncommitted changes to tracked files; commit or stash first"}'
  exit 1
fi

# Abort if the transfer stalls below 1 KB/s for 30s (macOS has no `timeout`).
git -c http.lowSpeedLimit=1000 -c http.lowSpeedTime=30 fetch --quiet --no-tags "$UPSTREAM_URL" "+refs/heads/$REF:refs/remotes/upstream/$REF"
UP="refs/remotes/upstream/$REF"
BASE=$(git merge-base HEAD "$UP")

# The versioned loader (load-v5p7.php etc.) names the minor version on each side.
loader_on() { git ls-tree --name-only "$1" | grep -E '^load-v[0-9]+p[0-9]+\.php$' | head -1; }
OURS_LOADER=$(loader_on HEAD)
THEIRS_LOADER=$(loader_on "$UP")
OURS_VER=${OURS_LOADER#load-}; OURS_VER=${OURS_VER%.php}
THEIRS_VER=${THEIRS_LOADER#load-}; THEIRS_VER=${THEIRS_VER%.php}

COMMITS=$(git log --format='%h %s' "$BASE..$UP" | jq -R . | jq -sc .)
BEHIND=$(git rev-list --count "$BASE..$UP")

# git merge-tree exits 1 when there are conflicts; that's expected, not a failure.
set +e
MT=$(git merge-tree --write-tree --name-only HEAD "$UP" 2>/dev/null)
MT_STATUS=$?
set -e
# Output: tree id, then conflicted paths, then a blank line, then messages.
CONFLICTS=$(printf '%s\n' "$MT" | sed -n '2,/^$/p' | sed '/^$/d')

classify() {
  case "$1" in
    Puc/*|load-v*.php) echo mechanical ;;
    README.md|composer.json) echo semi-mechanical ;;
    *) echo manual ;;
  esac
}
CONFLICT_JSON=$(printf '%s\n' "$CONFLICTS" | sed '/^$/d' | while read -r f; do
  jq -nc --arg p "$f" --arg k "$(classify "$f")" '{path:$p,kind:$k}'
done | jq -sc .)

# Upstream files that changed beyond the version bump (-I hides lines whose only change
# is a vNpM version string) -- the ones worth reading for behavior changes.
CHANGED=$(git diff --numstat -M -I 'v[0-9]+p[0-9]+' "$BASE" "$UP" | awk -F'\t' '$1+$2 > 0' \
  | jq -R 'split("\t") | {path:.[2], added:(.[0]|tonumber), removed:(.[1]|tonumber)}' | jq -sc .)

jq -nc \
  --arg up "$UP" --arg base "$BASE" --argjson behind "$BEHIND" \
  --arg ov "$OURS_VER" --arg tv "$THEIRS_VER" \
  --argjson clean "$([ "$MT_STATUS" -eq 0 ] && echo true || echo false)" \
  --argjson conflicts "$CONFLICT_JSON" --argjson commits "$COMMITS" --argjson changed "$CHANGED" \
  '{status:"ok", upstream_ref:$up, merge_base:$base, commits_behind:$behind,
    ours_version:$ov, theirs_version:$tv, version_bump:($ov != $tv),
    merges_cleanly:$clean, conflicts:$conflicts, upstream_commits:$commits,
    upstream_files_with_real_changes:$changed}'
