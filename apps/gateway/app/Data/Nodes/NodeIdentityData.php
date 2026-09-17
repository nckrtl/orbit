<?php

declare(strict_types=1);

namespace App\Data\Nodes;

use App\Models\Node;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** The identity of a related Node, so a client can name it without a second request. */
#[MapOutputName(SnakeCaseMapper::class)]
final class NodeIdentityData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}

    public static function fromModel(Node $node): self
    {
        return new self(id: $node->id, name: $node->name);
    }
}
