<?php

declare(strict_types=1);

namespace App\Support\Realtime;

use SensitiveParameter;

/** A private channel name and the Pusher `auth` signature that lets one socket subscribe to it. */
final readonly class RealtimeChannelGrant
{
    public function __construct(
        public string $channel,
        #[SensitiveParameter]
        public string $auth,
    ) {}

    /** @return array{channel: string} */
    public function __debugInfo(): array
    {
        return ['channel' => $this->channel];
    }
}
