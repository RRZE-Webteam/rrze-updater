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
* WordPress Multisite


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
- (Optional) Choose whether existing plugin files should be overwritten.
- Confirm by clicking "Install Plugin".

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
Browser validation and a real installation should be tested on staging.
