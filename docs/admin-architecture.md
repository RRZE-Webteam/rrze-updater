# Classical administration architecture

The classical administration separates HTTP handling, repository operations,
installed-file inspection, presentation and WordPress update execution. Its URLs,
nonces, capabilities and stored data retain their existing contracts.

## Entry points and dependency direction

`Controller` is the compatibility facade used by WordPress menu and action hooks.
It delegates to `Admin\RepositoryController`, `Admin\PluginController`,
`Admin\ThemeController` and `Admin\SettingsController`. The facade registers the
existing AJAX and theme-check hooks once; the page components do not register
hooks in their constructors.

The page controllers receive concrete dependencies through their constructors:

- `RepositoryActions` performs checks, persistence and removal of associations.
  Errors are returned as `WP_Error`; callers decide how to present them. Failed
  writes reload the shared `Settings` instance in place.
- `SettingsSynchronizer` reconciles associations with the installed extensions.
  Its entire read/prune/save operation holds `FilesystemLock`. A remaining plugin
  directory retains its association even when WordPress no longer recognizes a
  main file. Cron and `UpdateCheckBatch` use this service without a UI controller.
- `InstalledExtensions` reads local names, versions and installation state.
  Admin and email version lookup retain separate policies: email can inspect
  additional main files when the first has no version, and uses `n/a` for unknown
  versions. The administration keeps its existing package fallback and em dash.
- `Admin\RepositoryPresenter` prepares rows and editor data. `Admin\AdminLinks`
  resolves administration and optional tool links. Generated maintenance HTML is
  excluded from searchable row values.
- `Admin\AdminRenderer` receives explicit view data and messages. Templates only
  render output; they do not persist settings or execute updates.

The facade shares one `Admin\AdminMessages` collector across its page components
so that a public mutation followed by a different page still displays the same
request's notices. This collector contains only notices, not service lookup or
request handling. Direct component tests may inject their own collector.

`RepositoryManager` remains responsible for installation and plugin source
changes. `Core\PluginReplacement` and `Upgrader\PluginPackageReplacement` retain
review validation, package checks, file replacement and recovery. The refactor
does not introduce another installer or alter their transaction boundaries.

## Lists and progress output

The page controller processes a selected action before preparing a list table.
Tables receive rows and presentation options, and only sort, paginate and render
them. Preparing or rendering a table must never write settings, install files or
start an updater.

`Admin\RepositoryUpdateRunner` selects eligible updates, prepares WordPress's
update caches and streams the existing progress sections around each
`Plugin_Upgrader::upgrade()` or `Theme_Upgrader::upgrade()` call. It deliberately
does not use `bulk_upgrade()`: that would change maintenance-mode behavior.
Incomplete plugins are excluded and still require the explicit repair flow.

## Compatibility and tests

The public facade, menu slugs, hook names and priorities, request field names,
nonce actions, AJAX response fields and messages remain stable. No migration or
new settings are required. Theme source editing retains its existing semantics;
it is not silently changed to use the stricter plugin source-switch workflow.

Tests target the public APIs of the extracted components and inject recording
renderers or service fixtures instead of inheriting from the facade or reflecting
on its private methods. In addition to the existing regression suites, cover:

- File selection, version/name fallbacks and the different email lookup policy.
- Optional tool availability, generated URLs and theme-check form submission.
- Nonces, capabilities, confirmation requirements and unsuccessful persistence.
- Side-effect-free tables and sequential, streamed update output.
- Cron recovery and synchronization under the shared filesystem lock.

Run `npm run test:php` and `npm run test:js`. The optional real-WordPress repair
and maintenance suites documented in the README must only run on explicitly
marked disposable installations.

## Follow-up audit findings

These are separate changes rather than prerequisites of the controller refactor:

1. Split `AdminIntegration` by menu integration, token presentation and native
   update-row presentation. Preserve presentation-before-metadata hook ordering.
2. Extract named dispatch steps from `BundleManager` while preserving job
   revisions, cancellation and parent dependency ordering.
3. Isolate the pure merge/validation stages in `Settings::save()`. Preserve the
   local merge baseline and filesystem-before-settings lock ordering.
4. Simplify the completion callbacks in `ManagedUpgrader` and named phases in
   the replacement services, with dedicated rollback and post-write-exception
   tests. Keep their coherent transactions together.
5. Share the identical branch-check portion of plugin/theme validation without
   changing their public methods or type-specific error messages.
6. Fix plugin-row association matching in `AdminIntegration::pluginRowMeta()`:
   its prefix comparison can match `foobar` to a managed `foo` folder.
7. Review list sorting identifiers separately: `sanitize_key()` lowercases
   camelCase names such as `installationFolder` and `serviceOwner`, which can
   cause the existing sorter to fall back to its default field.

Provider HTTP code must not be unified solely because it looks similar: GitHub
downloads, repository discovery and connector API calls have deliberately
different cache, redirect, credential and error-handling rules. Small cohesive
classes such as `FilesystemLock`, `RepositoryInstaller`, `Config`, and
`MaintenanceNotice` do not need splitting merely to reduce their line counts.
