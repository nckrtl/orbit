<?php

declare(strict_types=1);

namespace App\Domain\Broadcasting;

use SensitiveParameter;

/**
 * The Reverb connection the Gateway's own `reverb` broadcaster and its
 * `GET /api/v1/realtime` response are both configured from, resolved from
 * the active websocket role assignment rather than static environment
 * configuration.
 */
final readonly class RealtimeConnectionData
{
    public function __construct(
        public string $host,
        public int $port,
        public string $scheme,
        public string $appId,
        public string $key,
        #[SensitiveParameter]
        public string $secret,
        public string $caCertificatePath,
        /**
         * Address the Gateway connects to for `host`. The Gateway host does not use Orbit's
         * private DNS, so it cannot resolve `reverb.orbit` by name.
         */
        public ?string $resolveAddress = null,
    ) {}

    public function url(): string
    {
        return "wss://{$this->host}";
    }
}
