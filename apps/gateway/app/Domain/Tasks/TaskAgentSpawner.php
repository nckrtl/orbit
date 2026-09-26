<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AgentThread;
use App\Models\AppInstance;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskGroup;
use Illuminate\Support\Facades\Log;

final readonly class TaskAgentSpawner implements AgentSpawner, TaskPlannerSpawner
{
    public function __construct(private AgentDriverRegistry $drivers) {}

    public function spawnReviewer(Task $task): ?int
    {
        $group = $task->taskGroup;
        if ($group->reviewer_agent_thread_id !== null) {
            return $group->reviewer_agent_thread_id;
        }

        return $this->spawn($group, null, TaskThreadRole::Reviewer, 'Orbit task #'.$group->id.' · Reviewer: '.$group->title, $this->reviewerPrompt($group)."\n\n".$this->reviewPrompt($task));
    }

    public function spawnPlanner(TaskGroup $group): ?int
    {
        if ($group->reviewer_agent_thread_id !== null) {
            return $group->reviewer_agent_thread_id;
        }

        return $this->spawn($group, null, TaskThreadRole::Reviewer, 'Orbit task #'.$group->id.' · Planner: '.$group->title, $this->plannerPrompt($group));
    }

    public function spawnImplementer(Task $task): ?int
    {
        if ($task->implementer_agent_thread_id !== null) {
            return $task->implementer_agent_thread_id;
        }
        $group = $task->taskGroup;

        return $this->spawn($group, $task->id, TaskThreadRole::Implementer, 'Orbit task #'.$group->id.' / subtask #'.$task->id.' · Implementer: '.$task->title, $this->implementerPrompt($group, $task));
    }

    private function spawn(TaskGroup $group, ?int $taskId, TaskThreadRole $role, string $title, string $prompt): ?int
    {
        $instance = $group->taskable;
        if (! $instance instanceof AppInstance) {
            return null;
        }
        $reviewer = $role === TaskThreadRole::Reviewer;
        $model = $reviewer ? $group->reviewer_model : $group->implementer_model;
        $effort = $reviewer ? TaskAgentDefaults::ReviewerEffort : TaskAgentDefaults::ImplementerEffort;
        try {
            $driver = $this->drivers->get($reviewer ? $group->reviewer_agent_driver : $group->implementer_agent_driver);
            $externalId = $driver->create(new AgentThreadStart($instance->node, $instance, $title, $prompt, $model, $effort, $role));
        } catch (AgentDriverException) {
            Log::error('Agent conversation creation failed.', ['task_group_id' => $group->id, 'task_id' => $taskId, 'role' => $role->value]);

            return null;
        }
        if ($externalId === '') {
            return null;
        }
        $thread = AgentThread::query()->firstOrCreate([
            'driver' => $driver->key(), 'runtime_key' => 'node:'.$instance->node_id, 'external_id' => $externalId,
        ], [
            'task_group_id' => $group->id, 'task_id' => $taskId, 'node_id' => $instance->node_id,
            'role' => $role->value, 'model' => $model, 'effort' => $effort,
        ]);
        if ($thread->task_group_id !== $group->id || $thread->task_id !== $taskId || $thread->role !== $role->value) {
            throw new AgentDriverException('Agent conversation ownership does not match.');
        }

        return $thread->id;
    }

    public function requestReview(Task $task): void
    {
        $thread = $task->taskGroup->reviewerThread;
        if ($thread === null) {
            throw new AgentDriverException('Reviewer conversation is unavailable.');
        }
        $group = $task->taskGroup;
        $prompt = $this->reviewPrompt($task);

        // ADR 0124: the planner thread becomes the reviewer with the group's first review request.
        if ($group->plan && ! $group->tasks()->whereNotNull('review_notified_attempt')->exists()) {
            $prompt = 'The plan is in Todo and Orbit has started the implementers. From now on you are the reviewer of this group, not its planner. Do not change the plan or the subtasks.'."\n\n".$this->reviewerPrompt($group)."\n\n".$prompt;
        }

        $this->drivers->get($thread->driver)->send($thread, $prompt);
    }

    private function plannerPrompt(TaskGroup $group): string
    {
        return implode("\n\n", [
            'You are the planner for this Orbit task group. Shape the feature with the operator in this thread before any agent implements it.',
            'Orbit task group #'.$group->id.' for Project '.$group->app->slug.' (app_id '.$group->app_id.')',
            'Feature: '.$group->title,
            $group->brief,
            'Follow this repository\'s instructions for designing a feature. Write the ADRs and documentation in this workspace on the branch task-'.$group->id.' and leave them uncommitted. Orbit commits them when the group moves to Todo.',
            'Keep the group current through Orbit MCP: tasks-update for the title and brief, and tasks-subtask-create, tasks-subtask-update, and tasks-subtask-destroy for the subtasks. Split the feature with the creating-tasks skill (.agents/skills/creating-tasks/SKILL.md): each subtask has one concise goal that an implementer finishes and a reviewer verifies in one turn, and its brief names the ADR sections and documentation it implements.',
            'Give every subtask at least one deliverable and at most five in its deliverables list, and turn each explicit item of its brief into one. A subtask that needs more than five is too large: split it. A deliverable has an id (a lowercase slug, unique in the subtask), a type, and a description. Use type file with path and change (created, modified, or any) for a file the step must create or change; type test with project, file, and name for a Pest test it must add or change and that must pass, and set fails_on_base to true when at least one test whose name contains name must fail on the start commit before every such test passes; type command with command and directory for a check in another ecosystem that must exit 0; and type review for an item only the reviewer can judge. Orbit verifies file, test, and command deliverables before each review, and refuses to move the group to Todo while a subtask has none.',
            'When the operator agrees the plan is ready, move the group to Todo with tasks-update and status todo. Orbit then runs the implementers, and this thread becomes the group\'s reviewer.',
        ]);
    }

    private function reviewerPrompt(TaskGroup $group): string
    {
        return implode("\n\n", [
            'You are the reviewer for this feature group. Orbit asks you to review one subtask at a time in this shared workspace.',
            'Orbit task group #'.$group->id,
            'Feature: '.$group->title,
            $group->brief,
            $this->contract($group).' Review each subtask against them.',
            'The implementer works with a minimal toolset and has no web access. You do: use your web and documentation tools to confirm that framework and library usage matches current documentation for the versions this Project uses.',
        ]);
    }

    private function implementerPrompt(TaskGroup $group, Task $task): string
    {
        $deliverables = $task->deliverableList();

        return implode("\n\n", array_filter([
            'Implement this subtask in the shared workspace. '.TaskRunInstructions::implementer($deliverables, $group->app->taskCheckCommand()),
            'Orbit task group #'.$group->id,
            'Feature: '.$group->title,
            'Orbit subtask #'.$task->id,
            'Subtask: '.$task->title,
            $task->brief,
            TaskRunInstructions::deliverables($deliverables),
            $this->contract($group).' Build to them.',
        ], static fn (string $part): bool => $part !== ''));
    }

    /** ADR 0122: the ADRs and documentation prepared on the branch while the group was in Backlog. */
    private function contract(TaskGroup $group): string
    {
        $branch = $group->app->default_branch;
        $base = is_string($branch) && $branch !== '' ? '`origin/'.$branch.'`' : 'the Project default branch';

        return 'The ADRs and documentation that this branch changes against '.$base.' are the feature\'s contract.';
    }

    private function reviewPrompt(Task $task): string
    {
        $deliverables = $task->deliverableList();

        return implode("\n\n", array_filter([
            'Review subtask #'.$task->id.': '.$task->title,
            $task->brief,
            TaskRunInstructions::deliverables($deliverables),
            $this->baseRunReview($task, $deliverables),
            TaskRunInstructions::reviewer($task->isLastSubtask(), $deliverables),
        ], static fn (string $part): bool => $part !== ''));
    }

    /**
     * ADR 0163: the kind and message of each base failure, so a missing class is not read as a reproduction.
     *
     * @param  list<TaskDeliverable>  $deliverables
     */
    private function baseRunReview(Task $task, array $deliverables): string
    {
        $check = $task->checks()
            ->where('kind', TaskCheckKind::Handoff->value)
            ->where('status', TaskCheckStatus::Passed->value)
            ->latest('id')
            ->first();
        if (! $check instanceof TaskCheck) {
            return '';
        }
        $evidence = TaskDeliverableEvidence::fromArray($check->deliverable_evidence);

        return $evidence instanceof TaskDeliverableEvidence ? $evidence->baseRunReview($deliverables) : '';
    }
}
