import { build } from 'esbuild';
import { mkdir, writeFile } from 'node:fs/promises';
import { createHash } from 'node:crypto';

// WordPress supplies React and public packages. DataViews' /wp distribution
// bundles its private implementation to avoid coupling to core private APIs.
const dependencies = new Set();
const globals = {
    react: ['React', 'react'],
    'react-dom': ['ReactDOM', 'react-dom'],
    'react-dom/client': ['ReactDOM', 'react-dom'],
    'react/jsx-runtime': ['ReactJSXRuntime', 'react-jsx-runtime'],
};
const wordpress = {
    name: 'wordpress-runtime',
    setup(builder) {
        builder.onResolve({ filter: /^(react(?:-dom)?(?:\/.*)?|@wordpress\/[^/]+)$/ }, ({ path }) => {
            if (path === '@wordpress/dataviews') throw new Error('Import @wordpress/dataviews/wp for its isolated private APIs.');
            return { path, namespace: 'wordpress' };
        });
        builder.onLoad({ filter: /.*/, namespace: 'wordpress' }, ({ path }) => {
            const name = path.replace('@wordpress/', '');
            const [global, handle] = globals[path] ?? [`wp.${name.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase())}`, `wp-${name}`];
            dependencies.add(handle);
            return { contents: `module.exports = window.${global};`, loader: 'js' };
        });
    },
};
const result = await build({
    entryPoints: ['src/installer/index.jsx'], outfile: 'build/installer.js', bundle: true,
    write: false, minify: true, target: ['es2020'], format: 'iife', jsx: 'automatic',
    plugins: [wordpress], define: { 'process.env.NODE_ENV': '"production"' }, metafile: true,
});
await mkdir('build', { recursive: true });
for (const file of result.outputFiles) await writeFile(file.path, file.contents);
const hash = createHash('sha256');
for (const file of result.outputFiles) hash.update(file.contents);
await writeFile('build/installer.asset.php', `<?php return ['dependencies' => [${[...dependencies].sort().map(d => `'${d}'`).join(', ')}], 'version' => '${hash.digest('hex').slice(0, 16)}'];\n`);
console.log('Built installer using WordPress runtime:', [...dependencies].sort().join(', '));
