import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { runInNewContext } from 'node:vm';
import { setImmediate as settle } from 'node:timers/promises';

// Render the actual PHP view with no WordPress bootstrap, database or HTTP calls.
const html = execFileSync('php', ['-r', `
define('ABSPATH', '/fixture/');
function __($text, $domain = '') { return $text; }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_html($text) { return esc_attr($text); }
function esc_html_e($text, $domain = '') { echo esc_html($text); }
function wp_json_encode($value) { return json_encode($value); }
function admin_url($path) { return '/wp-admin/' . $path; }
$_REQUEST['page'] = 'rrze-updater';
$data = ['listTable' => new class {
    public function search_box(...$args) {}
    public function display() {}
}, 'updateCheckNonce' => 'fixture-nonce', 'updateCheckDelay' => 1,
'updateCheckItems' => [
    ['id' => 'one', 'type' => 'plugin', 'name' => 'One', 'repository' => 'owner/one', 'branch' => 'main'],
    ['id' => 'two', 'type' => 'theme', 'name' => 'Two', 'repository' => 'owner/two', 'branch' => 'main'],
]];
require $argv[1];
`, fileURLToPath(new URL('../includes/views/repositories/index.php', import.meta.url))], { encoding: 'utf8' });
const script = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)]
    .map(match => match[1]).find(value => value.includes('rrzeUpdaterRepositoryUpdateCheck'));

class Element {
    children = []; className = ''; textContent = ''; disabled = false; hidden = false; listeners = {};
    classList = { add() {}, remove() {} };
    appendChild(child) { this.children.push(child); }
    setAttribute() {}
    focus() {}
    set innerHTML(value) { this.children = []; }
    addEventListener(event, callback) { this.listeners[event] = callback; }
    click() { if (!this.disabled && !this.hidden) this.listeners.click(); }
    querySelector(selector) {
        for (const child of this.children) {
            if (child.className.split(' ').includes(selector.slice(1))) return child;
            const match = child.querySelector(selector);
            if (match) return match;
        }
        return null;
    }
}

test('the rendered dialog stops, preserves results, resumes, and refreshes on close', async () => {
    const nodes = new Map();
    for (const match of html.matchAll(/<[^>]+\bid="(rrze-updater-check-[^"]+)"[^>]*>/g)) {
        const node = new Element();
        node.disabled = /\bdisabled\b/.test(match[0]);
        node.hidden = /\bhidden\b/.test(match[0]);
        nodes.set(match[1], node);
    }
    const node = name => nodes.get('rrze-updater-check-' + name);
    const timers = new Map(); const requests = [];
    let reloads = 0; let resolve;
    const window = {
        setTimeout: callback => { timers.set(1, callback); return 1; },
        clearTimeout: id => timers.delete(id),
        location: { reload() { reloads++; } },
    };
    const context = {
        window, URLSearchParams,
        document: { getElementById: id => nodes.get(id), createElement: () => new Element() },
        fetch: (url, options) => {
            assert.equal(url, '/wp-admin/admin-ajax.php');
            assert.equal(options.body.get('nonce'), 'fixture-nonce');
            requests.push(options.body.get('id'));
            return new Promise(done => { resolve = result => done({ json: async () => result }); });
        },
    };
    runInNewContext(readFileSync(new URL('../assets/js/update-check-runner.js', import.meta.url), 'utf8'), context);
    runInNewContext(script, context);

    node('updates').click();
    assert.deepEqual(requests, ['one']);
    assert.equal(node('stop').hidden, false);
    node('stop').click();
    assert.equal(node('stop').disabled, true);
    assert.equal(node('close').disabled, true);
    assert.match(node('summary').textContent, /Stopping after the current/);
    resolve({ success: true, data: { name: 'One', repository: 'owner/one', branch: 'main', status: 'Update available', hasUpdate: true } });
    await settle();
    assert.equal(node('close').disabled, false);
    assert.equal(node('resume').hidden, false);
    assert.match(node('summary').textContent, /Process stopped\. 1 of 2/);
    assert.equal(timers.size, 0);
    assert.equal(node('list').children[0].querySelector('.rrze-updater-check-status-text').textContent, 'Update available');

    node('resume').click();
    assert.deepEqual(requests, ['one', 'two']);
    assert.equal(node('list').children.length, 2);
    resolve({ success: false, data: { message: 'Service unavailable' } });
    await settle();
    assert.equal(node('resume').hidden, true);
    assert.equal(node('stop').hidden, true);
    assert.equal(node('close').disabled, false);
    assert.equal(node('list').children[1].querySelector('.rrze-updater-check-status-text').textContent, 'Service unavailable');
    node('close').click();
    assert.equal(reloads, 1);
    assert.equal(timers.size, 0);
});
