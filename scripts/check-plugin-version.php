<?php
// Compare plugin headers without loading either plugin or WordPress.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

function readPluginVersion(string $file, string $label): string
{
    if (!is_file($file) || !is_readable($file)) {
        throw new RuntimeException("Cannot read the $label plugin file.");
    }

    // WordPress reads plugin headers from the first 8 KiB of a file.
    $contents = file_get_contents($file, false, null, 0, 8192);
    if ($contents === false) {
        throw new RuntimeException("Cannot read the $label plugin file.");
    }
    $contents = str_replace("\r", "\n", $contents);
    $count = preg_match_all('/^[ \t\/*#@]*Version:[ \t]*([^\n]*)/mi', $contents, $matches);
    if ($count !== 1) {
        throw new RuntimeException("Expected exactly one Version header in the $label plugin file.");
    }

    $version = trim(preg_replace('/\s*(?:\*\/|\?>).*/', '', $matches[1][0]));
    if (!preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?\z/', $version)) {
        throw new RuntimeException("Invalid Version header in the $label plugin file; expected X.Y.Z with an optional prerelease suffix.");
    }
    return $version;
}

try {
    if ($argc !== 3) {
        throw new RuntimeException('Usage: php scripts/check-plugin-version.php MAIN_PLUGIN_FILE PR_PLUGIN_FILE');
    }
    $mainVersion = readPluginVersion($argv[1], 'main');
    $prVersion = readPluginVersion($argv[2], 'pull request');
    echo "Plugin version on main: $mainVersion\nPull request plugin version: $prVersion\n";

    // Use the same version ordering as WordPress/PHP, including numeric components.
    if (!version_compare($prVersion, $mainVersion, '>')) {
        throw new RuntimeException(
            "Plugin version $prVersion must be higher than main's $mainVersion. Bump the version before merging."
        );
    }
    echo "Plugin version increase verified.\n";
} catch (RuntimeException $error) {
    // Escape annotation control characters, even when invoked with unexpected inputs.
    $message = str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $error->getMessage());
    fwrite(STDERR, "::error::$message\n");
    exit(1);
}
