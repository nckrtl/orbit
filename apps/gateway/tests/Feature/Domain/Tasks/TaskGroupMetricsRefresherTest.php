<?php

declare(strict_types=1);

use App\Actions\Tasks\ShowTaskGroupAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\T3ThreadReader;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupMetricsRefresher;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceDiffReader;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskGroup;

function metrics_running_group(): TaskGroup
{
    $app = OrbitApp::query()->create([
        'name' => 'live-metrics',
        'slug' => 'live-metrics',
        'repository_url' => 'git@example.test:live-metrics.git',
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'live-metrics-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.161',
        'wireguard_ip' => '10.44.0.161',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'task-7',
        'checkout_path' => '/tmp/task-7',
        'status' => 'source_resolved',
    ]);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Live metrics',
        'brief' => 'Show session totals.',
        'status' => TaskGroupStatus::Running,
        'reviewer_thread_id' => 'reviewer-thread',
        'started_at' => '2026-09-21 10:00:00',
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 1,
        'title' => 'First',
        'brief' => 'One',
        'status' => TaskStatus::Running,
        'implementer_thread_id' => 'implementer-1',
        'started_at' => '2026-09-21 10:00:00',
    ]);

    return $group->fresh(['app', 'tasks', 'taskable']) ?? $group;
}

it('fills subtask session metrics from T3 and the group line diff from git', function (): void {
    $this->travelTo('2026-09-21 10:00:05');
    $group = metrics_running_group();
    $threads = new class implements T3ThreadReader
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
        public function lineChanges(AppInstance $instance, string $baseBranch): ?array
        {
            return ['additions' => $this->lineDiff($instance, $baseBranch), 'deletions' => 0];
        }

        public function lineDiff(AppInstance $instance, string $baseBranch): int
        {
            expect($baseBranch)->toBe('main');

            return 22;
        }

        public function hasCommitsSince(AppInstance $instance, string $since): bool
        {
            return false;
        }
    };

    $refreshed = new TaskGroupMetricsRefresher($threads, $diff)->refresh($group);

    expect($refreshed->tokens)->toBe(1500)
        ->and($refreshed->line_diff)->toBe(22)
        ->and($refreshed->lines_added)->toBe(22)
        ->and($refreshed->lines_deleted)->toBe(0)
        ->and($refreshed->duration_ms)->toBe(5000)
        ->and($refreshed->tasks->first()?->tokens)->toBe(1200)
        ->and($refreshed->tasks->first()?->line_diff)->toBe(5)
        ->and($refreshed->tasks->first()?->duration_ms)->toBe(5000);
});

it('keeps stored thread metrics when T3 refuses the snapshot', function (): void {
    $group = metrics_running_group();
    $group->tokens = 90;
    $group->line_diff = 11;
    $group->save();
    $group->tasks->first()?->update(['tokens' => 90, 'line_diff' => 4]);
    $threads = new class implements T3ThreadReader
    {
        public function snapshot(Node $node, string $threadId): ?array
        {
            return null;
        }
    };
    $diff = new class implements TaskWorkspaceDiffReader
    {
        public function lineChanges(AppInstance $instance, string $baseBranch): ?array
        {
            return ['additions' => $this->lineDiff($instance, $baseBranch), 'deletions' => 0];
        }

        public function lineDiff(AppInstance $instance, string $baseBranch): int
        {
            return 11;
        }

        public function hasCommitsSince(AppInstance $instance, string $since): bool
        {
            return false;
        }
    };

    $refreshed = new TaskGroupMetricsRefresher($threads, $diff)->refresh($group->fresh(['app', 'tasks', 'taskable']) ?? $group);

    expect($refreshed->tokens)->toBe(90)
        ->and($refreshed->line_diff)->toBe(11)
        ->and($refreshed->tasks->first()?->tokens)->toBe(90)
        ->and($refreshed->tasks->first()?->line_diff)->toBe(4);
});

it('refreshes an active group when it is shown', function (): void {
    $this->travelTo('2026-09-21 10:00:05');
    $group = metrics_running_group();
    app(TaskExtensionState::class)->enable();
    app()->instance(T3ThreadReader::class, new class implements T3ThreadReader
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
        public function lineChanges(AppInstance $instance, string $baseBranch): ?array
        {
            return ['additions' => $this->lineDiff($instance, $baseBranch), 'deletions' => 0];
        }

        public function lineDiff(AppInstance $instance, string $baseBranch): int
        {
            return 9;
        }

        public function hasCommitsSince(AppInstance $instance, string $since): bool
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

it('does not query T3 for a finished group', function (): void {
    $group = metrics_running_group();
    $group->status = TaskGroupStatus::Completed;
    $group->tokens = 12;
    $group->line_diff = 3;
    $group->save();
    $threads = new class implements T3ThreadReader
    {
        public bool $queried = false;

        public function snapshot(Node $node, string $threadId): ?array
        {
            $this->queried = true;

            return ['thread' => ['activities' => [], 'checkpoints' => []]];
        }
    };

    $refreshed = new TaskGroupMetricsRefresher($threads, new class implements TaskWorkspaceDiffReader
    {
        public function lineChanges(AppInstance $instance, string $baseBranch): ?array
        {
            return ['additions' => $this->lineDiff($instance, $baseBranch), 'deletions' => 0];
        }

        public function lineDiff(AppInstance $instance, string $baseBranch): int
        {
            return 99;
        }

        public function hasCommitsSince(AppInstance $instance, string $since): bool
        {
            return false;
        }
    })->refresh($group);

    expect($threads->queried)->toBeFalse()
        ->and($refreshed->tokens)->toBe(12)
        ->and($refreshed->line_diff)->toBe(3);
});
