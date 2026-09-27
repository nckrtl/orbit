<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * Renders prompts which do not need a database, a model, or an agent driver.
 */
final readonly class TaskPromptRenderer
{
    public static function implementer(TaskPromptGroup $group, TaskPromptSubtask $task, ?int $threadId = null): string
    {
        $deliverables = $task->deliverables;

        return implode("\n\n", array_filter([
            'Implement this subtask in the shared workspace. '.TaskRunInstructions::implementer($deliverables, $group->taskCheck, $threadId),
            'Orbit task group #'.$group->id,
            'Feature: '.$group->title,
            'Orbit subtask #'.$task->id,
            'Subtask: '.$task->title,
            $task->brief,
            TaskRunInstructions::deliverables($deliverables),
            TaskRunInstructions::contract($group->defaultBranch).' Build to them.',
        ], static fn (string $part): bool => $part !== ''));
    }
}
