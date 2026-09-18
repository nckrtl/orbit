<?php

declare(strict_types=1);

namespace App\Support\Realtime;

use App\Data\GatewayProfile;

/**
 * The realtime endpoint and key resolved for one gateway profile, plus what its channel
 * authorization needs. resolve() returns null when realtime is not configured: no
 * `ORBIT_REALTIME_URL`/`ORBIT_REALTIME_KEY` environment override and no `realtime_url`/
 * `realtime_key` stored on the profile. Callers treat that as a signal to fall back to polling.
 */
final readonly class RealtimeConnectionConfig
{
    private function __construct(
        public string $socketUrl,
        public string $gatewayUrl,
        public ?string $caPath,
    ) {}

    public static function resolve(?GatewayProfile $profile, string $clientVersion): ?self
    {
        if ($profile === null) {
            return null;
        }

        $realtimeUrl = self::envOrProfileValue('ORBIT_REALTIME_URL', $profile->realtimeUrl);
        $realtimeKey = self::envOrProfileValue('ORBIT_REALTIME_KEY', $profile->realtimeKey);

        if ($realtimeUrl === null || $realtimeKey === null) {
            return null;
        }

        if (! GatewayProfile::hasSafeRealtimeUrl($realtimeUrl) || ! GatewayProfile::hasValidRealtimeKey($realtimeKey)) {
            return null;
        }

        $socketUrl = rtrim($realtimeUrl, '/').'/app/'.rawurlencode($realtimeKey)
            .'?protocol=7&client=orbit-cli&version='.rawurlencode($clientVersion);

        return new self($socketUrl, $profile->url, $profile->caPath);
    }

    private static function envOrProfileValue(string $variable, ?string $fallback): ?string
    {
        $value = getenv($variable);

        return is_string($value) && $value !== '' ? $value : $fallback;
    }
}
