<?php

declare(strict_types=1);

namespace App\Data\Instances;

use App\Data\Projects\ProjectData;
use App\Models\Instance;
use App\Models\Project;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class InstanceRegistrationData extends Data
{
    /** @param list<InstanceData> $instances */
    public function __construct(
        public ProjectData $project,
        public InstanceData $instance,
        public array $instances,
        public string $status,
        public int $sourceCount,
        public int $completedCount,
    ) {}

    /** @param list<Instance> $sourceInstances */
    public static function fromModels(Project $project, Instance $primary, array $sourceInstances): self
    {
        return new self(
            project: ProjectData::fromModel($project),
            instance: InstanceData::fromModel($primary),
            instances: array_map(InstanceData::fromModel(...), $sourceInstances),
            status: 'active',
            sourceCount: count($sourceInstances),
            completedCount: count($sourceInstances),
        );
    }
}
