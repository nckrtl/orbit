<?php

declare(strict_types=1);

namespace App\Domain\Hibernation;

use App\Models\Instance;
use InvalidArgumentException;

final readonly class RuntimeHibernation
{
    public const string MarkerDirectory = '/dev/shm/orbit/hibernation';

    /**
     * Header a measurement carries so a development site neither wakes for it nor counts it as
     * the activity that keeps it awake. `orbit profile --instance` sends it; the rendered Caddy
     * site honours it (see DevelopmentCaddyConfigRenderer).
     */
    public const string ProbeHeader = 'X-Orbit-Probe';

    public const string AccessLogDirectory = '/data/caddy/orbit/hibernation';

    public const int DefaultIdleSeconds = 3_600;

    public const int DefaultSweepSeconds = 600;

    public const int DefaultWakeTimeoutSeconds = 60;

    public const int DefaultDependencyIdleSeconds = 604_800;

    public const int DefaultColdWakeTimeoutSeconds = 1_800;

    public const string ActivationType = 'app-instance';

    public static function key(int $instanceId): string
    {
        if ($instanceId < 1) {
            throw new InvalidArgumentException('A hibernation key needs a positive Instance ID.');
        }

        return 'app-instance-'.$instanceId;
    }

    public static function parseInstanceId(string $key): int
    {
        if (preg_match('/\Aapp-instance-([1-9][0-9]*)\z/D', $key, $matches) !== 1) {
            throw new InvalidArgumentException('A hibernation key must be app-instance-{id}.');
        }

        return (int) $matches[1];
    }

    public static function awakePath(string $key): string
    {
        self::parseInstanceId($key);

        return self::MarkerDirectory.'/'.$key.'.awake';
    }

    public static function accessLogPath(string $key): string
    {
        self::parseInstanceId($key);

        return self::AccessLogDirectory.'/'.$key.'.log';
    }

    public static function coldPath(string $key): string
    {
        self::parseInstanceId($key);

        return self::AccessLogDirectory.'/'.$key.'.cold';
    }
}
