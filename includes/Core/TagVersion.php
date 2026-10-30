<?php

namespace RRZE\Updater\Core;

defined('ABSPATH') || exit;

/** Compare version tags without changing the Git references used for downloads. */
final class TagVersion
{
    /**
     * Extract a numeric version after an optional alphabetic tag prefix.
     *
     * @param string $tag Original tag, for example v2.1.0 or release/2.1.0-rc.1.
     * @return string Comparable version, or an empty string for arbitrary names.
     */
    public static function normalize(string $tag): string
    {
        if (!preg_match('~^(?:[a-zA-Z][a-zA-Z._/-]*)?(\d+(?:\.\d+)*(?:[-._]?[a-zA-Z][a-zA-Z0-9.-]*)?)(?:\+[a-zA-Z0-9.-]+)?$~D', trim($tag), $matches)) {
            return '';
        }
        return strtolower($matches[1]);
    }

    /**
     * Recognize a version suffix as a prerelease when no release flag is present.
     *
     * @param string $tag Original Git tag.
     * @return bool Whether a numeric version has a non-stable suffix.
     */
    public static function isPrerelease(string $tag): bool
    {
        $version = self::normalize($tag);
        return $version !== '' && (bool) preg_match('/[a-z]/i', $version)
            && !preg_match('/(?:^|[._-])(?:pl|p)\d*$/i', $version);
    }
}
