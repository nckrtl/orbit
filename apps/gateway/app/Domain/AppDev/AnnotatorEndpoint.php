<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use App\Models\Instance;

final readonly class AnnotatorEndpoint
{
    public const string PATH = '/__orbit/annotator';

    public const int PORT = 4848;

    public const string URL_KEY = 'ANNOTATOR_URL';

    public const string PORT_KEY = 'ORBIT_ANNOTATOR_PORT';

    public const string STORED_URL = 'https://{{instance.domain}}'.self::PATH.'/annotations';

    public static function origin(string $domain): string
    {
        return 'https://'.$domain.self::PATH;
    }

    public static function queue(string $domain): string
    {
        return self::origin($domain).'/annotations';
    }

    public static function forInstance(Instance $instance, ?string $app = null): string
    {
        $name = $instance->appConfiguration($app)['name'];

        return self::store($instance->id, $instance->usesAppStoreIdentity($name) ? $name : null);
    }

    public static function store(int $instanceId, ?string $app = null): string
    {
        if ($instanceId < 1) {
            throw new \InvalidArgumentException('An annotator store requires a persisted Instance.');
        }

        return '/var/lib/orbit/annotator/instance-'.$instanceId.($app === null ? '' : '-'.$app);
    }
}
