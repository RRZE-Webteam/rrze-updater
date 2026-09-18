import { useEffect, useMemo, useState } from '@wordpress/element';
import { Button, Modal, Notice, SelectControl, Spinner, TextControl } from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';
import { __, sprintf } from '@wordpress/i18n';
import { allPages } from './api';

function useBranches(connector, repository, enabled) {
    const [branches, setBranches] = useState([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [attempt, setAttempt] = useState(0);
    useEffect(() => {
        if (!enabled) return;
        const controller = new AbortController();
        setLoading(true); setError(''); setBranches([]);
        allPages('browse_branches', { connector, repository }, controller.signal)
            .then(setBranches).catch(e => { if (!controller.signal.aborted) setError(e.message); })
            .finally(() => { if (!controller.signal.aborted) setLoading(false); });
        return () => controller.abort();
    }, [connector, repository, enabled, attempt]);
    return { branches, loading, error, retry: () => setAttempt(current => current + 1) };
}

function Branch({ connector, item, value, onChange }) {
    const [opened, setOpened] = useState(false);
    const { branches, loading, error, retry } = useBranches(connector, item.repository, opened);
    const options = [...new Set([value, item.branch, ...branches].filter(Boolean))].map(branch => ({ label: branch, value: branch }));
    return <div className="rrze-branch">
        <SelectControl label={sprintf(__('Branch for %s', 'rrze-updater'), item.repository)} value={value}
            options={options.length ? options : [{ label: __('No default branch', 'rrze-updater'), value: '' }]}
            onFocus={() => setOpened(true)} onChange={onChange} __nextHasNoMarginBottom />
        {loading && <span role="status"><Spinner />{__('Loading branches…', 'rrze-updater')}</span>}
        {error && <Notice status="error" isDismissible={false}>{error} <Button variant="link" onClick={retry}>{__('Retry branch lookup', 'rrze-updater')}</Button></Notice>}
    </div>;
}

function BranchDialog({ connector, item, onApply, onClose }) {
    const [value, setValue] = useState(item.branch);
    const { branches, loading, error, retry } = useBranches(connector, item.repository, true);
    return <Modal title={sprintf(__('Change branch for %s', 'rrze-updater'), item.repository)} onRequestClose={onClose}>
        <p>{sprintf(__('Default branch: %s', 'rrze-updater'), item.defaultBranch || '—')}</p>
        {loading && <p role="status"><Spinner />{__('Loading branches…', 'rrze-updater')}</p>}
        {error && <Notice status="error" isDismissible={false}>{error} <Button variant="link" onClick={retry}>{__('Retry branch lookup', 'rrze-updater')}</Button></Notice>}
        <SelectControl label={__('Installation branch', 'rrze-updater')} value={value} disabled={loading || !!error}
            options={[{ value: '', label: __('Select a branch', 'rrze-updater') }, ...branches.map(branch => ({ value: branch, label: branch }))]}
            onChange={setValue} __nextHasNoMarginBottom />
        <p>{__('This branch will be used when you select and install this repository. Updates will track its commits.', 'rrze-updater')}</p>
        <div className="rrze-toolbar">
            <Button variant="primary" disabled={loading || !!error || !branches.includes(value)} onClick={() => { onApply(value); onClose(); }}>{__('Use branch', 'rrze-updater')}</Button>
            <Button variant="tertiary" onClick={onClose}>{__('Cancel', 'rrze-updater')}</Button>
        </div>
    </Modal>;
}

export default function Browser({ connector, onConnector, selected, onSelected, onReview, disabled, canInstall }) {
    const [repositories, setRepositories] = useState([]);
    const [loading, setLoading] = useState(false);
    const [loaded, setLoaded] = useState(0);
    const [error, setError] = useState('');
    const [refresh, setRefresh] = useState(0);
    const [branchChoices, setBranchChoices] = useState({ connector, values: {} });
    const [branchEditor, setBranchEditor] = useState(null);
    const [view, setView] = useState({ type: 'table', perPage: 20, page: 1, search: '', fields: ['description', 'branch', 'updated_at', 'visibility', 'managed', 'archived'], titleField: 'repository', sort: { field: 'updated_at', direction: 'desc' } });
    useEffect(() => {
        setRepositories([]); setError(''); setLoaded(0); setBranchEditor(null);
        setView(current => ({ ...current, page: 1, search: '' }));
        if (!connector) { setLoading(false); return; }
        const controller = new AbortController();
        setLoading(true);
        allPages('browse_repositories', { connector, refresh: refresh ? '1' : '0' }, controller.signal, setLoaded)
            .then(setRepositories).catch(e => { if (!controller.signal.aborted) setError(e.message); })
            .finally(() => { if (!controller.signal.aborted) setLoading(false); });
        return () => controller.abort();
    }, [connector, refresh]);
    const fields = useMemo(() => [
        { id: 'repository', label: __('Repository', 'rrze-updater'), enableGlobalSearch: true },
        { id: 'description', label: __('Description', 'rrze-updater'), enableGlobalSearch: true, enableSorting: false },
        { id: 'branch', label: __('Installation branch', 'rrze-updater'),
            render: ({ item }) => <div className="rrze-branch-cell">
                <code>{item.branch || '—'}</code>
                <small>{item.branch && item.branch === item.defaultBranch
                    ? __('Default branch', 'rrze-updater')
                    : sprintf(__('Default: %s', 'rrze-updater'), item.defaultBranch || '—')}</small>
            </div>,
        },
        { id: 'updated_at', label: __('Updated', 'rrze-updater'),
            sort: (a, b, direction) => {
                const difference = (Date.parse(a.updated_at) || 0) - (Date.parse(b.updated_at) || 0);
                return difference ? difference * (direction === 'asc' ? 1 : -1) : a.repository.localeCompare(b.repository);
            },
            render: ({ item }) => Number.isFinite(Date.parse(item.updated_at))
                ? <time dateTime={item.updated_at}>{new Date(item.updated_at).toLocaleString()}</time> : '—',
        },
        { id: 'visibility', label: __('Visibility', 'rrze-updater'), elements: [{ value: 'public', label: __('Public', 'rrze-updater') }, { value: 'private', label: __('Private', 'rrze-updater') }, { value: 'internal', label: __('Internal', 'rrze-updater') }] },
        { id: 'managed', label: __('Updater status', 'rrze-updater'), render: ({ item }) => item.managed ? __('Managed', 'rrze-updater') : __('Not managed', 'rrze-updater'), elements: [{ value: true, label: __('Managed', 'rrze-updater') }, { value: false, label: __('Not managed', 'rrze-updater') }] },
        { id: 'archived', label: __('Archived', 'rrze-updater'), render: ({ item }) => item.archived ? __('Yes', 'rrze-updater') : '—', enableSorting: false },
    ], []);
    const rows = useMemo(() => {
        const chosen = new Map(selected.map(item => [item.id, item.branch]));
        const overrides = new Map(Object.entries(branchChoices.connector === connector ? branchChoices.values : {}));
        return repositories.map(item => ({ ...item, defaultBranch: item.branch, branch: chosen.get(item.id) ?? overrides.get(item.id) ?? item.branch }));
    }, [repositories, selected, branchChoices, connector]);
    const { data, paginationInfo } = useMemo(() => filterSortAndPaginate(rows, view, fields), [rows, view, fields]);
    const editing = branchEditor?.connector === connector ? rows.find(item => item.id === branchEditor.id) : null;
    function changeSelection(ids) {
        const byId = new Map(rows.map(item => [item.id, item]));
        onSelected(ids.map(id => selected.find(item => item.id === id) || byId.get(id)).filter(Boolean).map(item => ({ ...item, folder: item.folder ?? item.repository })));
    }
    function changeItem(id, values) { onSelected(selected.map(item => item.id === id ? { ...item, ...values } : item)); }
    function changeBranch(id, branch) {
        setBranchChoices(current => ({ connector, values: { ...(current.connector === connector ? current.values : {}), [id]: branch } }));
        changeItem(id, { branch });
    }
    function changeConnector(value) {
        setBranchChoices({ connector: value, values: {} });
        setBranchEditor(null);
        onConnector(value);
    }
    const choices = window.rrzeUpdaterBundles.connectors;
    return <>
        <div className="rrze-toolbar">
            <SelectControl label={__('Connector', 'rrze-updater')} value={connector} disabled={disabled}
                options={[{ label: __('Select a connector', 'rrze-updater'), value: '' }, ...choices.map(choice => ({
                    value: choice.id, disabled: choice.authenticated !== 'yes',
                    label: `${choice.host} / ${choice.owner} (${choice.id})${choice.authenticated !== 'yes' ? ' — ' + __('Token missing', 'rrze-updater') : ''}`,
                }))]} onChange={changeConnector} __nextHasNoMarginBottom />
            <Button variant="secondary" disabled={!connector || loading || disabled} onClick={() => setRefresh(refresh + 1)}>{__('Refresh repositories', 'rrze-updater')}</Button>
        </div>
        <p>{__('Choose repositories belonging to this connector’s owner. Use Change branch in a repository’s actions to choose its installation branch. Updates will track commits on that branch.', 'rrze-updater')}</p>
        {loading && <p role="status"><Spinner />{sprintf(__('Loading repositories… %d received', 'rrze-updater'), loaded)}</p>}
        {error && <Notice status="error" isDismissible={false}>{error}</Notice>}
        {connector && !loading && !error && <div className="rrze-dataviews"><DataViews data={data} fields={fields} view={view} onChangeView={setView}
            defaultLayouts={{ table: {} }} paginationInfo={paginationInfo} getItemId={item => item.id}
            selection={selected.map(item => item.id)} onChangeSelection={changeSelection}
            actions={[
                { id: 'select', label: __('Add to selection', 'rrze-updater'), disabled, supportsBulk: true, callback: items => changeSelection([...new Set([...selected.map(item => item.id), ...items.map(item => item.id)])]) },
                { id: 'change-branch', label: __('Change branch', 'rrze-updater'), disabled, supportsBulk: false, callback: items => setBranchEditor({ connector, id: items[0].id }) },
            ]} /></div>}
        {editing && !disabled && <BranchDialog key={`${connector}/${editing.id}`} connector={connector} item={editing}
            onApply={branch => changeBranch(editing.id, branch)} onClose={() => setBranchEditor(null)} />}

        {!!selected.length && <section className="rrze-selection" aria-label={__('Selected repositories', 'rrze-updater')}>
            <div className="rrze-toolbar"><h3>{sprintf(__('%d selected', 'rrze-updater'), selected.length)}</h3><Button variant="tertiary" onClick={() => onSelected([])}>{__('Clear selection', 'rrze-updater')}</Button></div>
            <p>{__('Selections are kept across search results and pages. Review checks the selected branches for a WordPress plugin or theme.', 'rrze-updater')}</p>
            {selected.map(item => <div className="rrze-selection-row" key={item.id}>
                <strong>{item.repository}</strong>
                <Branch connector={connector} item={item} value={item.branch} onChange={branch => changeBranch(item.id, branch)} />
                <TextControl label={sprintf(__('Installation folder for %s', 'rrze-updater'), item.repository)} value={item.folder || ''} onChange={folder => changeItem(item.id, { folder })} __nextHasNoMarginBottom />
                <Button variant="tertiary" isDestructive onClick={() => onSelected(selected.filter(row => row.id !== item.id))} aria-label={sprintf(__('Remove %s', 'rrze-updater'), item.repository)}>{__('Remove', 'rrze-updater')}</Button>
            </div>)}
            {selected.length > 100 && <Notice status="warning" isDismissible={false}>{__('Select up to 100 repositories per installation job.', 'rrze-updater')}</Notice>}
            <Button variant="primary" disabled={disabled || !canInstall || loading || !!error || !connector || selected.length > 100 || selected.some(item => !item.branch || !item.folder)} onClick={onReview}>{__('Review selected repositories', 'rrze-updater')}</Button>
        </section>}
    </>;
}
