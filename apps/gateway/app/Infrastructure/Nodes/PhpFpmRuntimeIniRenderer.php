<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use InvalidArgumentException;

/**
 * Renders the Orbit-managed OPcache runtime for one PHP-FPM service.
 *
 * OPcache shared memory belongs to the FPM master process, so sizing is a
 * per-version setting and cannot live in a pool. The file is published as a
 * Debian PHP module (`mods-available` plus `phpenmod`) for the fpm SAPI only.
 * Timestamp validation is a per-pool policy and stays in the pool renderers.
 */
final readonly class PhpFpmRuntimeIniRenderer
{
    public const string MODULE = 'orbit-runtime';

    public const int PRIORITY = 99;

    public const int MAX_ACCELERATED_FILES = 65_407;

    /** @return array{memory_consumption: int, interned_strings_buffer: int} */
    public static function sizing(string $profile): array
    {
        return match ($profile) {
            'app-dev' => ['memory_consumption' => 512, 'interned_strings_buffer' => 64],
            'app-prod' => ['memory_consumption' => 256, 'interned_strings_buffer' => 32],
            default => throw new InvalidArgumentException("Unknown PHP runtime profile [{$profile}]."),
        };
    }

    public function render(string $profile): string
    {
        $sizing = self::sizing($profile);
        $priority = self::PRIORITY;
        $files = self::MAX_ACCELERATED_FILES;

        return <<<INI
            ; priority={$priority}
            ; Managed by Orbit for the {$profile} PHP-FPM runtime. Orbit rewrites this file during convergence.
            ; OPcache shared memory is allocated once per FPM master, so sizing lives here.
            ; Timestamp validation is a per-pool policy in /etc/php/*/fpm/pool.d.
            opcache.enable = On
            opcache.memory_consumption = {$sizing['memory_consumption']}
            opcache.interned_strings_buffer = {$sizing['interned_strings_buffer']}
            opcache.max_accelerated_files = {$files}
            opcache.jit = disable
            opcache.jit_buffer_size = 0

            INI;
    }
}
