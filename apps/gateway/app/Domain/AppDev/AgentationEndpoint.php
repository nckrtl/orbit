<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

final readonly class AgentationEndpoint
{
    public const string PATH = '/__orbit/agentation';

    public const string LOOPBACK_HOST = '127.0.0.1';

    public const int PORT = 4747;

    public const string URL_KEY = 'AGENTATION_URL';

    public const string PORT_KEY = 'ORBIT_AGENTATION_PORT';

    public const string STORED_URL = 'https://{{app_instance.domain}}'.self::PATH;

    public static function origin(string $domain): string
    {
        return 'https://'.$domain.self::PATH;
    }

    public static function upstream(?int $port = null): string
    {
        return self::LOOPBACK_HOST.':'.($port ?? self::PORT);
    }
}
