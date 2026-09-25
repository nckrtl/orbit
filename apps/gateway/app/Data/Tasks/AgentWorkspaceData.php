<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** One task checkout a Node agent watches (ADR 0151). */
#[MapOutputName(SnakeCaseMapper::class)]
final class AgentWorkspaceData extends Data
{
    public function __construct(
        public int $instanceId,
        public string $path,
        public string $base,
        public ?string $start,
    ) {}
}
