// Deterministic browser-controller tests; no network or npm dependencies.
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/bundles.js'), 'utf8');
const entry = { id: 'plugin/example', repository: '<img src=x onerror=alert(1)>', provider: 'github', type: 'plugin', branch: 'main', updates: 'commits' };
const tick = () => new Promise(resolve => setImmediate(resolve));
let checks = 0;
function expect(condition, message) { assert.ok(condition, message); checks++; }
function page(fetch) {
    const elements = new Map();
    function node() {
        return { value: '', hidden: false, disabled: false, textContent: '', listeners: {}, children: [],
            append(child) { this.children.push(child); }, replaceChildren(...children) { this.children = children; },
            addEventListener(name, handler) { this.listeners[name] = handler; },
            set innerHTML(value) { throw new Error('Do not render API values as HTML'); } };
    }
    const document = { createElement: node, getElementById(id) { if (!elements.has(id)) elements.set(id, node()); return elements.get(id); } };
    document.getElementById('rrze-bundle-connector-github').value = 'github-one';
    document.getElementById('rrze-bundle-connector-gitlab').value = 'gitlab-one';
    vm.runInNewContext(source, { document, fetch, URLSearchParams, rrzeUpdaterBundles: {
        url: '/ajax', action: 'rrze_updater_bundle', network: 2, nonce: 'nonce', catalog: { items: [entry], connectors: { github: {}, gitlab: {} } },
        labels: { progress: '%1$s of %2$s', networkError: 'Network error' },
    } });
    return { get: name => document.getElementById(`rrze-bundle-${name}`), click: name => document.getElementById(`rrze-bundle-${name}`).listeners.click() };
}
const response = job => ({ ok: true, json: async () => ({ success: true, data: { job: structuredClone(job) } }) });
function job(phase, status) { return { id: 'job-1', revision: 1, phase, connectors: { github: 'github-one', gitlab: 'gitlab-one' }, items: { [entry.id]: { ...entry, status, plan: { action: 'install', ref: 'v1' } } } }; }
(async () => {
    let state = job('ready', 'ready');
    const operations = [];
    const ui = page(async (_url, request) => {
        const operation = request.body.get('operation'); operations.push(operation);
        expect(request.body.get('network') === '2' && request.body.get('nonce') === 'nonce', 'Send network-bound authorization data');
        if (operation === 'install') state = job('running', 'queued');
        if (operation === 'step') state = job('complete', 'done');
        return response(state);
    });
    await tick();
    expect(operations.join(',') === 'status', 'Opening page is read-only');
    expect(!ui.get('install').hidden, 'Reviewed bundle offers installation');
    expect(ui.get('items').children[0].children[0].textContent === entry.repository, 'Repository names remain plain text');
    ui.click('install'); await tick();
    expect(operations.join(',') === 'status,install,step', 'Install requests one step at a time');
    expect(ui.get('progress').value === 1 && ui.get('resume').hidden, 'Completed job shows complete progress');

    state = job('checking', 'pending');
    let release;
    const pausedOps = [];
    const paused = page(async (_url, request) => {
        const operation = request.body.get('operation'); pausedOps.push(operation);
        if (operation === 'step') await new Promise(resolve => { release = resolve; });
        return response(state);
    });
    await tick();
    expect(pausedOps.join(',') === 'status' && !paused.get('resume').hidden, 'Reopening does not start paused work automatically');
    paused.click('resume'); await tick();
    paused.click('pause'); release(); await tick();
    expect(pausedOps.join(',') === 'status,status,step', 'Pause finishes the in-flight request without sending another');
    expect(!paused.get('resume').hidden && paused.get('pause').hidden, 'Paused job can be resumed');

    let selectionState = job('ready', 'ready');
    selectionState.connectors = { github: 'saved-github', gitlab: 'saved-gitlab' };
    const selectionRequests = [];
    const selected = page(async (_url, request) => {
        const operation = request.body.get('operation');
        selectionRequests.push(Object.fromEntries(request.body));
        if (operation === 'check') {
            selectionState = { ...job('ready', 'ready'), id: 'new-job', connectors: {
                github: request.body.get('connectors[github]'), gitlab: request.body.get('connectors[gitlab]'),
            } };
        }
        return response(selectionState);
    });
    await tick();
    expect(selected.get('connector-github').value === 'saved-github' && selected.get('connector-gitlab').value === 'saved-gitlab', 'Restore both saved selections on reopen');
    selected.get('connector-github').value = 'different-github';
    selected.get('connector-github').listeners.change();
    expect(selected.get('install').disabled && selected.get('retry').disabled && selected.get('resume').disabled, 'Selection changes require a fresh preflight');
    expect(!selected.get('check').disabled, 'Fresh preflight remains available with both selections');
    selected.get('connector-gitlab').value = '';
    selected.get('connector-gitlab').listeners.change();
    expect(selected.get('check').disabled, 'Both connectors must be selected');
    selected.get('connector-gitlab').value = 'different-gitlab';
    selected.get('connector-gitlab').listeners.change();
    selected.click('check'); await tick();
    const sent = selectionRequests.find(request => request.operation === 'check');
    expect(sent['connectors[github]'] === 'different-github' && sent['connectors[gitlab]'] === 'different-gitlab', 'Submit both chosen IDs for preflight');
    expect(!selected.get('install').disabled, 'Review completed using the new selections');
    expect(!Object.keys(selectionRequests[0]).some(key => key.startsWith('connectors[')), 'Status requests do not override job selections');

    let partialState = job('blocked', 'ready');
    partialState.items['plugin/bad'] = { ...entry, id: 'plugin/bad', status: 'error', message: 'Access denied' };
    const partialOps = [];
    const partial = page(async (_url, request) => {
        const operation = request.body.get('operation'); partialOps.push(operation);
        if (operation === 'install_anyways') {
            partialState.phase = 'running';
            partialState.items[entry.id].status = 'queued';
            partialState.items['plugin/bad'].status = 'prerequisite_skipped';
        }
        if (operation === 'step') {
            partialState.phase = 'complete';
            partialState.items[entry.id].status = 'done';
        }
        return response(partialState);
    });
    await tick();
    expect(!partial.get('install-anyways').hidden && !partial.get('install-anyways').disabled, 'Offer Install anyways for a partially blocked job');
    expect(partial.get('install').hidden, 'Keep standard install hidden for prerequisite errors');
    partial.get('connector-github').value = 'changed';
    partial.get('connector-github').listeners.change();
    expect(partial.get('install-anyways').disabled, 'Partial installation cannot use an unreviewed connector selection');
    partial.get('connector-github').value = 'github-one';
    partial.get('connector-github').listeners.change();
    partial.click('install-anyways'); await tick();
    expect(partialOps.join(',') === 'status,install_anyways,step', 'Partial installation uses its explicit server action and normal queue');
    expect(partial.get('status').textContent === 'completeWithSkips', 'Completion summary distinguishes prerequisite skips');
    expect(partial.get('items').children[1].children[5].textContent === 'Access denied', 'Original prerequisite errors remain visible after skipping');
    expect(partial.get('retry').hidden, 'Skipped prerequisites require new checks rather than installation retry');
    const allErrors = page(async () => response(job('blocked', 'error')));
    await tick();
    expect(allErrors.get('install-anyways').disabled, 'Disable partial installation when every prerequisite failed');
    expect(ui.get('install-anyways').hidden, 'Do not offer partial installation on completed jobs');

    let shouldFail = false;
    const failureOps = [];
    const failed = page(async (_url, request) => {
        const operation = request.body.get('operation'); failureOps.push(operation);
        if (shouldFail && operation === 'status') throw new Error('Connection lost');
        return response(job('running', 'installing'));
    });
    await tick(); shouldFail = true;
    failed.click('resume'); await tick();
    expect(!failureOps.includes('step'), 'Failed status reconciliation must not start an install');
    expect(!failed.get('error').hidden, 'Connection failures are visible');

    const uncertainOps = [];
    const uncertain = page(async (_url, request) => {
        const operation = request.body.get('operation'); uncertainOps.push(operation);
        if (operation === 'step') throw new Error('Response lost');
        return response(job('running', 'installing'));
    });
    await tick(); uncertain.click('resume'); await tick();
    expect(uncertainOps.join(',') === 'status,status,step,status', 'Uncertain installation response triggers only a read-only reconciliation');
    expect(!uncertain.get('resume').hidden && !uncertain.get('error').hidden, 'Recovery requires explicit resume');
    console.log(`Passed ${checks} bundle UI checks.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
