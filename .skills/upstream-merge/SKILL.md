---
name: upstream-merge
description: Merge new releases of YahnisElsts/plugin-update-checker (the upstream project) into Reason's fork, reason-dev/wp-update-checker, keeping the ReasonDev namespace and the packages.reason.com additions (reason-packages-auth.php, reason-updates.php). Use when someone says "merge upstream", "pull in upstream changes", "update from upstream master", "sync with YahnisElsts", "upstream released a new version", "the upstream PR has merge conflicts", "bump to 5.8 / v5p8", or opens a PR from YahnisElsts:master. Handles the Puc/v5pN -> Puc/v5pM folder rename, the namespace conflicts it causes in every file, the loader and composer.json, and verification. Do NOT use it for changes to Reason's own update-key or ReasonUpdates code, for releasing a Reason plugin that bundles this library (that's wp-plugin-version-bump / wp-plugin-publishing), or for merging anything other than upstream plugin-update-checker.
metadata: { "openclaw": { "emoji": "🔀", "always": false } }
---

# Upstream merge

## Purpose

This repo is a fork of [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker). Two things make every upstream merge conflict, even when nobody changed the same code:

- **We renamed the namespace.** Every class is `ReasonDev\PluginUpdateChecker\...` instead of `YahnisElsts\PluginUpdateChecker\...`, which edits the top lines of every file.
- **Upstream versions its folder.** Each minor release renames `Puc/v5p6/` to `Puc/v5p7/` and `load-v5p6.php` to `load-v5p7.php`, and changes those same top lines.

The correct resolution is almost always "upstream's file, with our namespace". The scripts do that part. The agent reads what upstream actually changed and decides whether it affects Reason's plugins.

## When to use this skill

- Someone asks to merge, sync, or pull in upstream changes, or says upstream released a new version.
- A PR from `YahnisElsts:master` into this repo shows merge conflicts. That PR can't be fixed in place, because its branch belongs to upstream. This skill resolves the merge on a new branch, and the new PR closes the old one.

Not for: editing `reason-packages-auth.php` or `reason-updates.php` on their own; releasing plugins that bundle this library; merging from any other remote.

## Requirements

`git` 2.38 or later (for `git merge-tree --write-tree`), `php`, `jq`, `perl`, `gh`. Run the scripts from anywhere inside the repo.

## Workflow

1. **Preflight.** From a clean `master`:
   ```bash
   .skills/upstream-merge/scripts/preflight.sh
   ```
   It fetches upstream into `refs/remotes/upstream/master` and dry-runs the merge without touching the working tree. Read these fields:
   - `ours_version` / `theirs_version`: the folder rename, if any (for example `v5p6` → `v5p7`).
   - `conflicts[]`: each has a `kind`. `mechanical` (in `Puc/`, the loader) and `semi-mechanical` (`README.md`, `composer.json`) are what `resolve.sh` handles. Anything `manual` needs a person.
   - `upstream_files_with_real_changes[]`: files upstream changed beyond version strings. These are what step 4 reviews.
   - `commits_behind: 0` means there is nothing to merge. Stop and say so.

2. **Branch and merge.**
   ```bash
   git switch -c merge/upstream-<theirs_version> && git merge refs/remotes/upstream/master
   ```
   A stop with conflicts is expected.

3. **Resolve the mechanical conflicts.**
   ```bash
   .skills/upstream-merge/scripts/resolve.sh
   ```
   It stages what it resolves and never commits. If `status` is `needs_manual`:
   - `customized_puc_files[]` lists `Puc/` files that carry a Reason change beyond the namespace. The script refuses to take upstream's side of those. Merge them by hand: keep upstream's code, re-apply our change, and use `ReasonDev` in the namespace.
   - `still_conflicted[]` is everything else left, usually a README hunk where both sides changed real text. Resolve these by hand.
   - `leftover_old_version_files[]` should be empty. If not, a file exists only on our side under the old folder. Move it to the new folder or delete it, as appropriate.

4. **Review what upstream changed.** This is the judgment step. Read the diff of each file in `upstream_files_with_real_changes` and the upstream commit messages. Check them against `references/reviewing-upstream-changes.md`. Decide whether any change affects Reason's plugins, the update key, or `ReasonUpdates::build()`. Fix code or docs where needed, for example the README's "Reason packages" sections.

5. **Verify.**
   ```bash
   .skills/upstream-merge/scripts/verify.sh
   ```
   `ok: true` is required before committing. The checks:
   - no conflict markers
   - `php -l` is clean
   - every `tests/*-test.php` passes
   - no stray `YahnisElsts\` namespace or other-minor-version references remain
   - every upstream-owned file matches upstream's copy exactly once the namespace and our `require_once` lines are set aside (`differs_from_upstream` must be empty)

   Add a test under `tests/` for any Reason-relevant behavior found in step 4.

6. **Commit and open the PR.** Use a merge commit message that names the upstream version, lists what upstream brought in, and records any Reason-side fixes. Push the branch and open a PR against `master`. The body should:
   - summarize upstream's changes
   - flag any Reason impact from step 4
   - paste the `verify.sh` result
   - say `Closes #<n>` if an upstream PR is open
   - end with a reminder that Reason plugins only pick this up when they bump their bundled copy

## Output

Report back briefly: the version merged, what upstream brought in, the Reason-relevant findings from step 4 and what was done about them, the `verify.sh` result, and the PR link. Don't narrate each script run.

## Reference files

- `references/reviewing-upstream-changes.md`: load at step 4. It lists what in upstream's changes can affect Reason, and why.

## Pitfalls

- **Don't take upstream's side of whole files outside `Puc/` and the loader.** `README.md` and `composer.json` hold Reason content. `resolve.sh` resolves those per hunk, only where it's safe.
- **Grep for the namespace with both backslash forms.** `Autoloader::DEFAULT_NS_PREFIX` spells it `'YahnisElsts\\PluginUpdateChecker\\'` inside a string. The first fork rename missed it, so the bundled `Parsedown` and `PucReadmeParser` silently stopped autoloading. `tests/load-test.php` now catches this.
- **Don't edit the translation files' `#: Puc/v5pN/...` comments.** Upstream leaves them stale too, and `verify.sh` ignores them on purpose.
- **Don't rewrite `docs/`.** Those are dated design notes. Old version numbers in them are historical.
- **The version bump changes Composer's file ID.** Composer runs each package's `files` entry once per site, keyed by package name plus path. A new loader filename means plugins bundling different minor versions each run their own loader. Keep the docblock in `reason-updates.php` and the README's Composer paragraph accurate about this.
