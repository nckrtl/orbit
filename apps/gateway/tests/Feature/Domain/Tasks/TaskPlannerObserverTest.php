<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPlannerObserver;
use App\Infrastructure\Tasks\T3\T3ThreadReader;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\TaskGroup;

function planner_observer_group(TaskGroupStatus $status, string $planner): TaskGroup
{
    static $sequence = 0;
    $sequence++;
    $app = OrbitApp::query()->create([
        'name' => "planner-{$sequence}",
        'slug' => "planner-{$sequence}",
        'repository_url' => "git@example.test:planner-{$sequence}.git",
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => "planner-node-{$sequence}",
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.'.(170 + $sequence),
        'wireguard_ip' => '10.44.0.'.(170 + $sequence),
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => "task-{$sequence}",
        'checkout_path' => "/tmp/task-{$sequence}",
        'status' => 'source_resolved',
    ]);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => "Planned {$sequence}",
        'brief' => 'Shape the feature.',
        'status' => $status,
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    $group->update(['reviewer_agent_thread_id' => test_agent_thread($group, $planner)->id]);

    return $group->fresh() ?? $group;
}

it('observes the planner thread of every Backlog and Todo group, and no other group', function (): void {
    $backlog = planner_observer_group(TaskGroupStatus::Backlog, 'planner-backlog');
    $todo = planner_observer_group(TaskGroupStatus::Todo, 'planner-todo');
    $running = planner_observer_group(TaskGroupStatus::Running, 'reviewer-running');
    $reader = new class implements T3ThreadReader
    {
        /** @var list<string> */
        public array $read = [];

        public function snapshot(Node $node, string $threadId): ?array
        {
            $this->read[] = $threadId;

            return ['thread' => [
                'activities' => [['payload' => ['usage' => ['usedTokens' => 40, 'totalProcessedTokens' => 900]]]],
                'checkpoints' => [],
            ]];
        }
    };

    $observed = new TaskPlannerObserver(test_agent_observer($reader))->observe();

    expect($observed)->toBe(2)
        ->and($reader->read)->toBe(['planner-backlog', 'planner-todo'])
        ->and($backlog->fresh()?->reviewerThread?->tokens)->toBe(900)
        ->and($backlog->fresh()?->reviewerThread?->observed_at)->not->toBeNull()
        ->and($todo->fresh()?->reviewerThread?->tokens)->toBe(900)
        ->and($running->fresh()?->reviewerThread?->observed_at)->toBeNull();
});
