<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

final readonly class DevelopmentServerEndpoint
{
    public const string PATH = '/__orbit/vite';

    public const string LOOPBACK_HOST = '127.0.0.1';

    public const int PORT = 5173;

    public static function origin(string $hostname): string
    {
        return 'https://'.$hostname.self::PATH;
    }

    public static function upstream(): string
    {
        return self::LOOPBACK_HOST.':'.self::PORT;
    }
}
