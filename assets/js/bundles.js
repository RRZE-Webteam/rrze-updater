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
        element('check').disabled = !loaded || busy;
        element('install').hidden = phase !== 'ready';
        element('resume').hidden = !working || busy;
        element('retry').hidden = !(phase === 'blocked' || (phase === 'complete' && items.some(item => item.status === 'failed')));
        element('pause').hidden = !busy;
        ['install', 'resume', 'retry'].forEach(name => { element(name).disabled = busy; });
    }

    async function request(operation) {
        const body = new URLSearchParams({
            action: config.action, operation, nonce: config.nonce, network: config.network,
            job: job?.id || '', revision: job?.revision ?? -1,
        });
        const response = await fetch(config.url, { method: 'POST', credentials: 'same-origin', body });
        let result;
        try { result = await response.json(); } catch { throw new Error(label('networkError')); }
        if (!response.ok || !result.success) {
            throw new Error(result.data?.message || label('networkError'));
        }
        job = result.data.job;
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
    element('check').addEventListener('click', () => run('check'));
    element('install').addEventListener('click', () => run('install'));
    element('resume').addEventListener('click', () => run('resume'));
    element('retry').addEventListener('click', () => run('retry'));
    element('pause').addEventListener('click', () => { paused = true; });
    run('status');
})();
