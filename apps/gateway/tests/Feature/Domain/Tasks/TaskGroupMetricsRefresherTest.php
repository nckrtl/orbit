<?php

declare(strict_types=1);

use App\Actions\Tasks\ShowTaskGroupAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\NullTaskWorkspaceDiffReader;
use App\Domain\Tasks\TaskAgentSpawner;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupMetricsRefresher;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceDiffReader;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Tests\Support\AgentSnapshotReader;

beforeEach(function (): void {
    test_bind_snapshot_driver();
});

function metrics_running_group(): Task
{
    $project = Project::query()->create([
        'name' => 'live-metrics',
        'slug' => 'live-metrics',
        'repository_url' => 'git@example.test:live-metrics.git',
        'default_branch' => 'main',
        'apps' => fixture_apps(null),
    ]);
    $node = Node::query()->create([
        'name' => 'live-metrics-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.161',
        'wireguard_ip' => '10.44.0.161',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'task-7',
        'checkout_path' => '/tmp/task-7',
        'status' => 'source_resolved',
    ]);
    $group = Task::topLevel()->create([
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
        'project_id' => $project->id,
        'title' => 'Live metrics',
        'brief' => 'Show session totals.',
        'status' => TaskGroupStatus::Running,
        'started_at' => '2026-09-21 10:00:00',
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'First',
        'brief' => 'One',
        'status' => TaskStatus::Running,
        'started_at' => '2026-09-21 10:00:00',
    ]);

    test_link_agent_threads($group, implementer: 'implementer-1');

    return $group->fresh(['project', 'tasks', 'taskable']) ?? $group;
}

it('fills subtask session metrics from agent observations and the group line diff from git', function (): void {
    $this->travelTo('2026-09-21 10:00:05');
    $group = metrics_running_group();
    $threads = new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return match ($threadId) {
                'implementer-1' => [
                    'thread' => [
                        'activities' => [
                            ['payload' => ['usage' => ['usedTokens' => 200, 'totalProcessedTokens' => 1200]]],
                        ],
                        'checkpoints' => [
                            ['files' => [['path' => 'a.php', 'kind' => 'modified', 'additions' => 4, 'deletions' => 1]]],
                        ],
                    ],
                ],
                'reviewer-thread' => [
                    'thread' => [
                        'activities' => [
                            ['payload' => ['usage' => ['usedTokens' => 80, 'totalProcessedTokens' => 300]]],
                        ],
                        'checkpoints' => [],
                    ],
                ],
                default => null,
            };
        }
    };
    $diff = new class implements TaskWorkspaceDiffReader
    {
        public function lineChanges(Instance $instance, string $baseBranch): ?array
        {
            return ['additions' => $this->lineDiff($instance, $baseBranch), 'deletions' => 0];
        }

        public function lineDiff(Instance $instance, string $baseBranch): int
        {
            expect($baseBranch)->toBe('main');

            return 22;
        }

        public function hasCommitsSince(Instance $instance, string $since): bool
        {
            return false;
        }
    };

    $refreshed = new TaskGroupMetricsRefresher(test_agent_observer($threads), $diff)->refresh($group);

    expect($refreshed->tokens)->toBe(1500)
        ->and($refreshed->line_diff)->toBe(22)
        ->and($refreshed->lines_added)->toBe(22)
        ->and($refreshed->lines_deleted)->toBe(0)
        ->and($refreshed->duration_ms)->toBe(5000)
        ->and($refreshed->tasks->first()?->tokens)->toBe(1200)
        ->and($refreshed->tasks->first()?->line_diff)->toBe(5)
        ->and($refreshed->tasks->first()?->duration_ms)->toBe(5000);
});

it('does not observe a reserved reviewer row', function (): void {
    $group = metrics_running_group();
    $instance = $group->taskable;
    $nodeId = $instance instanceof Instance ? $instance->node_id : null;
    AgentThread::query()->create([
        'task_group_id' => $group->id,
        'task_id' => $group->tasks->first()?->id,
        'node_id' => $nodeId,
        'driver' => 't3',
        'runtime_key' => 'node:'.$nodeId,
        'external_id' => TaskAgentSpawner::PendingPrefix.'reserved-reviewer',
        'role' => 'reviewer',
        'model' => 'reviewer',
        'effort' => 'high',
    ]);
    $threads = new class implements AgentSnapshotReader
    {
        /** @var list<string> */
        public array $seen = [];

        public function snapshot(Node $node, string $threadId): ?array
        {
            $this->seen[] = $threadId;
            if (str_starts_with($threadId, TaskAgentSpawner::PendingPrefix)) {
                throw new RuntimeException('observed a reserved row');
            }

            return null;
        }
    };

    new TaskGroupMetricsRefresher(test_agent_observer($threads), new NullTaskWorkspaceDiffReader)->refresh($group);

    expect($threads->seen)->toContain('implementer-1')
        ->and($threads->seen)->toContain('reviewer-thread')
        ->and($threads->seen)->not->toContain(TaskAgentSpawner::PendingPrefix.'reserved-reviewer');
});

it('keeps stored thread metrics when the agent refuses the snapshot', function (): void {
    $group = metrics_running_group();
    $group->tokens = 90;
    $group->line_diff = 11;
    $group->save();
    $group->tasks->first()?->update(['tokens' => 90, 'line_diff' => 4]);
    $threads = new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return null;
        }
    };
    $diff = new class implements TaskWorkspaceDiffReader
    {
        public function lineChanges(Instance $instance, string $baseBranch): ?array
        {
            return ['additions' => $this->lineDiff($instance, $baseBranch), 'deletions' => 0];
        }

        public function lineDiff(Instance $instance, string $baseBranch): int
        {
            return 11;
        }

        public function hasCommitsSince(Instance $instance, string $since): bool
        {
            return false;
        }
    };

    $refreshed = new TaskGroupMetricsRefresher(test_agent_observer($threads), $diff)->refresh($group->fresh(['project', 'tasks', 'taskable']) ?? $group);

    expect($refreshed->tokens)->toBe(90)
        ->and($refreshed->line_diff)->toBe(11)
        ->and($refreshed->tasks->first()?->tokens)->toBe(90)
        ->and($refreshed->tasks->first()?->line_diff)->toBe(4);
});

it('refreshes an active group when it is shown', function (): void {
    $this->travelTo('2026-09-21 10:00:05');
    $group = metrics_running_group();
    app(TaskExtensionState::class)->enable();
    app()->instance(AgentSnapshotReader::class, new class implements AgentSnapshotReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return [
                'thread' => [
                    'activities' => [
                        ['payload' => ['usage' => ['usedTokens' => 50, 'totalProcessedTokens' => 400]]],
                    ],
                    'checkpoints' => [
                        ['files' => [['path' => 'a.php', 'kind' => 'modified', 'additions' => 2, 'deletions' => 0]]],
                    ],
                ],
            ];
        }
    });
    app()->instance(TaskWorkspaceDiffReader::class, new class implements TaskWorkspaceDiffReader
    {
        public function lineChanges(Instance $instance, string $baseBranch): ?array
        {
            return ['additions' => $this->lineDiff($instance, $baseBranch), 'deletions' => 0];
        }

        public function lineDiff(Instance $instance, string $baseBranch): int
        {
            return 9;
        }

        public function hasCommitsSince(Instance $instance, string $since): bool
        {
            return false;
        }
    });

    $shown = app(ShowTaskGroupAction::class)->execute($group);

    expect($shown->tokens)->toBe(800)
        ->and($shown->line_diff)->toBe(9)
        ->and($shown->tasks->first()?->tokens)->toBe(400)
        ->and($shown->tasks->first()?->line_diff)->toBe(2);
});

it('does not query the agent for a finished group', function (): void {
    $group = metrics_running_group();
    $group->status = TaskGroupStatus::Completed;
    $group->tokens = 12;
    $group->line_diff = 3;
    $group->save();
    $threads = new class implements AgentSnapshotReader
    {
        public bool $queried = false;

        public function snapshot(Node $node, string $threadId): ?array
        {
            $this->queried = true;

            return ['thread' => ['activities' => [], 'checkpoints' => []]];
        }
    };

    $refreshed = new TaskGroupMetricsRefresher(test_agent_observer($threads), new class implements TaskWorkspaceDiffReader
    {
        public function lineChanges(Instance $instance, string $baseBranch): ?array
        {
            return ['additions' => $this->lineDiff($instance, $baseBranch), 'deletions' => 0];
        }

        public function lineDiff(Instance $instance, string $baseBranch): int
        {
            return 99;
        }

        public function hasCommitsSince(Instance $instance, string $since): bool
        {
            return false;
        }
    })->refresh($group);

    expect($threads->queried)->toBeFalse()
        ->and($refreshed->tokens)->toBe(12)
        ->and($refreshed->line_diff)->toBe(3);
});
