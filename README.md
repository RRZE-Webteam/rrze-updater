[![Aktuelle Version](https://img.shields.io/github/package-json/v/rrze-webteam/rrze-updater/main?label=Version)](https://github.com/RRZE-Webteam/rrze-updater)
[![Release Version](https://img.shields.io/github/v/release/RRZE-Webteam/rrze-updater?label=Release+Version)](https://github.com/RRZE-Webteam/rrze-updater/releases/)
[![GitHub License](https://img.shields.io/github/license/RRZE-Webteam/rrze-updater)](https://github.com/RRZE-Webteam/rrze-updater)
[![GitHub issues](https://img.shields.io/github/issues/RRZE-Webteam/rrze-updater)](https://github.com/RRZE-Webteam/rrze-updater/issues)

# RRZE Updater

RRZE Updater is a WordPress plugin for synchronizing plugins and themes with the corresponding GitHub or GitLab repositories. RRZE Updater offers the following features:

- Installation of WordPress plugins from a GitHub or GitLab repository
- Installation of WordPress themes from a GitHub or GitLab repository
- Configurable selection of branch, repositories, and service
- Updating plugins via the plugin/theme overview when there are changes in the branch

## Contributors

* RRZE-Webteam, https://www.rrze.fau.de

## Copyright

GNU General Public License (GPL) Version 3

## Documentation

Public documentation at:

* https://www.wp.rrze.fau.de

## Feedback

* Issues und Feedback: https://github.com/RRZE-Webteam/rrze-updater/issues
* Kontakt: webmaster@rrze.fau.de

## Requirements

* WordPress ab 6.8
* PHP ab 8.3
* WordPress Single-Site or Multisite (repository browser and bundles require Multisite)


## Installation

- Download the current RRZE Updater repository as a .zip file.
- Log in to WordPress
- Navigate to Dashboard, then Plugins, then Install Plugin.
- Activate RRZE Updater from the list of installed plugins.

## Select a Service
To select a service, proceed as follows:

- Navigate to Dashboard, then Repositories, then Services.
- The service overview opens.
- Click "Add New".
- Select "GitHub" or "GitLab" from the dropdown for Service.
- Enter the name of the group/user. The group name is visible in the URL when opening the repository in the browser.
- Add a token for access if necessary.
- Confirm by clicking "Add New Service".

### Token expiration warnings

**Network Admin → Updater → Settings → Services** shows a token status for each
GitHub/GitLab service. Warnings start **30 days before expiration**. Expired
tokens and rejected authentication/access are marked as errors. Multisite super
administrators also see a warning in the top-level WordPress toolbar, including
on subsites and the frontend while the toolbar is visible. Its menu links to the
affected service editors. The overview shows whether a token is configured,
without displaying the credential.

The current network's main site checks tokens twice daily through WP-Cron,
independently of repository update checks. Opening the service overview refreshes
checks older than twelve hours (one hour after a temporary failure). The toolbar
only reads cached results. Background execution requires normal WP-Cron traffic
or a system cron. Replacing a token or changing its service configuration
invalidates its cached result; revisiting the overview checks the replacement.

GitLab uses `GET /personal_access_tokens/self`, relative to the configured API
base; date-only expiration values mean midnight UTC. GitHub uses `GET /user` and
the `GitHub-Authentication-Token-Expiration` response header. Dates are displayed
in the WordPress timezone. Token types, scopes or older servers that cannot
provide expiration metadata are shown as **unknown**, not as unlimited. Only an
explicit GitLab `expires_at: null` means no expiration date. An already expired
token may only return HTTP 401; without a previously known date, the plugin
reports rejection rather than guessing the cause. Permission failures and rate
limits are not treated as proof of expiration.

Network option `rrze_updater_token_status` stores timestamps, status and salted
credential/configuration fingerprints, never raw tokens or API response bodies.
Known expiration dates remain usable during API outages. Token metadata requests
use header authentication, TLS verification, a five-second timeout and no redirects.

## Add a Repository (Theme or Plugin)
The steps are the same for themes.

- Navigate to Dashboard, then Repositories, then Plugins.
- The plugin overview opens.
- Click "Install".
- Select the correct service.
- Enter the repository name used in GitLab or GitHub.
- Change the branch to the name of the desired branch.
- Choose whether to use commits or tags for checking for updates.
- (Optional) Specify a different plugin or theme folder.
- Confirm by clicking "Install Plugin".

An existing plugin directory is never replaced by the ordinary Install button.
After a directory conflict, **Replace folder and install** opens a separate
confirmation. For a directory already managed by Updater, open its existing
settings instead.

### Change a plugin's update source

Edit the plugin's service, repository, branch or update policy and save. Updater
validates the proposed source before saving; failed access or an invalid target
keeps the previous association. Saving does not replace files or change the
installation folder. When the source changes, the installed Git ref is treated
as unknown for that source, so even an identical tag name offers an update from
the newly selected repository. The version read from installed files remains
visible. Existing automatic-update settings continue to apply.

### Reinstall or repair plugin files

**Reinstall plugin files** on the plugin editor downloads the offered target for
the configured commits, tags or releases policy. It may therefore install a
newer version. Its confirmation shows the source, target folder, version and
pinned commit. A confirmation belongs to its administrator and installation,
expires after 15 minutes, and can be used only once. Changed source settings or
local files require a fresh review.

Replacement exchanges the **entire plugin directory**, removing leftover files
and local modifications. It does not call uninstall, activate or deactivate and
does not delete plugin settings from the database. Existing plugin main filenames
must remain present. Symlinks, unsafe paths and conflicting associations are
rejected. A mapped plugin with a remaining folder but no recognized entrypoint
stays visible as **Installation incomplete**, with repair available instead of a
normal update action. Completely removed directories are still removed from the
Updater registry during synchronization.

The archive is checked before the old directory is moved. Updater keeps a
temporary backup until both the new files and their repository association are
saved. A short WordPress maintenance window covers the exchange and recovery;
an existing maintenance file prevents a replacement from starting. Failed file
or settings operations restore the previous files. If that
restore also fails, the error reports the retained backup directory under
`wp-content/rrze-updater-recovery/` for manual recovery. Replacement requires the
PHP ZIP extension and filesystem paths that PHP can inspect locally.

These actions are available in the classic plugin administration to authorized
Single-Site administrators and Multisite superadministrators, subject to
WordPress installation/update capabilities and file-modification settings.
Themes, CLI, repository browser and bundles do not expose replacement actions.
Concurrent Updater file operations share a database advisory lock across networks.

GitHub ZIP downloads stream over HTTPS directly to a temporary file, avoiding a
complete archive in PHP memory. Certificate verification stays enabled. Archive
redirects are restricted to GitHub's API and codeload hosts, and the API token is
not sent to codeload. Failed or empty downloads remove the temporary file;
WordPress still validates the archive during installation.

## Check a Plugin or Theme for Updates
RRZE Updater automatically checks for new updates. To manually check for new updates, proceed as follows:

- Navigate to Dashboard, then Repositories, then Plugins/Themes.
- Select the desired repository from the overview via "Edit".
- Click "Check for Updates".
- The pending update will then appear in the WordPress plugin or theme overview.

The main **Updater** overview also offers **Check for updates** (**Auf Updates prüfen**)
for all managed repositories. **Stop process** lets the current repository request
finish and save its result, then stops issuing further requests. **Resume** continues
with the remaining repositories while the dialog stays open. **Close** reloads the
overview with the saved results; starting another manual check begins a new pass.

Scheduled checks run on each network's main site, processing one repository per
cron request and saving each result immediately. Remaining work is persisted in
the `rrze_updater_check_batch` option. The configured delay is applied between
requests, without keeping a PHP worker asleep. Continuations depend on normal
WordPress cron execution (site traffic or a system cron invoking WP-Cron), so the
delay is a minimum rather than an exact execution time.

An interrupted check or failed result save stays queued while other repositories
continue. After three unsuccessful attempts in one cycle, that repository is
reported through the RRZE error log and deferred to the next regular cycle.
Missing continuation events are repaired on the next main-site request. Scheduled
checks run independently of the overview's manual checks.

## Report Errors
Errors can be logged as issues in GitLab. Alternatively, issues and inquiries can be sent to webmaster@fau.de with the subject "RRZE Updater Plugin".

## WP-CLI

The active updater plugin provides these commands without requiring rrze-cli:

```sh
wp rrze-updater connector list
wp rrze-updater repo plugin register <repository> --connector=<id>
wp rrze-updater repo plugin install <repository> --connector=<id>
wp rrze-updater repo plugin list
wp rrze-updater repo plugin unregister <repository> [--connector=<id>]

wp rrze-updater repo theme register <repository> --connector=<id>
wp rrze-updater repo theme install <repository> --connector=<id>
wp rrze-updater repo theme list
wp rrze-updater repo theme unregister <repository> [--connector=<id>]

wp help rrze-updater repo plugin install
```

On Multisite the plugin must be network-active. Use WP-CLI's `--url` to select
the target network through one of its sites. Associations belong to the network,
not just the selected site.

Run `connector list` first and use its actual `id` value. Connectors are
configured through the existing service settings; names such as `github-rrze`
are not automatically created aliases. The list shows provider, host, owner and
whether credentials are configured, but never prints token values. All list
commands accept `--format=table|json|csv|yaml|count`.

### Register and install

`register` associates an already installed plugin/theme with a repository.
`install` downloads and installs it, then saves the association only after
WordPress confirms success. Neither command activates a plugin, enables a theme,
nor changes an existing activation. `unregister` removes only the association.

Both commands accept:

- `--connector=<id>` (required): an existing connector ID.
- `--folder=<name>`: installation folder, defaulting to the repository name.
- `--branch=<ref>`: branch for commit updates, defaulting to `main`.
- `--updates=tags|commits|releases`: update policy, defaulting to **tags**.
- `--only-release`: select release mode and persist it for
  future update checks. This flag conflicts with `--updates=tags|commits`.

Example (replace `a1b2c3d4` with the ID from your connector list):

```sh
wp rrze-updater repo plugin install rrze-notices --connector=a1b2c3d4 --only-release
wp rrze-updater repo plugin list --format=json
```

Tags and releases are selected across the repository; `--branch` does not filter
them. Release mode installs the **source archive at the release's tag**, not an
attached build asset. GitHub uses its latest published full release, excluding
drafts and prereleases. GitLab uses the newest release by `released_at`, excluding
future/upcoming releases; GitLab does not provide the same prerelease flag.
If no eligible tag or release exists, the command fails without falling back
to a branch.

Repeating a command with the same association and existing files succeeds
without reinstalling or duplicating the entry. Conflicting associations fail.
Existing folders are never overwritten by `install`; use `register` for them.
Missing README files and nonstandard plugin entry-file names or headers produce
warnings, matching the admin workflow. WordPress still validates downloaded plugin
packages before installation; access, ref lookup and installation failures remain
fatal. Warnings are printed by WP-CLI and stored with the repository association.

Registration cannot establish the installed Git ref from arbitrary local files.
It leaves that ref unknown, so the next update may replace those files with the
selected remote version. Installation records the ref it actually installed.

For a Multisite setup, install or register each desired extension, then activate
plugins explicitly with WordPress's own commands, for example:

```sh
wp plugin activate rrze-notices --network
```

### Development checks

From the WordPress root, run:

```sh
php wp-content/plugins/rrze-updater/tests/cli.php
```

The standalone suite uses the real repository manager, settings serialization,
release-selection methods, CLI adapter and WordPress hook dispatcher. Storage,
HTTP responses and the WordPress installer are fixtures. It tests successful and
failed registration/installation, repeated commands, conflicts, release policies,
credential-free lists and temporary-file/filter cleanup. It does not perform a
live database migration, fetch private repositories or extract a real ZIP.

To additionally validate every command synopsis with WP-CLI's own parser, pass
its PHAR (with a `.phar` extension) or its `php/` source directory:

```sh
php wp-content/plugins/rrze-updater/tests/cli.php /path/to/wp-cli.phar
```

This catches option-name errors that help rendering alone does not detect.
Use `--only-release`; WP-CLI does not accept the uppercase `--onlyRelease`
spelling as a valid synopsis flag.

## Recommended installation (Multisite)

Network administrators can open **Updater → Install repositories → Recommended bundle** to install
**RRZE Standard**, a predefined bundle of 71 plugins and 10 themes. The version 1
manifest is shipped in `includes/Bundles/standard.json`. Repository names, folder
case and branches follow the curated list; `rrze-notices` remains on GitLab.
Every entry uses commits from its configured `main` or `master` branch. Preflight
resolves the exact commit to install; tags and releases are not used by this bundle.

1. Configure connectors for `github.com / RRZE-Webteam` and
   `gitlab.rrze.fau.de / rrze-webteam` under **Settings → Services**. On the bundle
   page, select one GitHub connector and one GitLab connector for the process.
   Multiple compatible connectors are supported; a sole match is preselected.
   Both selected connectors require access tokens. Only the selected IDs are saved
   with the job; credentials remain in service settings. Resume and retry keep
   these IDs. Changing the selection requires new prerequisite checks.
2. Select **Review recommended bundle**. Each request checks one repository, verifies
   access, resolves its target ref and identifies installed files, conflicting
   associations and required parent themes. Missing or incompatible selected connectors,
   unavailable refs, missing parents and dependency cycles block the standard installation.
   After checks finish, **Install passing entries** can process the passing entries while
   skipping prerequisite errors and their dependents. It does not bypass validation
   or install entries that failed preflight.
3. Review the actions and refs, then select **Install repositories**. If any plugins
   or themes are already installed but unmanaged, a dialog lets you choose which
   ones to register. All checkboxes start unchecked. Leave Git-maintained installations
   unchecked to keep them outside Updater management; the remaining queue continues.
   Missing extensions are installed and registered; matching managed extensions
   are checked and skipped. Existing files are never
   overwritten. Plugins remain inactive unless already active; theme availability
   and the active theme are unchanged.
4. Keep the page open, or pause and resume later. Processing stops issuing requests
   when the page closes, although an in-flight request may still finish. Progress
   is stored per network. Reopening the page reads progress without automatically
   starting installation. Failed installations can be retried independently of successes. Entries skipped
   for prerequisite errors keep their original error messages; after fixing them,
   run prerequisite checks again before installing them.
   Registration choices are saved with the job and retained on resume/retry.
   Declined registrations appear as **Left unmanaged** and are not retried.
   An installed parent theme can remain unmanaged while satisfying a child theme's
   dependency. Start a new review to change registration choices.

Use **Cancel process** to stop checking or installing the remaining entries. A
request already in progress finishes first; completed installations and
registrations are kept. Cancellation is saved, so reopening the page does not
offer to resume that job. Select **Change selection / check again** to start a new
review. If a request was interrupted before its result could be saved, its entry
is marked **Interrupted — inspect files**; inspect the destination before retrying
in a new job. Cancellation is also available for discovered repository selections.

The installation uses the commit hashes reviewed during preflight. New commits
pushed while processing do not change those hashes; normal updater checks
subsequently continue tracking commits on each entry's configured branch. Initial installation must start within one
day of preflight. Bundled parent themes are processed before their children. A
parent outside the bundle must already be installed; it is not silently downloaded
from WordPress.org. A failed parent prevents dependent child installation, while
independent entries continue.

A connection-owned MySQL/MariaDB advisory lock serializes bundle requests across
networks sharing the installation. Stale requests from another tab are rejected.
The database user/server must support `GET_LOCK` and `RELEASE_LOCK`; failure to
acquire the lock prevents processing. The job is stored separately from updater
settings in the network option `rrze_updater_bundle_job`.

If a request installed files but failed before saving the repository association,
resuming reports the existing destination for inspection. It does not assume those
files match the reviewed ref. Once inspected, use repository registration to recover,
then retry the entry or rerun prerequisite checks. The bundle never activates code
or removes files as part of recovery.

## Browse and install repositories

Open **Network Admin → Updater → Install repositories → Browse repositories**.
Choose a GitHub or GitLab connector with an access token. The browser lists only
repositories belonging directly to its configured owner/namespace. GitLab shared
projects and descendants of the configured group are excluded; configure a
connector for a subgroup to browse it. Tokens stay on the server.

1. Search, filter, and select repositories in the DataViews table. Repositories
   default to most recently updated first, with an **Updated** column showing the
   provider timestamp. You can change the sort order in the table. The directory
   loads all API pages before offering search; selections persist across table
   pages and searches. Switching connector clears the selection. Use **Refresh
   repositories** to bypass the one-minute listing cache.
2. The **Installation branch** column shows the branch to install, initially the
   repository's default branch. Use **Change branch** in a row's actions to choose
   another branch, even before selecting the repository. The column also shows
   the default branch when it differs. Branch choices stay in sync with the
   selected-repository controls below, where you can also set installation folders.
   Branch lists load on demand, including additional API pages. Each job supports
   up to 100 repositories.
3. Select **Review selected repositories**. The server resolves each branch to an
   immutable commit and inspects the files at that commit. It recognizes a plugin
   by a `Plugin Name:` header in a root PHP file, regardless of filename, and a
   theme by `Theme Name:` in `style.css`. Parent themes need `index.php` or
   `templates/index.html`; child themes declare their parent with `Template:`.
   No readme or Git tags are required. Ambiguous/multiple extensions and nested
   extension packages are unsupported. Inspection is limited to 30 root PHP files
   and reads header metadata from the first 8 KiB, following WordPress conventions.
4. Review detected types, folders, conflicts, declared PHP/WordPress requirements,
   parent themes, and plugin dependency notices. Header checks establish package
   structure, not runtime correctness or completeness of build artifacts. The
   WordPress upgrader validates the downloaded package during installation.
5. Select **Install repositories** or **Install passing entries** when some checks
   failed. The recommended bundle and discovered selections share one saved job
   per network, with the same locking, pause/resume, recovery, and retry behavior.
   Use **Change selection / check again** to change branches or folders and create
   a new review. New reviews replace the previous saved job.

Discovered repositories always use **commit updates** on the selected branch.
Installation uses the reviewed commit even if new commits are pushed meanwhile.
Plugins are not activated; themes are not enabled or activated. Existing unmanaged
installations are registered only when explicitly checked in the confirmation dialog.
Unchecked installations remain unmanaged; existing files are never
silently overwritten. Plugin dependency headers are shown for activation planning;
the installer does not automatically fetch those plugins.

## Building and checking the installer

```sh
npm ci
npm run build:installer
npm run test:php
npm run test:js
```

`src/installer/` contains the React UI. `scripts/build-installer.mjs` builds the
committed `build/installer.js`, CSS, license notices, and PHP dependency manifest.
WordPress supplies React and the public WordPress packages. DataViews is pinned to
the WordPress 6.8 package generation and uses its `/wp` distribution to isolate
private implementation APIs. Transitive Babel runtime and UUID overrides apply
security fixes without raising the WordPress minimum. The normal `npm run build`
and release scripts also rebuild the installer.

The standalone PHP tests cover recommended bundles, discovered repositories,
GitHub/GitLab pagination and owner boundaries, package inspection, immutable refs,
retries, parent dependencies, authorization, network isolation, and file installation
through fixtures. They do not call real services or modify installed extensions.
The replacement suite additionally uses real temporary directories and ZIP files
to check complete replacement, permission failures, maintenance and rollback.

For a disposable WordPress installation backed by MySQL, add
`define('RRZE_UPDATER_TEST_INSTALLATION', true);` to that installation's
`wp-config.php`, then run the optional integration fixture:

```sh
wp eval-file /path/to/rrze-updater/tests/wordpress-repair.php /path/to/rrze-updater --path=/path/to/disposable-wordpress --skip-plugins --skip-themes --user=1
```

Run once on a Single-Site installation and once on a Multisite installation.
This fixture changes the test installation's Updater settings and creates test
plugins, so use an isolated database and content directory. It exercises real
WordPress options, activation state, filesystem operations and ZIP extraction;
GitHub/GitLab responses are supplied locally without external API access.

### Updating translations

Regenerate the translation template and merge it into the German catalogs with WP-CLI:

```sh
wp i18n make-pot . languages/rrze-updater.pot --exclude=node_modules,tests,build
wp i18n update-po languages/rrze-updater.pot languages
```

Translate the new entries in both `.po` files, then compile the PHP and JavaScript catalogs:

```sh
wp i18n make-mo languages
wp i18n make-json languages --no-purge --extensions=jsx --use-map=scripts/i18n-map.json
```

The map combines installer source strings into the catalog for `build/installer.js`.
Add new installer source files to this map when they introduce translatable strings.
Commit the POT, PO, MO, and JSON files together so installations receive the compiled translations.

### Continuous integration

`.github/workflows/tests.yml` runs on pull requests to any branch and pushes to
`dev`. It can also be started manually from GitHub's Actions tab once the workflow
is on the default branch. Each run tests WordPress 6.8 and the latest release with
PHP 8.3 and Node.js 24, running both test suites and building the installer.

Actions in this test workflow are pinned to full commit SHAs verified against their
official repositories. Update these pins deliberately when adopting newer releases;
the adjacent comments identify the corresponding major versions.

The workflow checks out this plugin inside a temporary WordPress directory and
downloads the core files needed by the PHP fixtures. No database or additional
repository secrets are needed. New commits cancel older runs for the same pull request
or branch.

Commit and push the workflow to enable the automatic runs. Results appear in the
pull request checks and the Actions tab. To make passing tests a merge requirement,
add `Tests (WordPress 6.8)` and `Tests (WordPress latest)` as required status checks
in a branch protection rule or ruleset after their first run.

`.github/workflows/plugin-version.yml` adds the `Plugin version increase` check
for pull requests targeting `main`, including pull requests retargeted to `main`.
It compares the `Version` header in `rrze-updater.php` at the pull request's head
with a fresh checkout of `main`. Equal, lower, missing, or malformed versions fail.
Comparison uses PHP's `version_compare`, matching WordPress version ordering;
plugin files are read as text and never loaded. The workflow uses pinned actions,
read-only repository permissions, and no persisted checkout credentials.

After its first run, require `Plugin version increase` in the protection rule for
`main` and enable **Require branches to be up to date before merging**. This makes
pull requests update and rerun their checks when another merge advances `main`,
so two pull requests cannot reuse the same version based on an older passing check.
