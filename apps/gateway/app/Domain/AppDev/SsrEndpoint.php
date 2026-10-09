<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

/** The environment that hands a development Instance its assigned Inertia SSR port. */
final readonly class SsrEndpoint
{
    public const string PORT_KEY = 'ORBIT_SSR_PORT';

    public const string URL_KEY = 'INERTIA_SSR_URL';

    /** @return array{ORBIT_SSR_PORT: string, INERTIA_SSR_URL: string} */
    public static function environment(int $port): array
    {
        return [self::PORT_KEY => (string) $port, self::URL_KEY => 'http://127.0.0.1:'.$port];
    }
}
