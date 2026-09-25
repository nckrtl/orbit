<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\InstanceProvisionIntent;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskGroup;

it('claimNext continues after provision null', function (): void {
    $gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'claim-hol-gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.87',
        'wireguard_ip' => '10.44.0.87',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $this->postJson('/api/v1/tasks/enable')->assertOk();

    $app = OrbitApp::query()->create([
        'name' => 'Claim HOL',
        'slug' => 'claim-hol',
        'repository_url' => 'git@example.test:claim-hol.git',
        'default_branch' => 'main',
    ]);
    $oldest = claim_hol_group($app, 'Oldest');
    $second = claim_hol_group($app, 'Second');
    $node = Node::query()->create([
        'name' => 'claim-hol-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.88',
        'wireguard_ip' => '10.44.0.88',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'claim-hol-second',
        'checkout_path' => '/tmp/claim-hol-second',
        'status' => 'reserved',
    ]);

    app()->instance(InstanceProvisioning::class, new class($instance) implements InstanceProvisioning
    {
        public int $calls = 0;

        public function __construct(private AppInstance $instance) {}

        public function provision(InstanceProvisionIntent $intent): ?AppInstance
        {
            $this->calls++;

            return $this->calls === 1 ? null : $this->instance;
        }
    });
    app()->instance(AgentSpawner::class, new class implements AgentSpawner
    {
        public function spawnReviewer(Task $task): ?int
        {
            return test_agent_thread($task->taskGroup, 'claim-hol-reviewer-'.$task->task_group_id)->id;
        }

        public function spawnImplementer(Task $task): ?int
        {
            return test_agent_thread($task->taskGroup, 'claim-hol-implementer-'.$task->task_group_id, $task)->id;
        }

        public function requestReview(Task $task): void {}
    });

    $claimed = app(TaskScheduler::class)->claimNext();
    test_pass_baseline();

    expect($claimed?->id)->toBe($second->id)
        ->and($claimed?->status)->toBe(TaskGroupStatus::Running)
        ->and($oldest->fresh()?->status)->toBe(TaskGroupStatus::Todo)
        ->and($oldest->fresh()?->assistance_requested)->toBeFalse()
        ->and($oldest->fresh()?->assistance_reason)->toBe('Workspace provisioning did not return an instance.')
        ->and($second->tasks()->sole()->fresh()?->status)->toBe(TaskStatus::Running);

    $this->getJson("/api/v1/task-groups/{$oldest->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'todo')
        ->assertJsonPath('data.assistance_reason', 'Workspace provisioning did not return an instance.');

    $recovered = app(TaskScheduler::class)->claimNext();
    test_pass_baseline();

    expect($recovered?->id)->toBe($oldest->id)
        ->and($recovered?->status)->toBe(TaskGroupStatus::Running)
        ->and($oldest->fresh()?->assistance_requested)->toBeFalse()
        ->and($oldest->fresh()?->assistance_reason)->toBeNull()
        ->and($oldest->tasks()->sole()->fresh()?->status)->toBe(TaskStatus::Running)
        ->and($oldest->tasks()->sole()->fresh()?->implementer_agent_thread_id)->not->toBeNull();
});

function claim_hol_group(OrbitApp $app, string $title): TaskGroup
{
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => $title,
        'brief' => "{$title} brief",
        'status' => TaskGroupStatus::Todo,
    ]);
    Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 1,
        'title' => "{$title} task",
        'brief' => 'First task.',
        'status' => TaskStatus::Todo,
    ]);

    return $group;
}
