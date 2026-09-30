<?php

declare(strict_types=1);

namespace GalahadXVI\OctaneGuard;

require_once __DIR__.'/guard-exception.php';

/**
 * Resolve the working application without losing a supplied deployment symlink.
 */
final class ApplicationPath
{
    /**
     * Prefer an explicit site path, otherwise identify the current directory.
     */
    public static function resolve(?string $explicit_path, string $script_path): string
    {
        if ($explicit_path !== null)
            return rtrim($explicit_path, '/');

        $working_directory = getcwd();

        if ($working_directory === false || !is_file($working_directory.'/artisan'))
            throw new GuardException('Set the Forge Working Directory to the application root containing artisan, or supply --app-dir=/absolute/site.');

        # PHP resolves cwd symlinks, but argv[0] retains an absolute launch path.
        if (str_starts_with($script_path, '/')) {
            $candidate = dirname($script_path);

            while ($candidate !== '/') {
                if (realpath($candidate) === $working_directory)
                    return $candidate;

                $candidate = dirname($candidate);
            }
        }

        # A shell may retain the logical path when invoking vendor/bin relatively.
        $logical_directory = getenv('PWD');

        if ($logical_directory !== false && str_starts_with($logical_directory, '/') && realpath($logical_directory) === $working_directory)
            return rtrim($logical_directory, '/');

        return $working_directory;
    }
}
