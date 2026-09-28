<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Tasks\TaskGroupStatus;
use App\Models\TaskGroup;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class TaskAssistanceData extends Data
{
    public function __construct(
        public int $id,
        public int $projectId,
        public string $project,
        public string $projectCode,
        public string $title,
        public TaskGroupStatus $status,
        public ?string $assistanceReason,
    ) {}

    public static function fromModel(TaskGroup $group): self
    {
        $group->loadMissing('project');

        return new self(
            id: $group->id,
            projectId: $group->project_id,
            project: $group->project->slug,
            projectCode: $group->project->code,
            title: $group->title,
            status: $group->status,
            assistanceReason: $group->assistance_reason,
        );
    }
}
