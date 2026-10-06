<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;

final readonly class TaskHandoffChecks
{
    public function __construct(private TaskCheckRunner $checks) {}

    public function start(Task $group, Task $task, TaskComment $receipt): TaskCheck
    {
        $group->loadMissing(['taskable', 'project']);
        $instance = $group->taskable;
        if (! $instance instanceof Instance) {
            throw new TaskCheckException('The task workspace is unavailable.');
        }
        $process = $this->checks->start($instance, $group->project->taskCheckCommand(), [], self::deliverables($task));

        return TaskCheck::query()->create([
            'task_id' => $task->id, 'task_comment_id' => $receipt->id,
            'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Running,
            'pid' => $process->pid, 'process_started' => $process->started,
            'head_before' => $process->head, 'tree_before' => $process->tree, 'started_at' => now(),
        ]);
    }

    /** @return array{start: string|null, commands: list<array{id: string, command: string, directory: string, fails_on_base?: bool, paths?: list<string>}>}|null */
    public static function deliverables(Task $task): ?array
    {
        $deliverables = $task->deliverableList();
        if ($deliverables === []) {
            return null;
        }
        $commands = [];
        foreach ($deliverables as $deliverable) {
            if ($deliverable->type !== TaskDeliverableType::Command) {
                continue;
            }
            $command = ['id' => $deliverable->id, 'command' => $deliverable->command, 'directory' => TaskDeliverable::relative($deliverable->directory) ?: '.'];
            if ($deliverable->fails_on_base) {
                $command['fails_on_base'] = true;
                $command['paths'] = $deliverable->paths;
            }
            $commands[] = $command;
        }

        $start = TaskReviewBase::commit($task);

        return ['start' => $start !== '' ? $start : null, 'commands' => $commands];
    }
}
