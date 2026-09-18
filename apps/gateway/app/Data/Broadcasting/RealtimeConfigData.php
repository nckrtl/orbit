<?php

declare(strict_types=1);

namespace App\Data\Broadcasting;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * What a CLI needs to connect to Reverb on its own: the WebSocket URL, the
 * Reverb app key, and the one channel every record event broadcasts on.
 * `url` and `key` are null when broadcasting is not configured.
 */
#[MapOutputName(SnakeCaseMapper::class)]
final class RealtimeConfigData extends Data
{
    public function __construct(
        public ?string $url,
        public ?string $key,
        public string $channel,
    ) {}
}
