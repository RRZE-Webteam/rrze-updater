import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { setImmediate as settle } from 'node:timers/promises';

const window = {};
runInNewContext(readFileSync(new URL('../assets/js/update-check-runner.js', import.meta.url), 'utf8'), { window });

function fixture(total = 3) {
    const f = { calls: [], timers: new Map(), progress: null, errors: [] };
    let timerId = 0;
    f.runner = window.rrzeUpdaterCreateCheckRunner({
        total, delay: 1000,
        check: index => {
            f.calls.push(index);
            return new Promise((resolve, reject) => { f.resolve = resolve; f.reject = reject; });
        },
        onChange: progress => { f.progress = progress; },
        onError: (error, index) => f.errors.push(index),
        setTimeout: (callback, delay) => { assert.equal(delay, 1000); f.timers.set(++timerId, callback); return timerId; },
        clearTimeout: id => f.timers.delete(id),
    });
    f.advance = () => {
        const [id, callback] = f.timers.entries().next().value;
        f.timers.delete(id);
        callback();
    };
    f.finish = async () => { f.resolve(); await settle(); };
    return f;
}

test('stop waits for the active request and does not issue another check', async () => {
    const f = fixture();
    f.runner.resume();
    f.runner.stop();
    assert.equal(f.progress.status, 'stopping');
    assert.equal(f.progress.completed, 0);
    f.runner.resume(); // Cannot overlap a request still finishing.
    assert.deepEqual(f.calls, [0]);
    await f.finish();
    assert.equal(f.progress.status, 'stopped');
    assert.equal(f.progress.completed, 1);
    assert.equal(f.timers.size, 0);
});

test('stop cancels the delay before the next request', async () => {
    const f = fixture(); f.runner.resume(); await f.finish();
    assert.equal(f.timers.size, 1);
    f.runner.stop();
    assert.equal(f.progress.status, 'stopped');
    assert.equal(f.timers.size, 0);
    assert.deepEqual(f.calls, [0]);
});

test('resume continues with the remaining repositories without repeating results', async () => {
    const f = fixture(); f.runner.resume(); f.runner.stop(); await f.finish();
    f.runner.resume();
    f.runner.resume();
    assert.deepEqual(f.calls, [0, 1]);
    await f.finish(); f.advance(); await f.finish();
    assert.deepEqual(f.calls, [0, 1, 2]);
    assert.equal(f.progress.status, 'complete');
    assert.equal(f.progress.completed, 3);
    assert.equal(f.timers.size, 0);
    f.runner.resume(); f.runner.stop();
    assert.deepEqual(f.calls, [0, 1, 2]);
});

test('a failed in-flight request still honors a pending stop', async () => {
    const f = fixture(); f.runner.resume(); f.runner.stop();
    f.reject(new Error('Lost response'));
    await settle();
    assert.equal(f.progress.status, 'stopped');
    assert.equal(f.progress.completed, 1);
    assert.deepEqual(f.errors, [0]);
    assert.equal(f.timers.size, 0);
});

test('a failed check does not prevent later repositories being checked', async () => {
    const f = fixture(2); f.runner.resume(); f.reject(new Error('Offline'));
    await settle();
    f.advance(); await f.finish();
    assert.deepEqual(f.calls, [0, 1]);
    assert.equal(f.progress.status, 'complete');
});

test('stopping the final request reports completion once its result settles', async () => {
    const f = fixture(1); f.runner.resume(); f.runner.stop(); await f.finish();
    assert.equal(f.progress.status, 'complete');
    assert.equal(f.progress.completed, 1);
    assert.equal(f.timers.size, 0);
});

test('empty queues complete without requests', () => {
    const f = fixture(0); f.runner.resume(); f.runner.stop();
    assert.equal(f.progress.status, 'complete');
    assert.deepEqual(f.calls, []);
});
