<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Models\AgentThread;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskGroup;
use Illuminate\Support\Carbon;
use Orbit\Sdk\Requests\Tasks\CancelTaskGroupRequest;
use Orbit\Sdk\Requests\Tasks\CompleteTaskGroupRequest;
use Orbit\Sdk\Requests\Tasks\CreateSubtaskRequest;
use Orbit\Sdk\Requests\Tasks\CreateTaskCommentRequest;
use Orbit\Sdk\Requests\Tasks\CreateTaskGroupRequest;
use Orbit\Sdk\Requests\Tasks\DestroySubtaskRequest;
use Orbit\Sdk\Requests\Tasks\DisableTasksRequest;
use Orbit\Sdk\Requests\Tasks\EnableTasksRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskAgentsRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskCommentsRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskGroupsRequest;
use Orbit\Sdk\Requests\Tasks\ShowTaskGroupRequest;
use Orbit\Sdk\Requests\Tasks\ShowTasksStatusRequest;
use Orbit\Sdk\Requests\Tasks\UpdateSubtaskRequest;
use Orbit\Sdk\Requests\Tasks\UpdateTaskGroupRequest;

/**
 * Records the tasks family responses that the SDK and the CLI replay. Data is deterministic:
 * the Gateway Node is id 1, the Project `orbit` (code ORB) is id 1, the clock is fixed, and the
 * request id is fixed. The scheduler finds no Node, so no group leaves Backlog or Todo on its own.
 */
describe('task response fixtures', function (): void {
    beforeEach(function (): void {
        $this->travelTo(Carbon::parse('2026-09-23T10:00:00Z'));
        $gateway = Node::query()->create([
            'name' => 'gateway',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.2',
            'wireguard_ip' => '10.44.0.1',
        ]);
        $this->markAsGateway($gateway);
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);
        $this->withHeader('X-Orbit-Request-Id', fixture_request_id());
        app()->instance(InstanceProvisioning::class, new class implements InstanceProvisioning
        {
            public function provision(InstanceProvisionIntent $intent): ?AppInstance
            {
                return null;
            }
        });
        app(TaskExtensionState::class)->enable();
        $this->project = OrbitApp::query()->create([
            'name' => 'Orbit',
            'code' => 'ORB',
            'slug' => 'orbit',
            'repository_url' => 'git@github.com:nckrtl/orbit.git',
            'default_branch' => 'main',
        ]);
    });

    it('records the extension toggles and status', function (): void {
        record_fixture($this->postJson('/api/v1/tasks/disable')->assertOk(), 'tasks/tasks-disable/disabled', DisableTasksRequest::class, 'POST /api/v1/tasks/disable');
        record_fixture($this->postJson('/api/v1/tasks/enable')->assertOk(), 'tasks/tasks-enable/enabled', EnableTasksRequest::class, 'POST /api/v1/tasks/enable');
        record_fixture($this->getJson('/api/v1/tasks/status')->assertOk(), 'tasks/tasks-status/enabled', ShowTasksStatusRequest::class, 'GET /api/v1/tasks/status');
    });

    it('records a created group and a refused todo create', function (): void {
        record_fixture($this->postJson('/api/v1/task-groups', [
            'app_id' => $this->project->id,
            'title' => 'Add the tasks CLI',
            'brief' => "Add tasks:* commands.\nAccept when every route has a command.",
            'tasks' => [
                ['title' => 'Add SDK requests', 'brief' => 'One request class per route.'],
                ['title' => 'Add CLI commands', 'brief' => 'One command per route.'],
            ],
        ])->assertCreated(), 'tasks/tasks-create/created', CreateTaskGroupRequest::class, 'POST /api/v1/task-groups');

        record_fixture($this->postJson('/api/v1/task-groups', [
            'app_id' => $this->project->id,
            'title' => 'Empty',
            'brief' => 'No subtasks.',
            'status' => 'todo',
        ])->assertUnprocessable(), 'tasks/tasks-create/no-subtasks', CreateTaskGroupRequest::class, 'POST /api/v1/task-groups');
    });

    it('records the group list, an empty list, and one group', function (): void {
        record_fixture($this->getJson('/api/v1/task-groups')->assertOk(), 'tasks/tasks-list/empty', ListTaskGroupsRequest::class, 'GET /api/v1/task-groups');

        $group = task_fixture_group($this->project);
        $done = TaskGroup::query()->create([
            'app_id' => $this->project->id,
            'title' => 'Absorb Commander',
            'brief' => 'Run task groups on the Gateway.',
            'status' => TaskGroupStatus::Completed,
            'pr_url' => 'https://github.com/nckrtl/orbit/pull/450',
            'tokens' => 182_400,
            'line_diff' => 1_240,
            'lines_added' => 1_010,
            'lines_deleted' => 230,
            'duration_ms' => 5_400_000,
        ]);
        Task::query()->create(['task_group_id' => $done->id, 'position' => 1, 'title' => 'Persist groups', 'brief' => 'Store groups.', 'status' => TaskStatus::Completed]);

        record_fixture($this->getJson('/api/v1/task-groups')->assertOk(), 'tasks/tasks-list/default', ListTaskGroupsRequest::class, 'GET /api/v1/task-groups');
        record_fixture($this->getJson("/api/v1/task-groups/{$group->id}")->assertOk(), 'tasks/tasks-show/default', ShowTaskGroupRequest::class, 'GET /api/v1/task-groups/{group}');
    });

    it('records group updates, a refused update, cancel, and complete', function (): void {
        $group = task_fixture_group($this->project);

        record_fixture($this->patchJson("/api/v1/task-groups/{$group->id}", ['title' => 'Add the tasks command family'])->assertOk(), 'tasks/tasks-update/updated', UpdateTaskGroupRequest::class, 'PATCH /api/v1/task-groups/{group}');

        $group->update(['status' => TaskGroupStatus::Todo]);
        record_fixture($this->patchJson("/api/v1/task-groups/{$group->id}", ['title' => 'Too late'])->assertConflict(), 'tasks/tasks-update/not-in-backlog', UpdateTaskGroupRequest::class, 'PATCH /api/v1/task-groups/{group}');

        record_fixture($this->postJson("/api/v1/task-groups/{$group->id}/cancel")->assertOk(), 'tasks/tasks-cancel/cancelled', CancelTaskGroupRequest::class, 'POST /api/v1/task-groups/{group}/cancel');

        $settling = task_fixture_group($this->project);
        $settling->update(['status' => TaskGroupStatus::Settling, 'pr_url' => 'https://github.com/nckrtl/orbit/pull/612']);
        record_fixture($this->postJson("/api/v1/task-groups/{$settling->id}/complete")->assertOk(), 'tasks/tasks-complete/completed', CompleteTaskGroupRequest::class, 'POST /api/v1/task-groups/{group}/complete');
    });

    it('records subtask create, update, and destroy', function (): void {
        $group = task_fixture_group($this->project);
        $first = $group->tasks()->orderBy('position')->firstOrFail();

        record_fixture($this->postJson("/api/v1/task-groups/{$group->id}/tasks", [
            'title' => 'Document the commands',
            'brief' => 'Add docs/cli/tasks.mdx.',
        ])->assertCreated(), 'tasks/tasks-subtask-create/created', CreateSubtaskRequest::class, 'POST /api/v1/task-groups/{group}/tasks');

        record_fixture($this->patchJson("/api/v1/task-groups/{$group->id}/tasks/{$first->id}", ['position' => 2])->assertOk(), 'tasks/tasks-subtask-update/updated', UpdateSubtaskRequest::class, 'PATCH /api/v1/task-groups/{group}/tasks/{task}');

        record_fixture($this->deleteJson("/api/v1/task-groups/{$group->id}/tasks/{$first->id}")->assertOk(), 'tasks/tasks-subtask-destroy/destroyed', DestroySubtaskRequest::class, 'DELETE /api/v1/task-groups/{group}/tasks/{task}');
    });

    it('records comments and agent threads', function (): void {
        $group = task_fixture_group($this->project);
        $task = $group->tasks()->orderBy('position')->firstOrFail();
        $implementer = AgentThread::query()->create(['task_group_id' => $group->id, 'task_id' => $task->id, 'node_id' => null, 'role' => 'implementer', 'model' => 'gpt-5.6-luna', 'effort' => 'low', 'driver' => 't3', 'runtime_key' => 'node:2', 'external_id' => 'thread-implementer', 'state' => 'done', 'observed_at' => now()->subMinute(), 'tokens' => 48_200, 'lines_added' => 120, 'lines_deleted' => 14]);
        AgentThread::query()->create(['task_group_id' => $group->id, 'task_id' => null, 'node_id' => null, 'role' => 'reviewer', 'model' => 'claude-opus-5', 'effort' => 'high', 'driver' => 't3', 'runtime_key' => 'node:2', 'external_id' => 'thread-reviewer', 'state' => 'working', 'observed_at' => now()->subMinute(), 'observation_error' => 'T3 did not answer in time.']);
        TaskComment::query()->create(['task_group_id' => $group->id, 'task_id' => $task->id, 'agent_thread_id' => $implementer->id, 'type' => 'ready_for_review', 'body' => "Added the SDK requests.\ncomposer check passes.", 'author' => 'implementer', 'posted_at' => now()->subMinutes(10)]);
        TaskComment::query()->create(['task_group_id' => $group->id, 'task_id' => $task->id, 'type' => 'approved', 'body' => 'Approved.', 'author' => 'reviewer', 'review_attempt' => 1, 'commit_sha' => '3f2a9c1e5b7d4f6a8c0e2b4d6f8a0c2e4b6d8f0a', 'posted_at' => now()->subMinutes(5)]);

        record_fixture($this->postJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/comments", [
            'type' => 'resolution',
            'body' => 'Use the existing request base.',
            'author' => 'nick',
        ])->assertCreated(), 'tasks/tasks-comment-create/created', CreateTaskCommentRequest::class, 'POST /api/v1/task-groups/{group}/tasks/{task}/comments');

        record_fixture($this->getJson("/api/v1/task-groups/{$group->id}/tasks/{$task->id}/comments")->assertOk(), 'tasks/tasks-comment-list/default', ListTaskCommentsRequest::class, 'GET /api/v1/task-groups/{group}/tasks/{task}/comments');

        record_fixture($this->getJson("/api/v1/task-groups/{$group->id}/agents")->assertOk(), 'tasks/tasks-agents/default', ListTaskAgentsRequest::class, 'GET /api/v1/task-groups/{group}/agents');
    });
});

function task_fixture_group(OrbitApp $project): TaskGroup
{
    $group = TaskGroup::query()->create([
        'app_id' => $project->id,
        'title' => 'Add the tasks CLI',
        'brief' => "Add tasks:* commands.\nAccept when every route has a command.",
    ]);
    Task::query()->create(['task_group_id' => $group->id, 'position' => 1, 'title' => 'Add SDK requests', 'brief' => 'One request class per route.', 'status' => TaskStatus::Todo]);
    Task::query()->create(['task_group_id' => $group->id, 'position' => 2, 'title' => 'Add CLI commands', 'brief' => 'One command per route.', 'status' => TaskStatus::Todo]);

    return $group;
}
