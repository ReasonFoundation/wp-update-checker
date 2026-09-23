#!/usr/bin/env bash
# verify.sh - check a finished upstream merge before committing or opening a PR.
# Usage: .skills/upstream-merge/scripts/verify.sh [--against <upstream-ref>]
#   Default ref: refs/remotes/upstream/master (what preflight.sh fetched).
# Output: one JSON object on stdout; "ok" is true only if every check passed.
# Exit status: 0 when ok, 1 otherwise.

set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

UP="refs/remotes/upstream/master"
while [ $# -gt 0 ]; do
  case "$1" in
    --against) UP="$2"; shift 2 ;;
    *) jq -nc --arg f "$1" '{status:"error",message:("unknown flag: " + $f)}'; exit 1 ;;
  esac
done

LOADER=$(ls load-v*p*.php | head -1)
VER=${LOADER#load-}; VER=${VER%.php}
MAJOR=${VER%%p*}
to_json() { sed '/^$/d' | jq -R . | jq -sc .; }

# 1. Conflict markers in any tracked file.
MARKERS=$(git grep -lE '^(<<<<<<<|>>>>>>>)( |$)' -- . || true)

# 2. PHP syntax.
LINT=$(git ls-files -z '*.php' | xargs -0 -n1 php -l 2>&1 | grep -v '^No syntax errors' || true)

# 3. Tests: every tests/*-test.php prints ALL PASSED and exits 0.
TESTS="[]"
for t in tests/*-test.php; do
  if out=$(php "$t" 2>&1); then pass=true; else pass=false; fi
  fails=$(printf '%s\n' "$out" | { grep -E '^FAIL ' || true; } | jq -R . | jq -sc .)
  TESTS=$(jq -c --arg t "$t" --argjson p "$pass" --argjson f "$fails" '. + [{test:$t,passed:$p,failures:$f}]' <<<"$TESTS")
done

# 4. Leftovers: the upstream namespace (links to upstream's GitHub are fine) and
#    references to other minor versions of this major. Translation files, dated
#    notes in docs/ and this skill are excluded: upstream leaves stale paths in its
#    .po comments, and the notes are historical records. tests/load-test.php names
#    the upstream namespace on purpose, to assert no class uses it.
EXCLUDE=(':!languages/*.po' ':!docs' ':!.skills' ':!tests/load-test.php')
NS_LEFT=$(git grep -nI 'YahnisElsts\\' -- . "${EXCLUDE[@]}" || true)
VER_LEFT=$(git grep -nIE "${MAJOR}p[0-9]+" -- . "${EXCLUDE[@]}" | grep -vE "${VER}([^0-9]|$)" || true)

# 5. Parity with upstream: after undoing the namespace rename and dropping our
#    require_once lines (and blank lines), each upstream-owned file must match.
normalize() {
  perl -pe 's/ReasonDev(\\+)PluginUpdateChecker/YahnisElsts$1PluginUpdateChecker/g' \
    | grep -vE "^require_once __DIR__ \. '/reason-" | grep -v '^[[:space:]]*$' || true
}
DRIFT=""
for f in $(git ls-tree -r --name-only "$UP" -- Puc "$LOADER" plugin-update-checker.php); do
  if [ ! -f "$f" ]; then DRIFT="$DRIFT$f (missing here)"$'\n'; continue; fi
  if [ "$(normalize <"$f")" != "$(git show "$UP:$f" | normalize)" ]; then DRIFT="$DRIFT$f"$'\n'; fi
done
EXTRA=$(comm -23 <(git ls-files Puc | sort) <(git ls-tree -r --name-only "$UP" -- Puc | sort))

REPORT=$(jq -nc \
  --arg ver "$VER" --arg up "$UP" \
  --argjson markers "$(printf '%s' "$MARKERS" | to_json)" \
  --argjson lint "$(printf '%s' "$LINT" | to_json)" \
  --argjson tests "$TESTS" \
  --argjson ns "$(printf '%s' "$NS_LEFT" | to_json)" \
  --argjson vl "$(printf '%s' "$VER_LEFT" | to_json)" \
  --argjson drift "$(printf '%s' "$DRIFT" | to_json)" \
  --argjson extra "$(printf '%s' "$EXTRA" | to_json)" \
  '{version:$ver, compared_against:$up,
    conflict_markers:$markers, lint_errors:$lint, tests:$tests,
    upstream_namespace_left:$ns, other_version_refs:$vl,
    differs_from_upstream:$drift, puc_files_not_in_upstream:$extra}
   | .ok = ((.conflict_markers + .lint_errors + .upstream_namespace_left + .other_version_refs
             + .differs_from_upstream + .puc_files_not_in_upstream | length) == 0
            and all(.tests[]; .passed))')
printf '%s\n' "$REPORT"
jq -e .ok <<<"$REPORT" >/dev/null
