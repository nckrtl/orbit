<?php

declare(strict_types=1);

namespace App\Infrastructure\Fleet\Footprint;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The Gateway source files that a set of classes renders from: every class in the start directories, and
 * every `App\` class they import or name in their own namespace, followed transitively. Models, actions,
 * controllers, commands, and providers are data or callers, not rendering code, so the walk stops there.
 */
final class SourceClosure
{
    /** @var list<string> */
    public const array StopNamespaces = ['App\\Models\\', 'App\\Actions\\', 'App\\Http\\', 'App\\Console\\', 'App\\Providers\\'];

    /** @var array<string, list<string>> */
    private static array $cache = [];

    /**
     * @param  list<string>  $directories  Directories relative to the Gateway's base path, such as `app/Infrastructure/Caddy`.
     * @return list<string> Paths relative to the base path, sorted.
     */
    public static function of(array $directories): array
    {
        $key = implode("\0", $directories);

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $pending = [];

        foreach ($directories as $directory) {
            $absolute = base_path($directory);

            if (! is_dir($absolute)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $pending[] = self::className($directory.substr($file->getPathname(), strlen($absolute)));
                }
            }
        }

        $files = [];
        $seen = [];

        while ($pending !== []) {
            $class = array_pop($pending);

            if ($class === null || isset($seen[$class])) {
                continue;
            }

            $seen[$class] = true;
            $path = self::path($class);
            $source = @file_get_contents(base_path($path));

            if (! is_string($source)) {
                continue;
            }

            $files[] = $path;

            foreach (self::references($source) as $reference) {
                if (! isset($seen[$reference])) {
                    $pending[] = $reference;
                }
            }
        }

        sort($files, SORT_STRING);

        return self::$cache[$key] = array_values(array_unique($files));
    }

    /** @return list<string> */
    private static function references(string $source): array
    {
        $namespace = preg_match('/^namespace ([^;]+);/m', $source, $match) === 1 ? $match[1] : 'App';
        preg_match_all('/^use (App\\\\[A-Za-z0-9_\\\\]+)(?: as \w+)?;/m', $source, $imports);
        preg_match_all('/\b([A-Z][A-Za-z0-9_]+)\b/', $source, $words);
        $references = $imports[1];

        foreach (array_unique($words[1]) as $word) {
            $candidate = $namespace.'\\'.$word;

            if (is_file(base_path(self::path($candidate)))) {
                $references[] = $candidate;
            }
        }

        return array_values(array_filter(
            array_unique($references),
            static fn (string $class): bool => str_starts_with($class, 'App\\') && ! array_any(self::StopNamespaces, static fn (string $stop): bool => str_starts_with($class, $stop)),
        ));
    }

    private static function path(string $class): string
    {
        return 'app/'.str_replace('\\', '/', substr($class, 4)).'.php';
    }

    private static function className(string $path): ?string
    {
        return str_starts_with($path, 'app/') && str_ends_with($path, '.php')
            ? 'App\\'.str_replace('/', '\\', substr($path, 4, -4))
            : null;
    }
}
