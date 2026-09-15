<?php

declare(strict_types=1);

namespace App\Domain\Processes;

final readonly class VpDevPreset
{
    public const string NAME = 'vp-dev';

    /** @return list<string> */
    public static function command(): array
    {
        return ['/usr/local/bin/vp', 'dev', '--host=127.0.0.1', '--port=${ORBIT_DEV_SERVER_PORT}', '--strictPort', '--base=/__orbit/vite/'];
    }
}
