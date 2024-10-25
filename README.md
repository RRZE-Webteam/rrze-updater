<!-- BEGIN PLUGIN DATA -->
<!-- END PLUGIN DATA -->

[![Release Version](https://img.shields.io/github/v/release/RRZE-Webteam/rrze-updater?label=Release+Version)](https://github.com/RRZE-Webteam/rrze-updater/releases/)
[![GitHub License](https://img.shields.io/github/license/RRZE-Webteam/rrze-updater)](https://github.com/RRZE-Webteam/rrze-updater)
[![GitHub issues](https://img.shields.io/github/issues/RRZE-Webteam/rrze-updater)](https://github.com/RRZE-Webteam/rrze-updater/issues)

# RRZE Updater

RRZE Updater is a WordPress plugin for synchronizing GitHub and GitLab repositories. RRZE Updater offers the following features:

- Installation of WordPress plugins from a GitHub or GitLab repository
- Installation of WordPress themes from a GitHub or GitLab repository
- Configurable selection of branch, repositories, and service
- Updating plugins via the plugin/theme overview when there are changes in the branch


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

## License
GNU GENERAL PUBLIC LICENSE Version 2.
