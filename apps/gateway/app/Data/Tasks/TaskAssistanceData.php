<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\Task;
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
        public ?AssistanceKind $assistanceKind,
        public ?string $assistanceQuestion,
        public ?string $assistanceReason,
    ) {}

    public static function fromModel(Task $group): self
    {
        $group->loadMissing('project');

        return new self(
            id: $group->id,
            projectId: $group->project_id,
            project: $group->project->slug,
            projectCode: $group->project->code,
            title: $group->title,
            status: $group->groupStatus(),
            assistanceKind: $group->assistance_kind,
            assistanceQuestion: $group->assistance_question,
            assistanceReason: $group->assistance_reason,
        );
    }
}
