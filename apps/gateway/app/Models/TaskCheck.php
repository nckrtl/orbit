<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckProcess;
use App\Domain\Tasks\TaskCheckStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One run of the Project check: the baseline before the first implementer, or the check for one `ready_for_review` receipt.
 *
 * @property int $id
 * @property int $task_id
 * @property int|null $task_comment_id
 * @property TaskCheckKind $kind
 * @property string|null $failed_step
 * @property TaskCheckStatus $status
 * @property int $pid
 * @property string $process_started
 * @property string $head_before
 * @property string $tree_before
 * @property string|null $head_after
 * @property string|null $tree_after
 * @property int|null $exit_code
 * @property list<string>|null $changed_paths
 * @property string|null $output
 * @property array<string, mixed>|null $deliverable_evidence
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 */
final class TaskCheck extends Model
{
    #[\Override]
    protected $fillable = [
        'task_id', 'task_comment_id', 'kind', 'failed_step', 'status', 'pid', 'process_started', 'head_before', 'tree_before',
        'head_after', 'tree_after', 'exit_code', 'changed_paths', 'output', 'deliverable_evidence', 'started_at', 'finished_at',
    ];

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function process(): TaskCheckProcess
    {
        return new TaskCheckProcess($this->pid, $this->process_started, $this->head_before, $this->tree_before);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => TaskCheckKind::class,
            'status' => TaskCheckStatus::class,
            'pid' => 'integer',
            'exit_code' => 'integer',
            'changed_paths' => 'array',
            'deliverable_evidence' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
