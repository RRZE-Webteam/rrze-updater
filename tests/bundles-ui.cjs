// Deterministic browser-controller tests; no network or npm dependencies.
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/bundles.js'), 'utf8');
const entry = { id: 'plugin/example', repository: '<img src=x onerror=alert(1)>', provider: 'github', type: 'plugin', branch: 'main', updates: 'tags' };
const tick = () => new Promise(resolve => setImmediate(resolve));
let checks = 0;
function expect(condition, message) { assert.ok(condition, message); checks++; }
function page(fetch) {
    const elements = new Map();
    function node() {
        return { hidden: false, disabled: false, textContent: '', listeners: {}, children: [],
            append(child) { this.children.push(child); }, replaceChildren(...children) { this.children = children; },
            addEventListener(name, handler) { this.listeners[name] = handler; },
            set innerHTML(value) { throw new Error('Do not render API values as HTML'); } };
    }
    const document = { createElement: node, getElementById(id) { if (!elements.has(id)) elements.set(id, node()); return elements.get(id); } };
    vm.runInNewContext(source, { document, fetch, URLSearchParams, rrzeUpdaterBundles: {
        url: '/ajax', action: 'rrze_updater_bundle', network: 2, nonce: 'nonce', catalog: { items: [entry] },
        labels: { progress: '%1$s of %2$s', networkError: 'Network error' },
    } });
    return { get: name => document.getElementById(`rrze-bundle-${name}`), click: name => document.getElementById(`rrze-bundle-${name}`).listeners.click() };
}
const response = job => ({ ok: true, json: async () => ({ success: true, data: { job: structuredClone(job) } }) });
function job(phase, status) { return { id: 'job-1', revision: 1, phase, items: { [entry.id]: { ...entry, status, plan: { action: 'install', ref: 'v1' } } } }; }
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
