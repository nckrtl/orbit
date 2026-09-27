<?php

declare(strict_types=1);

namespace App\Data\Clusters;

use App\Models\Node;
use App\Support\ValidatedData;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class ClusterNodeData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $status,
        public ?string $wireguardIp,
        public ?string $lanIp,
    ) {}

    public static function fromModel(Node $node): self
    {
        $wireguardIp = $node->getAttribute('wireguard_ip');
        $lanIp = $node->getAttribute('lan_ip');

        return new self(
            id: $node->id,
            name: $node->name,
            status: $node->status->value,
            wireguardIp: ValidatedData::nullableString($wireguardIp),
            lanIp: ValidatedData::nullableString($lanIp),
        );
    }
}
