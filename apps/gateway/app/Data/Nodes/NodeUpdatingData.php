<?php

declare(strict_types=1);

namespace App\Data\Nodes;

use App\Domain\Nodes\NodeUpdateKind;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** The update in progress on a Node. `rollout` is set for a fleet rollout visit, `release` for a Gateway release. */
#[MapOutputName(SnakeCaseMapper::class)]
final class NodeUpdatingData extends Data
{
    public function __construct(
        public NodeUpdateKind $kind,
        public string $since,
        public ?int $rollout = null,
        public ?int $release = null,
    ) {}
}
