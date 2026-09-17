/* global rrzeUpdaterBundles */
(() => {
    'use strict';
    const config = rrzeUpdaterBundles;
    const label = key => config.labels[key] || key;
    const element = name => document.getElementById(`rrze-bundle-${name}`);
    let job = null;
    let busy = false;
    let paused = true;
    let loaded = false;
    const providers = Object.keys(config.catalog.connectors);
    const selections = () => Object.fromEntries(providers.map(provider => [provider, element(`connector-${provider}`).value]));
    const selectionChanged = () => job && providers.some(provider => selections()[provider] !== job.connectors?.[provider]);

    function render() {
        const phase = job?.phase || 'idle';
        const items = job ? Object.values(job.items) : config.catalog.items;
        const rows = items.map(item => {
            const row = document.createElement('tr');
            const state = item.status === 'ready' ? 'checked' : (item.status || 'pending');
            const values = [
                item.repository,
                `${item.provider} / ${item.type}`,
                `${item.branch} / ${item.updates}`,
                item.plan ? `${label(item.plan.action)} / ${item.plan.ref}` : '—',
                label(state), item.message || '',
            ];
            values.forEach(value => {
                const cell = document.createElement('td');
                cell.textContent = value;
                row.append(cell);
            });
            return row;
        });
        element('items').replaceChildren(...rows);
        const working = ['checking', 'running'].includes(phase);
        const pendingStates = phase === 'checking' ? ['pending', 'checking'] : ['queued', 'installing'];
        const completed = !job ? 0 : items.filter(item => !pendingStates.includes(item.status)).length;
        element('progress').value = completed;
        element('count').textContent = label('progress').replace('%1$s', completed).replace('%2$s', items.length);
        element('status').textContent = working && paused && !busy ? label('paused') : label(phase);
        if (phase === 'complete' && items.some(item => item.status === 'prerequisite_skipped')) {
            element('status').textContent = label('completeWithSkips');
        }
        const changed = selectionChanged();
        if (changed && !busy) element('status').textContent = label('selectionChanged');
        providers.forEach(provider => { element(`connector-${provider}`).disabled = !loaded || busy; });
        element('check').disabled = !loaded || busy || providers.some(provider => !selections()[provider]);
        element('install').hidden = phase !== 'ready';
        element('install-anyways').hidden = phase !== 'blocked';
        element('install-anyways').disabled = busy || changed || !items.some(item => item.status === 'ready');
        element('resume').hidden = !working || busy;
        element('retry').hidden = !(phase === 'blocked' || (phase === 'complete' && items.some(item => item.status === 'failed')));
        element('pause').hidden = !busy;
        ['install', 'resume', 'retry'].forEach(name => { element(name).disabled = busy || changed; });
    }

    async function request(operation) {
        const body = new URLSearchParams({
            action: config.action, operation, nonce: config.nonce, network: config.network,
            job: job?.id || '', revision: job?.revision ?? -1,
        });
        if (operation === 'check') {
            Object.entries(selections()).forEach(([provider, id]) => body.set(`connectors[${provider}]`, id));
        }
        const response = await fetch(config.url, { method: 'POST', credentials: 'same-origin', body });
        let result;
        try { result = await response.json(); } catch { throw new Error(label('networkError')); }
        if (!response.ok || !result.success) {
            throw new Error(result.data?.message || label('networkError'));
        }
        const previousId = job?.id;
        job = result.data.job;
        if (job?.connectors && (!loaded || previousId !== job.id)) {
            providers.forEach(provider => { element(`connector-${provider}`).value = job.connectors[provider] || ''; });
        }
        loaded = true;
    }

    async function run(operation) {
        if (busy) return;
        busy = true;
        paused = operation === 'status';
        element('error').hidden = true;
        render();
        try {
            await request(operation === 'resume' ? 'status' : operation);
            render();
            while (!paused && ['checking', 'running'].includes(job?.phase)) {
                await request('step');
                render();
            }
        } catch (error) {
            paused = true;
            element('error').textContent = error.message;
            element('error').hidden = false;
            // Reconcile saved state after an uncertain response. This is read-only;
            // no automatic retry can repeat an installation request.
            try { await request('status'); } catch { /* Leave the error visible. */ }
        } finally {
            busy = false;
            render();
        }
    }
    providers.forEach(provider => element(`connector-${provider}`).addEventListener('change', render));
    element('check').addEventListener('click', () => run('check'));
    element('install').addEventListener('click', () => run('install'));
    element('install-anyways').addEventListener('click', () => run('install_anyways'));
    element('resume').addEventListener('click', () => run('resume'));
    element('retry').addEventListener('click', () => run('retry'));
    element('pause').addEventListener('click', () => { paused = true; });
    run('status');
})();
