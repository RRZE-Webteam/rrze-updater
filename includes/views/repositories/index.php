<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;
?>
<style>
    .wp-list-table tr.rrze-updater-has-update {
        background: #fff8e5;
    }

    .wp-list-table tr.rrze-updater-has-update th,
    .wp-list-table tr.rrze-updater-has-update td {
        border-left-color: #dba617;
    }

    .wp-list-table .rrze-updater-update-link {
        font-weight: 600;
    }

    .rrze-updater-check-backdrop {
        align-items: center;
        background: rgba(0, 0, 0, 0.35);
        display: none;
        inset: 0;
        justify-content: center;
        position: fixed;
        z-index: 100000;
    }

    .rrze-updater-check-backdrop.is-active {
        display: flex;
    }

    .rrze-updater-check-dialog {
        background: #fff;
        border-radius: 4px;
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.22);
        max-height: 80vh;
        max-width: 760px;
        overflow: auto;
        padding: 20px;
        width: calc(100% - 40px);
    }

    .rrze-updater-check-dialog h2 {
        margin-top: 0;
    }

    .rrze-updater-check-list {
        display: grid;
        gap: 8px;
        margin: 16px 0;
    }

    .rrze-updater-check-item {
        align-items: center;
        background: #f6f7f7;
        border-radius: 4px;
        display: flex;
        gap: 12px;
        justify-content: space-between;
        margin: 0;
        padding: 10px 12px;
    }

    .rrze-updater-check-repository {
        min-width: 0;
    }

    .rrze-updater-check-status {
        align-items: center;
        display: inline-flex;
        flex: 0 0 auto;
        font-weight: 600;
        gap: 5px;
        white-space: nowrap;
    }

    .rrze-updater-check-status .dashicons {
        font-size: 18px;
        height: 18px;
        width: 18px;
    }

    .rrze-updater-check-status.is-current {
        color: #008a20;
    }

    .rrze-updater-check-status.is-update {
        color: #996800;
    }

    .rrze-updater-check-status.is-error {
        color: #b32d2e;
    }

    .rrze-updater-check-status.is-pending {
        color: #50575e;
    }

    .rrze-updater-check-actions {
        text-align: right;
    }

    .rrze-updater-check-actions [hidden] {
        display: none;
    }
</style>
<h2>
    <?php esc_html_e('Updater', 'rrze-updater'); ?>
    <button type="button" class="add-new-h2" id="rrze-updater-check-updates"><?php esc_html_e('Auf Updates prüfen', 'rrze-updater'); ?></button>
</h2>

<form method="get">
    <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>">
    <?php
    $data['listTable']->search_box(__('Search', 'rrze-updater'), 's');
    ?>
</form>

<form method="get" class="rrze-updater-bulk-action-form">
    <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
    <?php $data['listTable']->display(); ?>
</form>
<?php
$bulkDeleteConfirmTitle = __('Delete selected repositories?', 'rrze-updater');
$bulkDeleteConfirmMessage = __('This action deletes the selected repository definitions from RRZE Updater. Installed plugins or themes are not removed by this bulk action.', 'rrze-updater');
$bulkDeleteConfirmCheckboxLabel = __('I understand that the selected repository definitions will be deleted.', 'rrze-updater');
require __DIR__ . '/../partials/bulk-delete-confirm.php';
?>

<div class="rrze-updater-check-backdrop" id="rrze-updater-check-backdrop" role="dialog" aria-modal="true" aria-labelledby="rrze-updater-check-title">
    <div class="rrze-updater-check-dialog">
        <h2 id="rrze-updater-check-title"><?php esc_html_e('Auf Updates prüfen', 'rrze-updater'); ?></h2>
        <p id="rrze-updater-check-summary" role="status" aria-live="polite"></p>
        <div class="rrze-updater-check-list" id="rrze-updater-check-list"></div>
        <div class="rrze-updater-check-actions">
            <button type="button" class="button" id="rrze-updater-check-stop" hidden><?php esc_html_e('Stop process', 'rrze-updater'); ?></button>
            <button type="button" class="button button-primary" id="rrze-updater-check-resume" hidden><?php esc_html_e('Resume', 'rrze-updater'); ?></button>
            <button type="button" class="button" id="rrze-updater-check-close" disabled><?php esc_html_e('Schließen', 'rrze-updater'); ?></button>
        </div>
    </div>
</div>

<script>
    (function rrzeUpdaterRepositoryUpdateCheck() {
        var items = <?php echo wp_json_encode($data['updateCheckItems'] ?? []); ?>;
        var delaySeconds = Math.max(1, parseInt(<?php echo wp_json_encode((int) ($data['updateCheckDelay'] ?? 1)); ?>, 10) || 1);
        var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var nonce = <?php echo wp_json_encode($data['updateCheckNonce'] ?? ''); ?>;
        var startButton = document.getElementById('rrze-updater-check-updates');
        var backdrop = document.getElementById('rrze-updater-check-backdrop');
        var list = document.getElementById('rrze-updater-check-list');
        var summary = document.getElementById('rrze-updater-check-summary');
        var closeButton = document.getElementById('rrze-updater-check-close');
        var stopButton = document.getElementById('rrze-updater-check-stop');
        var resumeButton = document.getElementById('rrze-updater-check-resume');
        var runner = null;
        var hasRun = false;

        function getRepositoryText(item) {
            return item.name + ' (' + item.repository + ' / ' + item.branch + '):';
        }

        function getStatusIcon(statusType) {
            if (statusType === 'current') {
                return 'dashicons-yes-alt';
            }

            if (statusType === 'update') {
                return 'dashicons-update-alt';
            }

            if (statusType === 'error') {
                return 'dashicons-warning';
            }

            return 'dashicons-update';
        }

        function setListItemStatus(listItem, item, status, statusType) {
            var repository = listItem.querySelector('.rrze-updater-check-repository');
            var statusElement = listItem.querySelector('.rrze-updater-check-status');
            var icon = statusElement.querySelector('.dashicons');
            var text = statusElement.querySelector('.rrze-updater-check-status-text');

            repository.textContent = getRepositoryText(item);
            statusElement.className = 'rrze-updater-check-status is-' + statusType;
            icon.className = 'dashicons ' + getStatusIcon(statusType);
            text.textContent = status;
        }

        function getStatusType(response) {
            if (!response.success) {
                return 'error';
            }

            if (response.data && response.data.isError) {
                return 'error';
            }

            if (response.data && response.data.hasUpdate) {
                return 'update';
            }

            return 'current';
        }

        function setSummary(text) {
            summary.textContent = text;
        }

        function updateProgress(progress) {
            var busy = progress.status === 'running' || progress.status === 'stopping';
            startButton.disabled = busy || progress.status === 'stopped';
            closeButton.disabled = busy;
            stopButton.hidden = !busy;
            stopButton.disabled = progress.status === 'stopping';
            resumeButton.hidden = progress.status !== 'stopped';

            if (progress.status === 'stopping') {
                setSummary(<?php echo wp_json_encode(__('Stopping after the current repository check finishes…', 'rrze-updater')); ?>);
            } else if (progress.status === 'stopped') {
                setSummary(<?php
                    /* translators: 1: Number of repositories checked, 2: Total number of repositories. */
                    echo wp_json_encode(__('Process stopped. %1$d of %2$d repositories checked. Resume continues with the remaining repositories.', 'rrze-updater'));
                ?>
                    .replace('%1$d', progress.completed).replace('%2$d', progress.total));
                resumeButton.focus();
            } else if (progress.status === 'complete') {
                setSummary(progress.total
                    ? <?php echo wp_json_encode(__('Prüfung abgeschlossen. Schließen lädt die Übersicht neu.', 'rrze-updater')); ?>
                    : <?php echo wp_json_encode(__('Keine Repositories vorhanden.', 'rrze-updater')); ?>);
                closeButton.focus();
            } else {
                setSummary((progress.completed + 1) + ' / ' + progress.total);
            }
        }

        function openDialog() {
            backdrop.classList.add('is-active');
        }

        function closeDialog() {
            backdrop.classList.remove('is-active');

            if (hasRun) {
                window.location.reload();
            }
        }

        function createListItem(item) {
            var listItem = document.createElement('div');
            var repository = document.createElement('span');
            var status = document.createElement('span');
            var icon = document.createElement('span');
            var statusText = document.createElement('span');

            listItem.className = 'rrze-updater-check-item';
            repository.className = 'rrze-updater-check-repository';
            status.className = 'rrze-updater-check-status is-pending';
            icon.className = 'dashicons dashicons-update';
            icon.setAttribute('aria-hidden', 'true');
            statusText.className = 'rrze-updater-check-status-text';

            status.appendChild(icon);
            status.appendChild(statusText);
            listItem.appendChild(repository);
            listItem.appendChild(status);
            list.appendChild(listItem);
            setListItemStatus(listItem, item, <?php echo wp_json_encode(__('Prüfe...', 'rrze-updater')); ?>, 'pending');

            return listItem;
        }

        function buildRequestBody(item) {
            var body = new URLSearchParams();

            body.append('action', 'rrze_updater_check_repository_update');
            body.append('nonce', nonce);
            body.append('id', item.id);
            body.append('type', item.type);

            return body;
        }

        function handleResponse(item, listItem, response) {
            if (response.success && response.data) {
                setListItemStatus(listItem, response.data, response.data.status, getStatusType(response));
                return;
            }

            if (response.data && response.data.message) {
                setListItemStatus(listItem, item, response.data.message, 'error');
                return;
            }

            setListItemStatus(listItem, item, <?php echo wp_json_encode(__('Fehler bei der Prüfung', 'rrze-updater')); ?>, 'error');
        }

        function checkItem(index) {
            var item = items[index];
            var listItem = createListItem(item);
            setSummary((index + 1) + ' / ' + items.length);

            return fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: buildRequestBody(item)
            })
                .then(parseJsonResponse)
                .then(function handleJson(response) {
                    handleResponse(item, listItem, response);
                })
                .catch(function handleError() {
                    setListItemStatus(listItem, item, <?php echo wp_json_encode(__('Fehler bei der Prüfung', 'rrze-updater')); ?>, 'error');
                });
        }

        function parseJsonResponse(response) {
            return response.json();
        }

        function startCheck() {
            hasRun = true;
            list.innerHTML = '';
            openDialog();

            runner = window.rrzeUpdaterCreateCheckRunner({
                total: items.length,
                delay: delaySeconds * 1000,
                check: checkItem,
                onChange: updateProgress
            });
            runner.resume();
            if (items.length) stopButton.focus();
        }

        if (!startButton || !backdrop || !list || !summary || !closeButton || !stopButton || !resumeButton) {
            return;
        }

        startButton.addEventListener('click', startCheck);
        closeButton.addEventListener('click', closeDialog);
        stopButton.addEventListener('click', function () { runner.stop(); });
        resumeButton.addEventListener('click', function () { runner.resume(); stopButton.focus(); });
    }());
</script>
