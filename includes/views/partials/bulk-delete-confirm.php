<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

$bulkDeleteConfirmTitle = $bulkDeleteConfirmTitle ?? __('Delete selected repositories?', 'rrze-updater');
$bulkDeleteConfirmMessage = $bulkDeleteConfirmMessage ?? __('This action removes the selected repository definitions from RRZE Updater.', 'rrze-updater');
$bulkDeleteConfirmCheckboxLabel = $bulkDeleteConfirmCheckboxLabel ?? __('I understand that the selected entries will be deleted.', 'rrze-updater');
?>
<style>
    .rrze-updater-bulk-delete-backdrop {
        align-items: center;
        background: rgba(0, 0, 0, 0.35);
        bottom: 0;
        display: none;
        justify-content: center;
        left: 0;
        position: fixed;
        right: 0;
        top: 0;
        z-index: 100000;
    }

    .rrze-updater-bulk-delete-backdrop.is-active {
        display: flex;
    }

    .rrze-updater-bulk-delete-dialog {
        background: #fff;
        border: 1px solid #c3c4c7;
        box-shadow: 0 8px 28px rgba(0, 0, 0, 0.2);
        max-width: 460px;
        padding: 20px;
        width: calc(100% - 40px);
    }

    .rrze-updater-bulk-delete-dialog h2 {
        margin-top: 0;
    }

    .rrze-updater-bulk-delete-actions {
        display: flex;
        gap: 8px;
        justify-content: flex-end;
        margin-top: 18px;
    }
</style>
<div class="rrze-updater-bulk-delete-backdrop" id="rrze-updater-bulk-delete-backdrop" role="dialog" aria-modal="true" aria-labelledby="rrze-updater-bulk-delete-title">
    <div class="rrze-updater-bulk-delete-dialog">
        <h2 id="rrze-updater-bulk-delete-title"><?php echo esc_html($bulkDeleteConfirmTitle); ?></h2>
        <p><?php echo esc_html($bulkDeleteConfirmMessage); ?></p>
        <label>
            <input type="checkbox" id="rrze-updater-bulk-delete-checkbox">
            <?php echo esc_html($bulkDeleteConfirmCheckboxLabel); ?>
        </label>
        <div class="rrze-updater-bulk-delete-actions">
            <button type="button" class="button" id="rrze-updater-bulk-delete-cancel"><?php esc_html_e('Cancel', 'rrze-updater'); ?></button>
            <button type="button" class="button button-primary" id="rrze-updater-bulk-delete-confirm" disabled><?php esc_html_e('Delete', 'rrze-updater'); ?></button>
        </div>
    </div>
</div>
<script>
    (function rrzeUpdaterBulkDeleteConfirm() {
        var pendingForm = null;
        var backdrop = document.getElementById('rrze-updater-bulk-delete-backdrop');
        var checkbox = document.getElementById('rrze-updater-bulk-delete-checkbox');
        var confirmButton = document.getElementById('rrze-updater-bulk-delete-confirm');
        var cancelButton = document.getElementById('rrze-updater-bulk-delete-cancel');

        function getSelectedBulkAction(form) {
            var topAction = form.querySelector('select[name="action"]');
            var bottomAction = form.querySelector('select[name="action2"]');

            if (topAction && topAction.value && topAction.value !== '-1') {
                return topAction.value;
            }

            if (bottomAction && bottomAction.value && bottomAction.value !== '-1') {
                return bottomAction.value;
            }

            return '';
        }

        function hasSelectedRows(form) {
            return form.querySelector('tbody .check-column input[type="checkbox"]:checked') !== null;
        }

        function hasConfirmationField(form) {
            return form.querySelector('input[name="rrze-updater-bulk-delete-confirmed"]') !== null;
        }

        function addConfirmationField(form) {
            var input = document.createElement('input');

            input.type = 'hidden';
            input.name = 'rrze-updater-bulk-delete-confirmed';
            input.value = '1';
            form.appendChild(input);
        }

        function openDialog(form) {
            pendingForm = form;
            checkbox.checked = false;
            confirmButton.disabled = true;
            backdrop.classList.add('is-active');
            checkbox.focus();
        }

        function closeDialog() {
            backdrop.classList.remove('is-active');
            pendingForm = null;
        }

        function handleFormSubmit(event) {
            var form = event.currentTarget;

            if (getSelectedBulkAction(form) !== 'delete' || !hasSelectedRows(form) || hasConfirmationField(form)) {
                return;
            }

            event.preventDefault();
            openDialog(form);
        }

        function handleCheckboxChange() {
            confirmButton.disabled = !checkbox.checked;
        }

        function handleConfirmClick() {
            if (!pendingForm || !checkbox.checked) {
                return;
            }

            addConfirmationField(pendingForm);
            pendingForm.submit();
        }

        function handleCancelClick() {
            closeDialog();
        }

        function handleBackdropClick(event) {
            if (event.target === backdrop) {
                closeDialog();
            }
        }

        function handleKeydown(event) {
            if (event.key === 'Escape' && backdrop.classList.contains('is-active')) {
                closeDialog();
            }
        }

        function bindForms() {
            var forms = document.querySelectorAll('.rrze-updater-bulk-action-form');
            var i = 0;

            for (i = 0; i < forms.length; i++) {
                forms[i].addEventListener('submit', handleFormSubmit);
            }
        }

        bindForms();
        checkbox.addEventListener('change', handleCheckboxChange);
        confirmButton.addEventListener('click', handleConfirmClick);
        cancelButton.addEventListener('click', handleCancelClick);
        backdrop.addEventListener('click', handleBackdropClick);
        document.addEventListener('keydown', handleKeydown);
    }());
</script>
