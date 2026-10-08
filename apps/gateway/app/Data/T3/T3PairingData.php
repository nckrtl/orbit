<?php

declare(strict_types=1);

namespace App\Data\T3;

use App\Models\T3Pairing;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class T3PairingData extends Data
{
    public function __construct(
        public int $id,
        public string $environmentId,
        public int $nodeId,
        public string $nodeName,
        public string $clientLabel,
        public string $issuedAt,
        public string $expiresAt,
        public ?string $revokedAt,
    ) {}

    public static function fromModel(T3Pairing $pairing): self
    {
        $pairing->loadMissing(['environment', 'node']);

        return new self(
            id: $pairing->id,
            environmentId: $pairing->environment->environment_id,
            nodeId: $pairing->node_id,
            nodeName: $pairing->node->name,
            clientLabel: $pairing->client_label,
            issuedAt: $pairing->created_at->toIso8601String(),
            expiresAt: $pairing->expires_at->toIso8601String(),
            revokedAt: $pairing->revoked_at?->toIso8601String(),
        );
    }
}
