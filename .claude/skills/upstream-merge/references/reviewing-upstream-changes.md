# Reviewing upstream changes for Reason impact

Load this at step 4 of the upstream-merge workflow. For each file in preflight's `upstream_files_with_real_changes`, read the diff, for example `git diff <merge_base> refs/remotes/upstream/master -M -- <path>`. Ask the questions below. Most upstream changes affect only GitHub, GitLab or BitBucket updates, which Reason plugins don't use. The ones that matter touch the JSON-metadata path, HTTP requests, or defaults.

## How Reason uses this library

- Plugins call `ReasonUpdates::build($slug, __FILE__)` (in `reason-updates.php`). That builds `https://packages.reason.com/<slug>/?action=get_metadata` and passes it to `v5\PucFactory::buildUpdateChecker($url, $fullPath, $slug, $checkPeriod, $optionName, $muPluginFile)`. So everything runs through `Puc/v5pN/Plugin/UpdateChecker.php` and the JSON metadata classes, not `Vcs/`.
- `reason-packages-auth.php` registers a global `http_request_args` filter. It adds `Authorization: Bearer <REASON_PACKAGES_UPDATES_KEY>` to HTTPS metadata requests for packages.reason.com and the `REASON_PACKAGES_URL` host. It skips download requests and any request that already has an `Authorization` header.
- Several plugins on one site bundle their own copy. Whichever copy loads first supplies the shared classes and functions.

## Questions to ask

1. **Does it change `buildUpdateChecker()` or the constructors' argument order or meaning?** `ReasonUpdates::build()` passes arguments by position. A reordering would break every Reason plugin quietly.
2. **Does it touch HTTP requests?** Look for `http_request_args`, `wp_remote_get`, `Authorization`, or redirect handling. Upstream code that sets its own `Authorization` header on a Reason host would stop our key from being added, because our filter backs off when the header is present. Upstream code that strips headers on redirect could interact with our download exclusion.
3. **Does it change a default?** Examples: check period, the `autoupdate` field (5.7 forces it to `false` unless `allowAutoupdateField()` is called), whether updates are injected, or caching. packages.reason.com doesn't send `autoupdate` today, and Reason has chosen to keep upstream's safe default and document the opt-in in the README.
4. **Does it add or rename a filter or action?** New hooks are `puc_<name>-<slug>`, or `puc_<name>_theme-<slug>` for themes. The README's Reason sections should mention any that are useful to Reason sites.
5. **Does it change the JSON metadata format or parsing?** See `Metadata.php`, `Plugin/PluginInfo.php`, `Plugin/Update.php`, and `Update.php`. packages.reason.com produces this JSON, so a new required field or a changed meaning is a server-side task too.
6. **Does it raise the minimum PHP or WordPress version?** Check `composer.json`'s `require` and any new syntax. Reason sites must meet it.
7. **Does it change the autoloader or loader?** Check `Autoloader.php` and `load-v5pN.php`. `resolve.sh` re-adds our `require_once` lines after `new Autoloader();`. If upstream restructured that spot, confirm the lines landed somewhere sensible and still run before the factory registration.
8. **Does it add vendored libraries?** A new file in `vendor/` needs an entry in the autoloader's static map. Confirm `tests/load-test.php` still covers it, or extend the test.

## What to write down

For the PR body, list each change that answered "yes" above: what changed, whether it affects Reason, and what was done (a code fix, a README update, a test, or "no action, because ..."). If nothing qualified, say so in one line. That line is still useful to reviewers.
