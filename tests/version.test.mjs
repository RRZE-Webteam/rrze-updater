import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { copyFileSync, existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const root = dirname(dirname(fileURLToPath(import.meta.url)));
const readJson = (path) => JSON.parse(readFileSync(path, 'utf8'));
const writeJson = (path, value) => writeFileSync(path, JSON.stringify(value));

function fixture(t, version, lockVersion = 3) {
    const directory = mkdtempSync(join(tmpdir(), 'rrze-updater-version-'));
    t.after(() => rmSync(directory, { recursive: true, force: true }));
    for (const name of ['package.json', 'rrze-updater.php', 'readme.txt']) {
        copyFileSync(join(root, name), join(directory, name));
    }
    const pkg = readJson(join(directory, 'package.json'));
    pkg.version = version;
    writeJson(join(directory, 'package.json'), pkg);
    if (lockVersion) {
        const lock = readJson(join(root, 'package-lock.json'));
        lock.version = '1.0.0'; // A stale lock must also be repaired by the next bump.
        lock.lockfileVersion = lockVersion;
        if (lockVersion === 1) {
            delete lock.packages;
            lock.dependencies = { fixture: { version: '1.2.3', integrity: 'unchanged' } };
        } else {
            lock.packages[''].version = '1.0.0';
        }
        writeJson(join(directory, 'package-lock.json'), lock);
    }
    return directory;
}

for (const [mode, current, expected] of [
    ['dev', '2.5.17', '2.5.17-1'],
    ['dev', '2.5.17-2', '2.5.17-3'],
    ['prod', '2.5.17-3', '2.5.18'],
    ['release', '2.5.17', '2.6.0'],
]) {
    test(`${mode} keeps package, lockfile and WordPress versions synchronized`, (t) => {
        const directory = fixture(t, current);
        const before = readJson(join(directory, 'package-lock.json'));
        execFileSync(process.execPath, [join(root, 'scripts/build-version.js'), mode], { cwd: directory });
        assert.equal(readJson(join(directory, 'package.json')).version, expected);
        const after = readJson(join(directory, 'package-lock.json'));
        assert.equal(after.version, expected);
        assert.equal(after.packages[''].version, expected);
        before.version = expected;
        before.packages[''].version = expected;
        assert.deepEqual(after, before, 'Dependency resolutions and integrity data stay unchanged');
        for (const name of ['rrze-updater.php', 'readme.txt']) {
            const content = readFileSync(join(directory, name), 'utf8');
            const versions = [...content.matchAll(/^\s*(?:\*\s*)?(?:Version|Stable tag):\s*(.+)$/gm)];
            assert.ok(versions.length, `${name} has a version header`);
            for (const match of versions) assert.equal(match[1].trim(), expected);
        }
    });
}

for (const lockVersion of [1, null]) {
    test(`version bump supports ${lockVersion ? 'legacy' : 'absent'} lockfiles`, (t) => {
        const directory = fixture(t, '2.5.17', lockVersion);
        execFileSync(process.execPath, [join(root, 'scripts/build-version.js'), 'prod'], { cwd: directory });
        assert.equal(readJson(join(directory, 'package.json')).version, '2.5.18');
        const lockPath = join(directory, 'package-lock.json');
        if (lockVersion) {
            const lock = readJson(lockPath);
            assert.equal(lock.version, '2.5.18');
            assert.equal(lock.packages, undefined);
            assert.deepEqual(lock.dependencies, { fixture: { version: '1.2.3', integrity: 'unchanged' } });
        } else {
            assert.equal(existsSync(lockPath), false);
        }
    });
}
