import { useState } from '@wordpress/element';
import { Button, CheckboxControl, Modal } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

export default function RegistrationDialog({ items, onContinue, onClose }) {
    const [selected, setSelected] = useState([]);
    return <Modal title={__('Manage existing installations?', 'rrze-updater')} onRequestClose={onClose}>
        <p>{__('The following plugins or themes are already installed but are not registered with RRZE Updater. Which ones would you like to add to Updater management?', 'rrze-updater')}</p>
        <p>{__('Leave installations you maintain through Git unchecked. Unchecked entries will stay unmanaged, and the remaining installation queue will continue.', 'rrze-updater')}</p>
        <div className="rrze-registration-list">{items.map(item => <CheckboxControl key={item.id}
            label={`${item.repository} (${item.type === 'theme' ? __('Theme', 'rrze-updater') : __('Plugin', 'rrze-updater')})`}
            help={sprintf(__('Installed folder: %s', 'rrze-updater'), item.folder)}
            checked={selected.includes(item.id)}
            onChange={checked => setSelected(current => checked ? [...current, item.id] : current.filter(id => id !== item.id))}
            __nextHasNoMarginBottom />)}</div>
        <p>{__('Registration keeps the current files. Future updates through RRZE Updater may replace them with files from the selected repository and branch.', 'rrze-updater')}</p>
        <div className="rrze-toolbar">
            <Button variant="primary" onClick={() => onContinue(selected)}>{selected.length
                ? __('Register selected and continue', 'rrze-updater')
                : __('Continue without registering these', 'rrze-updater')}</Button>
            <Button variant="tertiary" onClick={onClose}>{__('Back to review', 'rrze-updater')}</Button>
        </div>
    </Modal>;
}
