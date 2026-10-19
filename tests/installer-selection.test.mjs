import test from 'node:test';
import assert from 'node:assert/strict';
import { mergeRepositorySelection } from '../src/installer/selection.mjs';

const rows = ['a', 'b', 'c'].map(id => ({ id, repository: `repo-${id}`, branch: 'main' }));
const ids = selected => selected.map(item => item.id);

test('selecting and deselecting on another page preserves the existing basket', () => {
    let selected = mergeRepositorySelection([], rows, [rows[0]], ['a']);
    selected = mergeRepositorySelection(selected, rows, [rows[1]], ['b']);
    assert.deepEqual(ids(selected), ['a', 'b']);
    selected = mergeRepositorySelection(selected, rows, [rows[1]], []);
    assert.deepEqual(ids(selected), ['a']);
});

test('select-all and deselect-all only change visible search results', () => {
    let selected = [rows[0]];
    selected = mergeRepositorySelection(selected, rows, rows.slice(1), ['b', 'c']);
    assert.deepEqual(ids(selected), ['a', 'b', 'c']);
    selected = mergeRepositorySelection(selected, rows, rows.slice(1), []);
    assert.deepEqual(ids(selected), ['a']);
});

test('selection changes preserve edited branches and folders without duplicate IDs', () => {
    const selected = [{ ...rows[0], branch: 'feature/a', folder: 'custom-a' }];
    const result = mergeRepositorySelection(selected, rows, [rows[1]], ['a', 'b', 'a']);
    assert.deepEqual(ids(result), ['a', 'b']);
    assert.deepEqual(result[0], selected[0]);
    assert.equal(result[1].folder, 'repo-b');
});

test('restored selections survive filtered or incomplete provider results', () => {
    const restored = { id: 'saved', repository: 'saved', branch: 'release', folder: 'saved-folder' };
    const result = mergeRepositorySelection([restored], rows, [rows[0]], ['a', 'unknown']);
    assert.deepEqual(ids(result), ['saved', 'a']);
    assert.deepEqual(result[0], restored);
});
