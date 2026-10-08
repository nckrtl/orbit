<?php

declare(strict_types=1);

namespace App\Domain\Releases;

/**
 * Posts one alert body to the configured receiver within a bounded time. It never throws.
 */
interface ReleaseAlertWebhook
{
    /** @param array<string, mixed> $payload */
    public function send(array $payload): ReleaseAlertStep;
}
