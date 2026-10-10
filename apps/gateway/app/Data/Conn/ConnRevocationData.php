<?php

declare(strict_types=1);

namespace App\Data\Conn;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class ConnRevocationData extends Data
{
    /** @param list<ConnPairingData> $pairings */
    public function __construct(
        public string $environmentId,
        public int $nodeId,
        public int $revokedSessions,
        public array $pairings,
    ) {}
}
