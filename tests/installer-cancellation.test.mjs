import test from 'node:test';
import assert from 'node:assert/strict';
import { runJob } from '../src/installer/job-runner.mjs';

function fixture() {
    const fixture = { saved: { id: 'job-1', revision: 1, phase: 'running' }, calls: [], ui: {} };
    const context = {
        current: { current: { ...fixture.saved } }, working: { current: false }, stop: { current: false }, cancelTarget: { current: null },
        accept: job => { context.current.current = { ...job }; }, connectors: {}, connector: '', selected: [],
        messages: { changed: 'Job changed', failed: 'Request failed', cancelPending: 'Cancellation pending' },
        request: async (operation, values) => {
            fixture.calls.push([operation, values]);
            if (operation === 'status' && fixture.statusError) throw new Error('Offline');
            if (operation === 'step') return fixture.step();
            if (operation === 'cancel') {
                assert.equal(values.job, fixture.saved.id);
                assert.equal(values.revision, fixture.saved.revision);
                if (fixture.cancelError) throw new Error('Busy');
                fixture.saved = { ...fixture.saved, phase: 'cancelled', revision: fixture.saved.revision + 1 };
                if (fixture.loseCancelResponse) { fixture.loseCancelResponse = false; throw new Error('Lost cancel response'); }
            }
            return { job: { ...fixture.saved } };
        },
    };
    for (const name of ['Busy', 'Error', 'Uncertain', 'Cancelling', 'Review']) {
        context[`set${name}`] = value => { fixture.ui[name] = value; };
    }
    fixture.cancel = () => { context.cancelTarget.current = context.current.current.id; context.stop.current = true; };
    fixture.context = context;
    fixture.operations = () => fixture.calls.map(([operation]) => operation);
    return fixture;
}

test('cancel waits for the current step, then uses its saved revision', async () => {
    const f = fixture();
    f.step = async () => {
        f.cancel();
        assert.deepEqual(f.operations(), ['status', 'step']);
        f.saved.revision++;
        return { job: { ...f.saved } };
    };
    await runJob('resume', f.context);
    assert.deepEqual(f.operations(), ['status', 'step', 'cancel']);
    assert.equal(f.saved.phase, 'cancelled');
});

test('a lost step response reconciles and sends the pending cancellation', async () => {
    const f = fixture();
    f.step = async () => { f.cancel(); f.saved.revision++; throw new Error('Lost response'); };
    await runJob('resume', f.context);
    assert.deepEqual(f.operations(), ['status', 'step', 'status', 'cancel']);
    assert.equal(f.context.cancelTarget.current, null);
    assert.equal(f.saved.phase, 'cancelled');
    assert.equal(f.ui.Error, '');
});

test('failed reconciliation preserves cancellation and resume retries cancellation only', async () => {
    const f = fixture();
    f.step = async () => { f.cancel(); f.statusError = true; throw new Error('Offline'); };
    await runJob('resume', f.context);
    assert.equal(f.context.cancelTarget.current, 'job-1');
    assert.equal(f.ui.Uncertain, true);
    assert.equal(f.ui.Error, 'Cancellation pending');
    f.statusError = false;
    f.calls = [];
    await runJob('resume', f.context);
    assert.deepEqual(f.operations(), ['status', 'cancel']);
    assert.equal(f.saved.phase, 'cancelled');
});

test('a rejected cancellation stays pending until a later successful retry', async () => {
    const f = fixture(); f.cancel(); f.cancelError = true;
    await runJob('cancel', f.context);
    assert.equal(f.context.cancelTarget.current, 'job-1');
    assert.equal(f.ui.Busy, false);
    f.cancelError = false; f.calls = [];
    await runJob('cancel', f.context);
    assert.deepEqual(f.operations(), ['status', 'cancel']);
    assert.equal(f.context.cancelTarget.current, null);
});

test('a lost cancellation response is reconciled without cancelling twice', async () => {
    const f = fixture(); f.cancel(); f.loseCancelResponse = true;
    await runJob('cancel', f.context);
    assert.deepEqual(f.operations(), ['status', 'cancel', 'status']);
    assert.equal(f.context.cancelTarget.current, null);
    assert.equal(f.ui.Uncertain, false);
});

test('cancellation never transfers to a replacement job from another tab', async () => {
    const f = fixture();
    f.step = async () => { f.cancel(); f.saved = { id: 'job-2', revision: 1, phase: 'running' }; throw new Error('Stale job'); };
    await runJob('resume', f.context);
    assert.deepEqual(f.operations(), ['status', 'step', 'status']);
    assert.equal(f.context.cancelTarget.current, null);
    assert.equal(f.saved.phase, 'running');
    assert.equal(f.ui.Error, 'Job changed');
});

test('completion of the last in-flight entry needs no further cancellation', async () => {
    const f = fixture();
    f.step = async () => { f.cancel(); f.saved.phase = 'complete'; throw new Error('Lost response'); };
    await runJob('resume', f.context);
    assert.deepEqual(f.operations(), ['status', 'step', 'status']);
    assert.equal(f.context.cancelTarget.current, null);
    assert.equal(f.saved.phase, 'complete');
});
