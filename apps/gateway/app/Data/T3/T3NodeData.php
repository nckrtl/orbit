<?php

declare(strict_types=1);

namespace App\Data\T3;

use App\Models\Node;
use App\Models\T3ProfileBinding;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** The calling Node and the T3 profile it is bound to, or null before it binds. */
#[MapOutputName(SnakeCaseMapper::class)]
final class T3NodeData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $wireguardIp,
        public ?T3ProfileData $profile,
    ) {}

    public static function fromModel(Node $node): self
    {
        $binding = T3ProfileBinding::query()->with('profile')->where('node_id', $node->id)->first();

        return new self(
            id: $node->id,
            name: $node->name,
            wireguardIp: $node->wireguard_ip,
            profile: $binding === null ? null : T3ProfileData::fromModel($binding->profile),
        );
    }
}
