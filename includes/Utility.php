<?php

namespace RRZE\Updater;

defined('ABSPATH') || exit;

/**
 * Utility Class for Common Operations
 *
 * The `Utility` class provides a set of static utility methods for performing
 * common operations in the context of WordPress development.
 */
class Utility
{
    /**
     * Generates a Unique String
     *
     * This method generates a unique hexadecimal string of a specified length.
     *
     * @param int $length (Optional) The desired length of the unique string. Default is 8.
     * @return string A generated unique hexadecimal string.
     */
    public static function uniqid(int $length = 8): string
    {
        // Ensures that the minimum length is 4 characters.
        $length = ($length < 4) ? 4 : $length;

        // Generates a unique hexadecimal string using random_bytes.
        return bin2hex(
            random_bytes(
                ($length - ($length % 2)) / 2
            )
        );
    }

    /**
     * Check if a directory corresponds to a Git repository by looking for the presence of a .git directory.
     *
     * @param string $directory The path to the directory to be checked.
     * @return bool Returns true if the directory is a Git repository, and false if it is not.
     */
    public static function isGitRepository($directory)
    {
        // Define the path to the .git directory within the target directory.
        $gitDirectory = $directory . '/.git';

        // Check if the .git directory exists within the target directory.
        return is_dir($gitDirectory);
    }
}
