<?php

declare(strict_types=1);

namespace App\Data\Tools;

use App\Domain\Tools\ToolObjectStatus;
use App\Domain\Tools\ToolOutcome;
use App\Models\Tool;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class ToolData extends Data
{
    public function __construct(
        public int $id,
        public int $nodeId,
        public string $manager,
        public string $package,
        public ?string $versionConstraint,
        public ToolObjectStatus $status,
        public ?string $installedVersion,
        public ?string $failedOperation,
        public ?string $errorCode,
        public ?string $outcome,
    ) {}

    public static function fromModel(Tool $tool, ?ToolOutcome $outcome = null, ?ToolObjectStatus $status = null): self
    {
        $tool->loadMissing('manager');

        return new self(
            id: $tool->id,
            nodeId: $tool->node_id,
            manager: $tool->manager->name,
            package: $tool->package,
            versionConstraint: $tool->version_constraint,
            status: $status ?? ToolObjectStatus::fromStored($tool->status),
            installedVersion: $tool->installed_version,
            failedOperation: $tool->failed_operation?->value,
            errorCode: $tool->error_code,
            outcome: $outcome?->value,
        );
    }
}
