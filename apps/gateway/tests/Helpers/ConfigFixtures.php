<?php

declare(strict_types=1);

/**
 * Loads a config file with the given environment keys unset, then restores them.
 *
 * @param  list<string>  $keys
 * @return array<string, mixed>
 */
function config_without_env(string $file, array $keys): array
{
    $saved = [];
    foreach ($keys as $key) {
        $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }

    try {
        /** @var array<string, mixed> */
        return require config_path($file);
    } finally {
        foreach ($saved as $key => [$env, $server, $process]) {
            if ($env !== null) {
                $_ENV[$key] = $env;
            }
            if ($server !== null) {
                $_SERVER[$key] = $server;
            }
            if ($process !== false) {
                putenv("{$key}={$process}");
            }
        }
    }
}
