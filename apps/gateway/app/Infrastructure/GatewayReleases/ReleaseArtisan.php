<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

/**
 * An artisan command line for a release that starts with a clean environment. A release reads its configuration
 * only from the shared env file. The process that runs a release step may have loaded another env file into its
 * environment, such as a temporary checkout that adopts, and a child would inherit those values: `config:cache`
 * would then bake them into the release, because Laravel never overrides a variable that is already set.
 */
final class ReleaseArtisan
{
    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment  variables the command needs besides HOME, PATH, and LANG
     * @return non-empty-list<string>
     */
    public static function command(string $php, string $artisan, array $arguments, array $environment = []): array
    {
        $variables = [
            'HOME' => self::inherited('HOME', '/home/orbit'),
            'PATH' => self::inherited('PATH', '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'),
            'LANG' => 'C.UTF-8',
            ...$environment,
        ];

        return [
            'env',
            '-i',
            ...array_map(static fn (string $name, string $value): string => $name.'='.$value, array_keys($variables), $variables),
            $php,
            $artisan,
            ...$arguments,
        ];
    }

    private static function inherited(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
