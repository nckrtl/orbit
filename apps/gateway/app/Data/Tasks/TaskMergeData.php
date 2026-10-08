<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskMergeStatus;
use App\Models\Task;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** ADR 0203: one open review-and-merge task in the `tasks:status` view. */
#[MapOutputName(SnakeCaseMapper::class)]
final class TaskMergeData extends Data
{
    public function __construct(
        public int $id,
        public int $projectId,
        public string $project,
        public string $projectCode,
        public string $title,
        public TaskGroupStatus $status,
        public ?string $prUrl,
        public ?string $prBranch,
        public ?TaskMergeStatus $mergeStatus,
        public ?string $mergeReason,
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
            prUrl: $group->pr_url,
            prBranch: $group->pr_branch,
            mergeStatus: $group->merge_status,
            mergeReason: $group->merge_reason,
        );
    }
}
