<?php

declare(strict_types=1);

namespace App\Infrastructure\Fleet\Footprint;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The digest of Gateway source files: the code version of a renderer. It changes only when a Gateway release
 * changes that code, never when a user changes a site, a Route, or a DNS record.
 */
final class SourceDigest
{
    /** @var array<string, string> */
    private static array $cache = [];

    /** @param list<string> $paths  Files or directories relative to the Gateway's base path. */
    public static function of(array $paths): string
    {
        $key = implode("\0", $paths);

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $files = [];

        foreach ($paths as $path) {
            $absolute = base_path($path);

            if (is_file($absolute)) {
                $files[$path] = $absolute;

                continue;
            }

            if (! is_dir($absolute)) {
                $files[$path] = '';

                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof SplFileInfo && $file->isFile()) {
                    $files[$path.substr($file->getPathname(), strlen($absolute))] = $file->getPathname();
                }
            }
        }

        ksort($files, SORT_STRING);
        $digest = hash_init('sha256');

        foreach ($files as $relative => $absolute) {
            hash_update($digest, $relative."\0".($absolute === '' ? '' : (string) @file_get_contents($absolute))."\0");
        }

        return self::$cache[$key] = hash_final($digest);
    }
}
