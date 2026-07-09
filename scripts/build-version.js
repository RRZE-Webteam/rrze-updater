/* eslint-disable no-console */
'use strict';

var fs = require('fs');
var path = require('path');

function readJson(filePath) {
    var raw = fs.readFileSync(filePath, 'utf8');
    return JSON.parse(raw);
}

function writeJson(filePath, obj) {
    var out = JSON.stringify(obj, null, 2) + '\n';
    fs.writeFileSync(filePath, out, 'utf8');
}

function parseSemver(version) {
    var m = version.match(/^(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z.-]+))?$/);
    if (!m) {
        throw new Error('Invalid semver: ' + version);
    }

    return {
        major: parseInt(m[1], 10),
        minor: parseInt(m[2], 10),
        patch: parseInt(m[3], 10),
        prerelease: m[4] || ''
    };
}

function formatSemver(v) {
    var base = String(v.major) + '.' + String(v.minor) + '.' + String(v.patch);
    if (v.prerelease) {
        return base + '-' + v.prerelease;
    }
    return base;
}

function bumpDev(version) {
    var v = parseSemver(version);

    if (!v.prerelease) {
        v.prerelease = '1';
        return formatSemver(v);
    }

    var m = v.prerelease.match(/^(\d+)$/);
    if (!m) {
        v.prerelease = '1';
        return formatSemver(v);
    }

    var n = parseInt(m[1], 10);
    v.prerelease = String(n + 1);

    return formatSemver(v);
}

function bumpProd(version) {
    var v = parseSemver(version);

    v.prerelease = '';
    v.patch = v.patch + 1;

    return formatSemver(v);
}

function bumpRelease(version) {
    var v = parseSemver(version);

    v.prerelease = '';
    v.minor = v.minor + 1;
    v.patch = 0;

    return formatSemver(v);
}

function replaceInFile(filePath, replacer) {
    var content = fs.readFileSync(filePath, 'utf8');
    var updated = replacer(content);

    if (updated !== content) {
        fs.writeFileSync(filePath, updated, 'utf8');
    }
}

function setReadmeTxtVersion(pluginRoot, newVersion) {
    var filePath = path.join(pluginRoot, 'readme.txt');
    var replacements = 0;

    if (!fs.existsSync(filePath)) {
        return;
    }

    replaceInFile(filePath, function replaceReadmeVersion(content) {
        content = content.replace(
            /^(Stable tag:\s*)(.+)$/m,
            function replaceStableTag(match, p1) {
                replacements++;
                return p1 + newVersion;
            }
        );

        content = content.replace(
            /^(Version:\s*)(.+)$/m,
            function replaceVersion(match, p1) {
                replacements++;
                return p1 + newVersion;
            }
        );

        return content;
    });

    if (replacements === 0) {
        throw new Error('No version field found in readme.txt');
    }
}

function setPluginVersion(pluginRoot, pkg, newVersion) {
    if (!pkg.main || typeof pkg.main !== 'string') {
        throw new Error('package.json has no valid "main" entry');
    }

    var filePath = path.join(pluginRoot, pkg.main);
    var replacements = 0;

    if (!fs.existsSync(filePath)) {
        throw new Error('Plugin main file not found: ' + filePath);
    }

    replaceInFile(filePath, function replacePluginVersion(content) {
        content = content.replace(
            /^(\s*(?:\*\s*)?Version:\s*)(.+)$/m,
            function replaceHeaderVersion(match, p1) {
                replacements++;
                return p1 + newVersion;
            }
        );

        return content;
    });

    if (replacements === 0) {
        throw new Error('No plugin header version field found in ' + pkg.main);
    }
}

function setPluginCompatibility(pluginRoot, pkg) {
    if (!pkg.main || typeof pkg.main !== 'string') {
        throw new Error('package.json has no valid "main" entry');
    }

    var compatibility = pkg.compatibility;
    var filePath = path.join(pluginRoot, pkg.main);

    if (!compatibility || typeof compatibility !== 'object') {
        return;
    }

    if (!fs.existsSync(filePath)) {
        throw new Error('Plugin main file not found: ' + filePath);
    }

    replaceInFile(filePath, function replacePluginCompatibility(content) {
        var updated = content;

        if (typeof compatibility.phprequires === 'string' && compatibility.phprequires.trim() !== '') {
            updated = updated.replace(
                /^(\s*(?:\*\s*)?Requires PHP:\s*)(.+)$/m,
                function replacePhpRequirement(match, p1) {
                    return p1 + compatibility.phprequires.trim();
                }
            );

            updated = updated.replace(
                /const\s+RRZE_PHP_VERSION\s*=\s*['"][^'"]*['"]\s*;/,
                function replacePhpConstant() {
                    return "const RRZE_PHP_VERSION = '" + compatibility.phprequires.trim() + "';";
                }
            );
        }

        if (typeof compatibility.wprequires === 'string' && compatibility.wprequires.trim() !== '') {
            updated = updated.replace(
                /^(\s*(?:\*\s*)?Requires at least:\s*)(.+)$/m,
                function replaceWpRequirement(match, p1) {
                    return p1 + compatibility.wprequires.trim();
                }
            );

            updated = updated.replace(
                /const\s+RRZE_WP_VERSION\s*=\s*['"][^'"]*['"]\s*;/,
                function replaceWpConstant() {
                    return "const RRZE_WP_VERSION = '" + compatibility.wprequires.trim() + "';";
                }
            );
        }

        return updated;
    });
}

function getNextVersion(mode, currentVersion) {
    if (mode === 'dev') {
        return bumpDev(currentVersion);
    }

    if (mode === 'prod') {
        return bumpProd(currentVersion);
    }

    if (mode === 'release') {
        return bumpRelease(currentVersion);
    }

    throw new Error('Unsupported mode: ' + mode);
}

function main() {
    var mode = process.argv[2];
    if (mode !== 'dev' && mode !== 'prod' && mode !== 'release') {
        console.error('Usage: node scripts/build-version.js dev|prod|release');
        process.exit(1);
    }

    var pluginRoot = process.cwd();
    var packagePath = path.join(pluginRoot, 'package.json');
    var pkg = readJson(packagePath);

    if (!pkg.version || typeof pkg.version !== 'string') {
        throw new Error('package.json has no valid version');
    }

    var current = pkg.version;
    var next = getNextVersion(mode, current);

    pkg.version = next;
    writeJson(packagePath, pkg);

    setReadmeTxtVersion(pluginRoot, next);
    setPluginVersion(pluginRoot, pkg, next);
    setPluginCompatibility(pluginRoot, pkg);

    console.log('Version bumped (' + mode + '): ' + current + ' -> ' + next);
}

main();
