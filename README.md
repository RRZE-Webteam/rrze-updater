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
