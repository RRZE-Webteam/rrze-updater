/** DataViews reports selections for its visible page, not the entire basket. */
export function mergeRepositorySelection(selected, rows, visibleRows, ids) {
    const visible = new Set(visibleRows.map(item => item.id));
    const previous = new Map(selected.map(item => [item.id, item]));
    const available = new Map(rows.map(item => [item.id, item]));
    const combined = new Set([...selected.filter(item => !visible.has(item.id)).map(item => item.id), ...ids]);
    return [...combined].map(id => previous.get(id) || available.get(id)).filter(Boolean)
        .map(item => ({ ...item, folder: item.folder ?? item.repository }));
}
