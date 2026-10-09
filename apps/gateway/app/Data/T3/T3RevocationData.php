<?php

declare(strict_types=1);

namespace App\Data\T3;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class T3RevocationData extends Data
{
    /** @param list<T3PairingData> $pairings */
    public function __construct(
        public string $environmentId,
        public int $nodeId,
        public int $revokedSessions,
        public array $pairings,
    ) {}
}
