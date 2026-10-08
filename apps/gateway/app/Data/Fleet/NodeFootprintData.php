<?php

declare(strict_types=1);

namespace App\Data\Fleet;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** The result of `node:converge`: each footprint artifact, `applied` or `unchanged`, and the footprint digest. */
#[MapOutputName(SnakeCaseMapper::class)]
final class NodeFootprintData extends Data
{
    /** @param array<string, string> $artifacts */
    public function __construct(
        public int $nodeId,
        public string $node,
        public array $artifacts,
        public string $digest,
        public bool $changed,
    ) {}
}
