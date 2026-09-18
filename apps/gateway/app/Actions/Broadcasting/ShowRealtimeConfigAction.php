<?php

declare(strict_types=1);

namespace App\Actions\Broadcasting;

use App\Data\Broadcasting\RealtimeConfigData;

final readonly class ShowRealtimeConfigAction
{
    public function execute(): RealtimeConfigData
    {
        /** @var mixed $host */
        $host = config('broadcasting.connections.reverb.options.host');
        /** @var mixed $key */
        $key = config('broadcasting.connections.reverb.key');

        $configured = config('broadcasting.default') === 'reverb'
            && is_string($host) && $host !== ''
            && is_string($key) && $key !== '';

        return new RealtimeConfigData(
            url: $configured ? "wss://{$host}" : null,
            key: $configured ? $key : null,
            channel: 'orbit',
        );
    }
}
