<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\LocalTaskSettleMetricsCollector;
use App\Domain\Tasks\TaskGroupMetricsRefresher;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskWorkspaceDiffReader;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Tests\Support\NullAgentSnapshotReader;

it('sums task tokens, reads the workspace line diff, and measures duration', function (): void {
    $this->travelTo('2026-09-20 12:00:02');
    $project = Project::query()->create([
        'name' => 'metrics-app',
        'slug' => 'metrics-app',
        'repository_url' => 'git@github.com:nckrtl/orbit.git',
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'metrics-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.160',
        'wireguard_ip' => '10.44.0.160',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'task-40',
        'checkout_path' => '/tmp/task-40',
        'status' => 'source_resolved',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Metrics',
        'brief' => 'Fill settle numbers.',
        'status' => TaskGroupStatus::Settling,
        'started_at' => '2026-09-20 12:00:00',
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'First',
        'brief' => 'One',
        'status' => TaskStatus::Completed,
        'tokens' => 10,
    ]);
    Task::query()->create([
        'parent_id' => $group->id,
        'position' => 2,
        'title' => 'Second',
        'brief' => 'Two',
        'status' => TaskStatus::Completed,
        'tokens' => 30,
    ]);
    $reader = new class implements TaskWorkspaceDiffReader
    {
        public function lineChanges(Instance $instance, string $baseBranch): ?array
        {
            return ['additions' => $this->lineDiff($instance, $baseBranch), 'deletions' => 0];
        }

        public function lineDiff(Instance $instance, string $baseBranch): int
        {
            expect($baseBranch)->toBe('main');

            return 18;
        }

        public function hasCommitsSince(Instance $instance, string $since): bool
        {
            return false;
        }
    };

    $group->update(['questions' => 4, 'escalations' => 2]);
    $metrics = new LocalTaskSettleMetricsCollector(
        new TaskGroupMetricsRefresher(test_agent_observer(new NullAgentSnapshotReader), $reader),
    )->collect($group->fresh(['project', 'tasks', 'taskable']) ?? $group);

    expect($metrics->tokens)->toBe(40)
        ->and($metrics->lineDiff)->toBe(18)
        ->and($metrics->durationMs)->toBe(2000)
        ->and($metrics->questions)->toBe(4)
        ->and($metrics->escalations)->toBe(2);
});
