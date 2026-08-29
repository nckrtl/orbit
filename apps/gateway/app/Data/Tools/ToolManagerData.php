<?php

declare(strict_types=1);

namespace App\Data\Tools;

use App\Models\ToolManagerRecord;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class ToolManagerData extends Data
{
    /** @mago-expect lint:excessive-parameter-list */
    public function __construct(
        public int $id,
        public int $nodeId,
        public string $name,
        public string $status,
        public ?string $installedVersion,
        public ?string $failedStep,
        public ?string $errorCode,
    ) {}

    public static function fromModel(ToolManagerRecord $manager): self
    {
        return new self(
            id: $manager->id,
            nodeId: $manager->node_id,
            name: $manager->name->value,
            status: $manager->status->value,
            installedVersion: $manager->installed_version,
            failedStep: $manager->failed_step,
            errorCode: $manager->error_code,
        );
    }
}
