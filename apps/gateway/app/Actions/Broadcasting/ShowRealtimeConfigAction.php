<?php

declare(strict_types=1);

namespace App\Actions\Broadcasting;

use App\Data\Broadcasting\RealtimeConfigData;
use App\Domain\Broadcasting\RealtimeConnection;

final readonly class ShowRealtimeConfigAction
{
    public function __construct(
        private RealtimeConnection $realtime,
    ) {}

    public function execute(): RealtimeConfigData
    {
        $connection = $this->realtime->resolve();

        return new RealtimeConfigData(
            url: $connection?->url(),
            key: $connection?->key,
            channel: 'orbit',
        );
    }
}
