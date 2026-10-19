import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

export async function request(operation, values = {}, signal) {
    const config = window.rrzeUpdaterBundles;
    const body = new URLSearchParams({ action: config.action, network: config.network, nonce: config.nonce, operation });
    for (const [key, value] of Object.entries(values)) {
        if (key === 'connectors') {
            for (const [provider, id] of Object.entries(value)) body.set(`connectors[${provider}]`, id);
        } else body.set(key, ['selection', 'registrations'].includes(key) ? JSON.stringify(value) : value);
    }
    const response = await apiFetch({ url: config.url, method: 'POST', body, signal, parse: false });
    const data = await response.json();
    if (!response.ok || !data.success) throw new Error(data.data?.message || __('The request failed. Reload to reconcile saved progress.', 'rrze-updater'));
    return data.data;
}

export async function allPages(operation, values, signal, onProgress = () => {}) {
    const items = [];
    for (let page = 1; page <= 1000; page++) {
        const result = await request(operation, { ...values, page }, signal);
        items.push(...result.items);
        onProgress(items.length);
        if (!result.has_more) return items;
    }
    throw new Error(__('This listing is too large to load completely. Use a connector with a narrower owner scope.', 'rrze-updater'));
}
