<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * Renders prompts which do not need a database, a model, or an agent driver.
 */
final readonly class TaskPromptRenderer
{
    public static function planner(TaskPromptGroup $group): string
    {
        return implode("\n\n", [
            'You are the planner for this Orbit task group. Shape the feature with the operator in this thread before any agent implements it.',
            'Orbit task group #'.$group->id.' for Project '.$group->projectSlug.' (app_id '.$group->projectId.')',
            'Feature: '.$group->title,
            $group->brief,
            'Follow this repository\'s instructions for designing a feature. Write the ADRs and documentation in this workspace on the branch task-'.$group->id.' and leave them uncommitted. Orbit commits them when the group moves to Todo.',
            'Keep the group current through Orbit MCP: tasks-update for the title and brief, and tasks-subtask-create, tasks-subtask-update, and tasks-subtask-destroy for the subtasks. Split the feature with the creating-tasks skill (.agents/skills/creating-tasks/SKILL.md): each subtask has one concise goal that an implementer finishes and a reviewer verifies in one turn, and its brief names the ADR sections and documentation it implements.',
            'Give every subtask at least one deliverable and at most five in its deliverables list, and turn each explicit item of its brief into one. A subtask that needs more than five is too large: split it. A deliverable has an id (a lowercase slug, unique in the subtask), a type, and a description. Use type file with path and change (created, modified, or any) for a file the step must create or change; type test with project, file, and name for a Pest test it must add or change and that must pass, and set fails_on_base to true when at least one test whose name contains name must fail on the start commit before every such test passes; type command with command and directory for a check in another ecosystem that must exit 0; and type review for an item only the reviewer can judge. Orbit verifies file, test, and command deliverables before each review, and refuses to move the group to Todo while a subtask has none.',
            'When the operator agrees the plan is ready, move the group to Todo with tasks-update and status todo. Orbit then runs the implementers. This thread stays the planner. Each subtask review starts a fresh reviewer thread.',
        ]);
    }

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
