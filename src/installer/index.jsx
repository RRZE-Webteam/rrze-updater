import { createRoot, useEffect, useRef, useState } from '@wordpress/element';
import { Button, Notice, SelectControl, Spinner, TabPanel } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import Browser from './Browser';
import RegistrationDialog from './RegistrationDialog';
import { registrationCandidates } from './registrations.mjs';
import { request } from './api';
import { runJob } from './job-runner.mjs';
import '../../node_modules/@wordpress/dataviews/build-style/style.css';
import './style.css';

const config = window.rrzeUpdaterBundles;
const active = job => ['checking', 'running'].includes(job?.phase);
const labels = {
    pending: __('Pending', 'rrze-updater'), checking: __('Checking', 'rrze-updater'), ready: __('Ready', 'rrze-updater'),
    error: __('Check failed', 'rrze-updater'), queued: __('Queued', 'rrze-updater'), installing: __('Installing', 'rrze-updater'),
    done: __('Successful', 'rrze-updater'), skipped: __('Already managed', 'rrze-updater'), failed: __('Failed', 'rrze-updater'),
    prerequisite_skipped: __('Skipped: prerequisite error', 'rrze-updater'),
    registration_skipped: __('Left unmanaged', 'rrze-updater'),
    cancelled: __('Cancelled', 'rrze-updater'), interrupted: __('Interrupted — inspect files', 'rrze-updater'),
    install: __('Install and register', 'rrze-updater'), register: __('Register existing installation', 'rrze-updater'), skip: __('Already managed', 'rrze-updater'),
};

function Recommended({ connectors, onChange, onReview, disabled }) {
    return <>
        <h2>{config.catalog.name}</h2>
        <p>{sprintf(__('Recommended bundle · %d repositories', 'rrze-updater'), config.catalog.items.length)}</p>
        <p>{__('Install our curated set of plugins and themes. Prerequisite checks determine the installation order and identify any missing parent themes.', 'rrze-updater')}</p>
        <div className="rrze-toolbar">{Object.entries(config.catalog.connectors).map(([provider, requirement]) =>
            <SelectControl key={provider} label={`${requirement.host} / ${requirement.owner}`} value={connectors[provider] || ''}
                options={[{ value: '', label: __('Select a connector', 'rrze-updater') }, ...(config.recommendedConnectors[provider] || []).map(choice => ({ value: choice.id, disabled: !choice.has_token, label: `${choice.name} (${choice.id})${!choice.has_token ? ' — ' + __('Token missing', 'rrze-updater') : ''}` }))]}
                onChange={value => onChange({ ...connectors, [provider]: value })} disabled={disabled} __nextHasNoMarginBottom />
        )}</div>
        <Button variant="primary" disabled={disabled || Object.keys(config.catalog.connectors).some(provider => !connectors[provider])} onClick={onReview}>{__('Review recommended bundle', 'rrze-updater')}</Button>
        <details className="rrze-catalog"><summary>{__('Included repositories', 'rrze-updater')}</summary><ul>{config.catalog.items.map(item => <li key={item.id}>{item.repository} · {item.type} · {item.branch}</li>)}</ul></details>
    </>;
}

function Review({ job, busy, cancelling, onRun, onPause, onCancel, onEdit, uncertain, cancelPending }) {
    const [registrationPrompt, setRegistrationPrompt] = useState(null);
    const candidates = registrationCandidates(job);
    function install(operation) {
        if (candidates.length) setRegistrationPrompt({ operation, revision: job.revision });
        else onRun(operation);
    }
    const items = Object.values(job.items);
    const processed = items.filter(item => !['pending', 'checking', 'queued', 'installing', 'cancelled', 'interrupted'].includes(item.status)).length;
    const failed = items.some(item => item.status === 'failed');
    const skipped = items.some(item => ['prerequisite_skipped', 'registration_skipped'].includes(item.status));
    const phaseText = {
        checking: __('Checking repository access, package structure, and prerequisites.', 'rrze-updater'),
        ready: __('Review the planned actions below, then install.', 'rrze-updater'),
        blocked: __('Some checks failed. Resolve them and check again, or install only entries that passed and have satisfied dependencies.', 'rrze-updater'),
        running: __('Installing and registering repositories.', 'rrze-updater'),
        complete: skipped ? __('Processing finished with skipped entries. Review the results below.', 'rrze-updater') : __('Processing finished. Review the results below.', 'rrze-updater'),
        cancelled: __('Process cancelled. Completed installations and registrations have been kept. Start a new review to install more repositories.', 'rrze-updater'),
    };
    return <section aria-label={__('Installation review', 'rrze-updater')}>
        <h2>{job.source === 'custom' ? __('Selected repositories', 'rrze-updater') : __('Recommended bundle', 'rrze-updater')}</h2>
        <p role="status">{busy && <Spinner />}{cancelling ? __('Cancelling the process after the current entry finishes…', 'rrze-updater') : phaseText[job.phase]} {!busy && !cancelling && !cancelPending && active(job) && __('Paused. Resume to continue from saved progress.', 'rrze-updater')}</p>
        <div className="rrze-progress"><progress value={processed} max={items.length || 1} aria-label={__('Installation progress', 'rrze-updater')} /><span>{sprintf(__('%1$d of %2$d processed', 'rrze-updater'), processed, items.length)}</span></div>
        <div className="rrze-toolbar">
            {job.phase === 'ready' && <Button variant="primary" disabled={busy || uncertain || cancelPending || !config.fileModifications} onClick={() => install('install')}>{__('Install repositories', 'rrze-updater')}</Button>}
            {job.phase === 'blocked' && <Button variant="primary" disabled={busy || uncertain || cancelPending || !config.fileModifications || !items.some(item => item.status === 'ready')} onClick={() => install('install_anyways')}>{__('Install passing entries', 'rrze-updater')}</Button>}
            {!busy && !cancelPending && active(job) && <Button variant="primary" disabled={!config.fileModifications} onClick={() => onRun('resume')}>{__('Resume', 'rrze-updater')}</Button>}
            {busy && <Button variant="secondary" disabled={cancelling} onClick={onPause}>{__('Pause after current entry', 'rrze-updater')}</Button>}
            {!['complete', 'cancelled'].includes(job.phase) && <Button variant="secondary" isDestructive disabled={cancelling} onClick={onCancel}>{cancelling ? __('Cancelling…', 'rrze-updater') : cancelPending ? __('Retry cancellation', 'rrze-updater') : __('Cancel process', 'rrze-updater')}</Button>}
            {((job.phase === 'complete' && failed) || job.phase === 'blocked') && <Button variant="secondary" disabled={busy || uncertain || cancelPending || !config.fileModifications} onClick={() => onRun('retry')}>{job.phase === 'blocked' ? __('Retry checks', 'rrze-updater') : __('Retry failed installations', 'rrze-updater')}</Button>}
            {!active(job) && <Button variant="secondary" disabled={busy || uncertain || cancelPending} onClick={onEdit}>{__('Change selection / check again', 'rrze-updater')}</Button>}
        </div>
        {!['complete', 'cancelled'].includes(job.phase) && <p className="description">{__('Cancel stops the remaining queue after the current entry finishes. Completed installations and registrations are kept.', 'rrze-updater')}</p>}
        <p className="description">{__('Keep this page open while processing. Closing it pauses further requests; an in-flight request may still finish. Installation uses the reviewed commits. Existing files are kept, and activation is unchanged.', 'rrze-updater')}</p>
        {!!candidates.length && <Notice status="info" isDismissible={false}>{__('Some entries are already installed but unmanaged. Before installation starts, you can choose which ones to register with RRZE Updater.', 'rrze-updater')}</Notice>}
        {registrationPrompt?.revision === job.revision && !busy && !uncertain && !cancelPending && <RegistrationDialog
            key={`${job.id}/${job.revision}`} items={candidates} onClose={() => setRegistrationPrompt(null)}
            onContinue={registrations => { const operation = registrationPrompt.operation; setRegistrationPrompt(null); onRun(operation, registrations); }} />}
        <div className="rrze-review-table"><table className="widefat striped"><thead><tr>
            {[__('Repository', 'rrze-updater'), __('Type / folder', 'rrze-updater'), __('Branch / updates', 'rrze-updater'), __('Planned action / commit', 'rrze-updater'), __('Status', 'rrze-updater'), __('Details', 'rrze-updater')].map(title => <th scope="col" key={title}>{title}</th>)}
        </tr></thead><tbody>{items.map(item => <tr key={item.id}>
            <th scope="row">{item.repository}</th><td>{item.type || '—'}<br />{item.folder}</td><td>{item.branch}<br />{item.updates}</td>
            <td>{item.status === 'registration_skipped' ? __('Leave unmanaged', 'rrze-updater')
                : item.status === 'ready' && item.plan?.action === 'register' ? __('Optional registration', 'rrze-updater')
                : labels[item.plan?.action] || '—'}{item.plan?.ref && <><br /><code title={item.plan.ref}>{item.plan.ref.slice(0, 12)}</code></>}</td>
            <td><span className={`rrze-status rrze-status-${item.status}`}>{labels[item.status] || item.status}</span></td><td>{item.message}</td>
        </tr>)}</tbody></table></div>
    </section>;
}

function App() {
    const [job, setJob] = useState(null);
    const current = useRef(null);
    const stop = useRef(false);
    const working = useRef(false);
    const cancelTarget = useRef(null);
    const [busy, setBusy] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    const [loaded, setLoaded] = useState(false);
    const [error, setError] = useState('');
    const [uncertain, setUncertain] = useState(false);
    const [review, setReview] = useState(false);
    const [tab, setTab] = useState('recommended');
    const [connector, setConnector] = useState('');
    const [selected, setSelected] = useState([]);
    const [connectors, setConnectors] = useState(() => Object.fromEntries(Object.entries(config.recommendedConnectors).map(([provider, choices]) => {
        const available = choices.filter(choice => choice.has_token);
        return [provider, available.length === 1 ? available[0].id : ''];
    })));
    function accept(value) { current.current = value; setJob(value); }
    function restore(value) {
        if (!value?.items) return;
        if (value.source === 'custom') {
            setTab('browse'); setConnector(value.connectors.browse);
            // Selection IDs from the provider are restored when its directory loads.
            setSelected(Object.values(value.items).map(item => ({ ...item, id: item.repository })));
        } else setConnectors(value.connectors || {});
    }
    function cancel() {
        if (!current.current || (cancelTarget.current && working.current)) return;
        cancelTarget.current ??= current.current.id;
        stop.current = true;
        setCancelling(true);
        // Do not abort an installation request: wait for its saved result, then
        // cancel with that revision before the loop can send another step.
        if (!working.current) run('cancel');
    }
    useEffect(() => {
        let mounted = true;
        request('status').then(result => {
            if (!mounted) return;
            accept(result.job); restore(result.job); setReview(!!result.job); setLoaded(true);
        }).catch(() => { if (mounted) { setError(__('Could not load saved progress. Reload before continuing.', 'rrze-updater')); setUncertain(true); } });
        return () => { mounted = false; stop.current = true; };
    }, []);
    function run(operation, registrations = []) {
        return runJob(operation, {
            current, working, stop, cancelTarget, request, accept, setBusy, setError,
            setUncertain, setCancelling, setReview, connectors, connector, selected, registrations,
            messages: {
                changed: __('The saved process changed. Review its progress before cancelling.', 'rrze-updater'),
                failed: __('The request did not finish normally. Reload or resume to reconcile saved progress.', 'rrze-updater'),
                cancelPending: __('Cancellation has not been confirmed. Retry cancellation to reconcile saved progress before continuing.', 'rrze-updater'),
            },
        });
    }
    function edit() { restore(job); setReview(false); }
    return <div className="rrze-installer-shell">
        <div className="rrze-intro"><p>{__('Install plugins and themes from your connected repositories.', 'rrze-updater')}</p><Button variant="link" href={config.servicesUrl}>{__('Manage connectors', 'rrze-updater')}</Button></div>
        {!config.fileModifications && <Notice status="warning" isDismissible={false}>{__('File modifications are disabled. You can browse repositories and view saved progress.', 'rrze-updater')}</Notice>}
        {error && <Notice status="error" isDismissible={false}>{error}</Notice>}
        {!loaded ? (!error && <Spinner />) : review && job ? <Review key={job.id} job={job} busy={busy} cancelling={cancelling} uncertain={uncertain} cancelPending={!!cancelTarget.current} onRun={run} onPause={() => { stop.current = true; }} onCancel={cancel} onEdit={edit} /> :
            <TabPanel key={tab} initialTabName={tab} onSelect={setTab} tabs={[{ name: 'recommended', title: __('Recommended bundle', 'rrze-updater') }, { name: 'browse', title: __('Browse repositories', 'rrze-updater') }]}>
                {activeTab => activeTab.name === 'recommended'
                    ? <Recommended connectors={connectors} onChange={setConnectors} disabled={busy || uncertain || !!cancelTarget.current || !config.fileModifications} onReview={() => run('check')} />
                    : <Browser connector={connector} onConnector={value => { setConnector(value); setSelected([]); }} selected={selected} onSelected={setSelected}
                        disabled={busy || uncertain || !!cancelTarget.current} canInstall={config.fileModifications} onReview={() => run('check_custom')} />}
            </TabPanel>}
        {!review && job && <Button className="rrze-saved" variant="secondary" onClick={() => setReview(true)}>{__('Return to saved progress', 'rrze-updater')}</Button>}
    </div>;
}

createRoot(document.getElementById('rrze-installer-app')).render(<App />);
