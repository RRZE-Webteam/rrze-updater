import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const checker = fileURLToPath(new URL('../scripts/check-plugin-version.php', import.meta.url));
const plugin = version => `<?php\n/*\nPlugin Name: Fixture\nVersion: ${version}\n*/\nthrow new RuntimeException('Plugin code must never execute.');\n`;

function runCheck(t, main, candidate) {
    const directory = mkdtempSync(join(tmpdir(), 'rrze version check '));
    t.after(() => rmSync(directory, { recursive: true, force: true }));
    const mainFile = join(directory, 'main.php');
    const candidateFile = join(directory, 'candidate.php');
    if (main !== null) writeFileSync(mainFile, main);
    if (candidate !== null) writeFileSync(candidateFile, candidate);
    const result = spawnSync('php', [checker, mainFile, candidateFile], { encoding: 'utf8' });
    assert.ifError(result.error);
    return result;
}

for (const [main, candidate, passes] of [
    ['2.5.17', '2.5.18', true],
    ['2.5.17', '2.6.0', true],
    ['2.5.17', '3.0.0', true],
    ['2.5.9', '2.5.10', true],
    ['2.5.17', '2.5.17', false],
    ['2.5.17', '2.5.16', false],
    ['2.5.10', '2.5.9', false],
    ['2.6.0', '2.5.99', false],
    ['3.0.0', '2.99.99', false],
    ['2.6.0-rc.1', '2.6.0', true],
    ['2.6.0', '2.6.0-beta.1', false],
    // PHP/WordPress treats a numeric suffix as an additional version component.
    ['2.5.17-1', '2.5.17-2', true],
]) {
    test(`plugin version ${candidate} ${passes ? 'passes' : 'fails'} against main ${main}`, t => {
        const result = runCheck(t, plugin(main), plugin(candidate));
        assert.equal(result.status, passes ? 0 : 1, result.stdout + result.stderr);
        assert.ok(result.stdout.includes(`Plugin version on main: ${main}`));
        assert.ok(result.stdout.includes(`Pull request plugin version: ${candidate}`));
        if (!passes) assert.match(result.stderr, /::error::Plugin version .* must be higher/);
    });
}

for (const [name, invalid, message] of [
    ['missing file', null, /Cannot read/],
    ['missing header', '<?php /* Plugin Name: Fixture */', /exactly one Version header/],
    ['empty header', plugin(''), /Invalid Version header/],
    ['malformed header', plugin('new-release'), /Invalid Version header/],
    ['duplicate headers', plugin('2.5.18') + '\n// Version: 2.5.19\n', /exactly one Version header/],
    ['header outside the WordPress limit', ' '.repeat(8192) + plugin('2.5.18'), /exactly one Version header/],
]) {
    test(`fails closed for ${name} on either side`, t => {
        for (const inputs of [[invalid, plugin('2.5.18')], [plugin('2.5.17'), invalid]]) {
            const result = runCheck(t, ...inputs);
            assert.equal(result.status, 1);
            assert.match(result.stderr, message);
        }
    });
}

test('reads docblock headers and Windows line endings without executing plugin code', t => {
    const result = runCheck(t, plugin('2.5.17'), plugin('2.5.18')
        .replace('Version: 2.5.18', ' * Version: 2.5.18 */').replaceAll('\n', '\r\n'));
    assert.equal(result.status, 0, result.stderr);
});

test('checks the newly supplied main version each time', t => {
    assert.equal(runCheck(t, plugin('2.5.17'), plugin('2.5.18')).status, 0);
    assert.equal(runCheck(t, plugin('2.5.18'), plugin('2.5.18')).status, 1);
});
