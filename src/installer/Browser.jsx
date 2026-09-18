import { useEffect, useMemo, useState } from '@wordpress/element';
import { Button, Notice, SelectControl, Spinner, TextControl } from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';
import { __, sprintf } from '@wordpress/i18n';
import { allPages } from './api';

function Branch({ connector, item, value, onChange }) {
    const [branches, setBranches] = useState([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [attempt, setAttempt] = useState(0);
    const [opened, setOpened] = useState(false);
    useEffect(() => {
        if (!opened) return;
        const controller = new AbortController();
        setLoading(true); setError('');
        allPages('browse_branches', { connector, repository: item.repository }, controller.signal)
            .then(setBranches).catch(e => { if (!controller.signal.aborted) setError(e.message); })
            .finally(() => { if (!controller.signal.aborted) setLoading(false); });
        return () => controller.abort();
    }, [connector, item.repository, opened, attempt]);
    const options = [...new Set([value, item.branch, ...branches].filter(Boolean))].map(branch => ({ label: branch, value: branch }));
    return <div className="rrze-branch">
        <SelectControl label={sprintf(__('Branch for %s', 'rrze-updater'), item.repository)} value={value}
            options={options.length ? options : [{ label: __('No default branch', 'rrze-updater'), value: '' }]}
            onFocus={() => setOpened(true)} onChange={onChange} __nextHasNoMarginBottom />
        {loading && <span role="status"><Spinner />{__('Loading branches…', 'rrze-updater')}</span>}
        {error && <Notice status="error" isDismissible={false}>{error} <Button variant="link" onClick={() => setAttempt(attempt + 1)}>{__('Retry branch lookup', 'rrze-updater')}</Button></Notice>}
    </div>;
}

export default function Browser({ connector, onConnector, selected, onSelected, onReview, disabled, canInstall }) {
    const [repositories, setRepositories] = useState([]);
    const [loading, setLoading] = useState(false);
    const [loaded, setLoaded] = useState(0);
    const [error, setError] = useState('');
    const [refresh, setRefresh] = useState(0);
    const [view, setView] = useState({ type: 'table', perPage: 20, page: 1, search: '', fields: ['description', 'visibility', 'managed', 'archived'], titleField: 'repository', sort: { field: 'repository', direction: 'asc' } });
    useEffect(() => {
        setRepositories([]); setError(''); setLoaded(0);
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
        { id: 'visibility', label: __('Visibility', 'rrze-updater'), elements: [{ value: 'public', label: __('Public', 'rrze-updater') }, { value: 'private', label: __('Private', 'rrze-updater') }, { value: 'internal', label: __('Internal', 'rrze-updater') }] },
        { id: 'managed', label: __('Updater status', 'rrze-updater'), render: ({ item }) => item.managed ? __('Managed', 'rrze-updater') : __('Not managed', 'rrze-updater'), elements: [{ value: true, label: __('Managed', 'rrze-updater') }, { value: false, label: __('Not managed', 'rrze-updater') }] },
        { id: 'archived', label: __('Archived', 'rrze-updater'), render: ({ item }) => item.archived ? __('Yes', 'rrze-updater') : '—', enableSorting: false },
    ], []);
    const { data, paginationInfo } = useMemo(() => filterSortAndPaginate(repositories, view, fields), [repositories, view, fields]);
    function changeSelection(ids) {
        const byId = new Map(repositories.map(item => [item.id, item]));
        onSelected(ids.map(id => selected.find(item => item.id === id) || byId.get(id)).filter(Boolean).map(item => ({ ...item, folder: item.folder ?? item.repository })));
    }
    function changeItem(id, values) { onSelected(selected.map(item => item.id === id ? { ...item, ...values } : item)); }
    const choices = window.rrzeUpdaterBundles.connectors;
    return <>
        <div className="rrze-toolbar">
            <SelectControl label={__('Connector', 'rrze-updater')} value={connector} disabled={disabled}
                options={[{ label: __('Select a connector', 'rrze-updater'), value: '' }, ...choices.map(choice => ({
                    value: choice.id, disabled: choice.authenticated !== 'yes',
                    label: `${choice.host} / ${choice.owner} (${choice.id})${choice.authenticated !== 'yes' ? ' — ' + __('Token missing', 'rrze-updater') : ''}`,
                }))]} onChange={onConnector} __nextHasNoMarginBottom />
            <Button variant="secondary" disabled={!connector || loading || disabled} onClick={() => setRefresh(refresh + 1)}>{__('Refresh repositories', 'rrze-updater')}</Button>
        </div>
        <p>{__('Choose repositories belonging to this connector’s owner. Select branches below; updates will track commits on those branches.', 'rrze-updater')}</p>
        {loading && <p role="status"><Spinner />{sprintf(__('Loading repositories… %d received', 'rrze-updater'), loaded)}</p>}
        {error && <Notice status="error" isDismissible={false}>{error}</Notice>}
        {connector && !loading && !error && <div className="rrze-dataviews"><DataViews data={data} fields={fields} view={view} onChangeView={setView}
            defaultLayouts={{ table: {} }} paginationInfo={paginationInfo} getItemId={item => item.id}
            selection={selected.map(item => item.id)} onChangeSelection={changeSelection}
            actions={[{ id: 'select', label: __('Add to selection', 'rrze-updater'), supportsBulk: true, callback: items => changeSelection([...new Set([...selected.map(item => item.id), ...items.map(item => item.id)])]) }]} /></div>}
        {!!selected.length && <section className="rrze-selection" aria-label={__('Selected repositories', 'rrze-updater')}>
            <div className="rrze-toolbar"><h3>{sprintf(__('%d selected', 'rrze-updater'), selected.length)}</h3><Button variant="tertiary" onClick={() => onSelected([])}>{__('Clear selection', 'rrze-updater')}</Button></div>
            <p>{__('Selections are kept across search results and pages. Review checks the selected branches for a WordPress plugin or theme.', 'rrze-updater')}</p>
            {selected.map(item => <div className="rrze-selection-row" key={item.id}>
                <strong>{item.repository}</strong>
                <Branch connector={connector} item={item} value={item.branch} onChange={branch => changeItem(item.id, { branch })} />
                <TextControl label={sprintf(__('Installation folder for %s', 'rrze-updater'), item.repository)} value={item.folder || ''} onChange={folder => changeItem(item.id, { folder })} __nextHasNoMarginBottom />
                <Button variant="tertiary" isDestructive onClick={() => onSelected(selected.filter(row => row.id !== item.id))} aria-label={sprintf(__('Remove %s', 'rrze-updater'), item.repository)}>{__('Remove', 'rrze-updater')}</Button>
            </div>)}
            {selected.length > 100 && <Notice status="warning" isDismissible={false}>{__('Select up to 100 repositories per installation job.', 'rrze-updater')}</Notice>}
            <Button variant="primary" disabled={disabled || !canInstall || loading || !!error || !connector || selected.length > 100 || selected.some(item => !item.branch || !item.folder)} onClick={onReview}>{__('Review selected repositories', 'rrze-updater')}</Button>
        </section>}
    </>;
}
