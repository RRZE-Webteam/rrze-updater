import test from 'node:test';
import assert from 'node:assert/strict';
import { registrationCandidates } from '../src/installer/registrations.mjs';
import { runJob } from '../src/installer/job-runner.mjs';

test('the registration prompt includes only ready unmanaged installations', () => {
    const items = [
        { id: 'existing-plugin', type: 'plugin', status: 'ready', plan: { action: 'register' } },
        { id: 'existing-theme', type: 'theme', status: 'ready', plan: { action: 'register' } },
        { id: 'managed', status: 'ready', plan: { action: 'skip' } },
        { id: 'new', status: 'ready', plan: { action: 'install' } },
        { id: 'blocked', status: 'error', plan: { action: 'register' } },
        { id: 'declined', status: 'registration_skipped', plan: { action: 'register' } },
    ];
    assert.deepEqual(registrationCandidates({ items }).map(item => item.id), ['existing-plugin', 'existing-theme']);
    assert.deepEqual(registrationCandidates({ items: [] }), []);
});

for (const operation of ['install', 'install_anyways']) {
    for (const registrations of [undefined, [], ['existing-plugin']]) {
        test(`${operation} sends explicit registration choices: ${JSON.stringify(registrations)}`, async () => {
            const calls = [];
            const context = {
                current: { current: { id: 'reviewed-job', revision: 8, phase: operation === 'install' ? 'ready' : 'blocked' } },
                working: { current: false }, stop: { current: false }, cancelTarget: { current: null }, registrations,
                request: async (action, values) => {
                    calls.push({ action, values });
                    return { job: { id: 'reviewed-job', revision: 9, phase: 'complete' } };
                },
                accept: job => { context.current.current = job; },
                setBusy() {}, setError(message) { assert.equal(message, ''); }, setUncertain() {}, setCancelling() {}, setReview() {},
                messages: {},
            };
            await runJob(operation, context);
            assert.deepEqual(calls, [{ action: operation, values: { job: 'reviewed-job', revision: 8, registrations: registrations ?? [] } }]);
        });
    }
}
