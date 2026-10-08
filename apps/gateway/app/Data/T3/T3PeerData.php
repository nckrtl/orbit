<?php

declare(strict_types=1);

namespace App\Data\T3;

use App\Models\T3Peer;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class T3PeerData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $wireguardIp,
        public ?string $nodeName,
        public ?int $profileId,
        public string $lastSeenAt,
    ) {}

    public static function fromModel(T3Peer $peer): self
    {
        $peer->loadMissing('node');

        return new self(
            id: $peer->id,
            name: $peer->displayName(),
            wireguardIp: $peer->wireguard_ip,
            nodeName: $peer->node?->name,
            profileId: $peer->t3_profile_id,
            lastSeenAt: $peer->last_seen_at->toIso8601String(),
        );
    }
}
