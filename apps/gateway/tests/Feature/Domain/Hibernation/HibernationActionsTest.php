<?php

declare(strict_types=1);

use App\Actions\Hibernation\ActivateAppInstanceRuntimeAction;
use App\Actions\Hibernation\SweepIdleAppDevRuntimesAction;
use App\Domain\Hibernation\AppInstanceRuntimeReadiness;
use App\Domain\Hibernation\HibernationException;
use App\Domain\Hibernation\HibernationMarkerStore;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Schedule;
use Illuminate\Support\Carbon;
use Tests\Support\FakeAppInstanceRuntimeReadiness;
use Tests\Support\ProcessesApiFakeRuntimeManager;

beforeEach(function (): void {
    $this->runtime = new ProcessesApiFakeRuntimeManager;
    $this->markers = new HibernationFakeMarkerStore;
    $this->readiness = new FakeAppInstanceRuntimeReadiness;
    app()->instance(ProcessRuntimeManager::class, $this->runtime);
    app()->instance(HibernationMarkerStore::class, $this->markers);
    app()->instance(AppInstanceRuntimeReadiness::class, $this->readiness);

    $this->node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $this->node->roles()->create(['role' => 'app-dev', 'status' => LifecycleStatus::Active]);
    $app = OrbitApp::query()->create([
        'name' => 'Docs',
        'slug' => 'docs',
        'repository_url' => 'git@example.test:docs.git',
    ]);
    $this->instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $this->node->id,
        'name' => 'main',
        'environment' => 'development',
        'checkout_path' => '/home/orbit/apps/docs',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
});

it('wakes desired-running AppInstance Processes and writes the awake marker', function (): void {
    $running = hibernation_action_process($this->instance, 'vite', DesiredProcessState::Running);
    $stopped = hibernation_action_process($this->instance, 'queue', DesiredProcessState::Stopped);

    app(ActivateAppInstanceRuntimeAction::class)->execute($this->instance);

    expect($this->runtime->started)
        ->toBe([$running->id])
        ->and($this->readiness->waited)
        ->toBeTrue()
        ->and($this->readiness->processIds)
        ->toBe([$running->id])
        ->and($this->markers->awake)
        ->toBe([RuntimeHibernation::key((int) $this->instance->id)])
        ->and($stopped->fresh()->desired_state)
        ->toBe(DesiredProcessState::Stopped)
        ->and($running->fresh()->desired_state)
        ->toBe(DesiredProcessState::Running);
});

it('does not write the awake marker when readiness fails', function (): void {
    hibernation_action_process($this->instance, 'vite', DesiredProcessState::Running);
    $this->readiness->failure = new HibernationException(
        errorCode: 'hibernation.development_server_not_ready',
        message: 'The development server did not accept connections before the wake timeout.',
    );

    expect(fn () => app(ActivateAppInstanceRuntimeAction::class)->execute($this->instance))
        ->toThrow(HibernationException::class);
    expect($this->markers->awake)->toBe([]);
});

it('halts idle desired-running Processes without changing desired state or Schedules', function (): void {
    $running = hibernation_action_process($this->instance, 'vite', DesiredProcessState::Running, 'always');
    hibernation_action_process($this->instance, 'queue', DesiredProcessState::Stopped);
    $schedule = Schedule::query()->create([
        'target_type' => AppInstance::class,
        'target_id' => $this->instance->id,
        'host_node_id' => $this->node->id,
        'name' => 'hourly',
        'command' => 'php artisan report:send',
        'calendar' => 'hourly',
        'timeout_seconds' => 60,
        'desired_timer_state' => 'enabled',
        'status' => 'active',
    ]);
    $this->markers->activity[RuntimeHibernation::key((int) $this->instance->id)] = Carbon::now()->subSeconds(3_601)->getTimestamp();

    $halted = app(SweepIdleAppDevRuntimesAction::class)->execute(Carbon::now());

    expect($halted)
        ->toBe(1)
        ->and($this->runtime->stopped)
        ->toBe([$running->id])
        ->and($running->fresh()->desired_state)
        ->toBe(DesiredProcessState::Running)
        ->and($this->markers->asleep)
        ->toBe([RuntimeHibernation::key((int) $this->instance->id)])
        ->and($schedule->fresh()->desired_timer_state->value)
        ->toBe('enabled');
});

it('halts desired-running Processes that have no recorded HTTP activity', function (): void {
    $running = hibernation_action_process($this->instance, 'vite', DesiredProcessState::Running);

    $halted = app(SweepIdleAppDevRuntimesAction::class)->execute(Carbon::now());

    expect($halted)
        ->toBe(1)
        ->and($this->runtime->stopped)
        ->toBe([$running->id]);
});

it('leaves recent HTTP activity and Node or production Processes running', function (): void {
    hibernation_action_process($this->instance, 'vite', DesiredProcessState::Running);
    $this->markers->activity[RuntimeHibernation::key((int) $this->instance->id)] = Carbon::now()->subMinutes(5)->getTimestamp();
    $prodNode = Node::query()->create([
        'name' => 'app-prod',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.21',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.4',
    ]);
    $prodNode->roles()->create(['role' => 'app-prod', 'status' => LifecycleStatus::Active]);
    $app = OrbitApp::query()->findOrFail($this->instance->app_id);
    $production = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $prodNode->id,
        'name' => 'prod',
        'environment' => 'production',
        'checkout_path' => '/var/www/docs',
        'production_user' => 'orbit-docs',
        'production_home' => '/var/www/docs',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
    $productionProcess = hibernation_action_process($production, 'queue', DesiredProcessState::Running);
    $nodeProcess = Process::query()->create([
        'owner_type' => Node::class,
        'owner_id' => $this->node->id,
        'name' => 'postgres',
        'runtime' => 'docker',
        'working_directory' => '/app',
        'runtime_config' => ['image' => 'postgres:18', 'command' => ['postgres']],
        'restart_policy' => 'always',
        'desired_state' => 'running',
        'status' => 'active',
    ]);

    $halted = app(SweepIdleAppDevRuntimesAction::class)->execute(Carbon::now());

    expect($halted)
        ->toBe(0)
        ->and($this->runtime->stopped)
        ->toBe([])
        ->and($productionProcess->fresh()->desired_state)
        ->toBe(DesiredProcessState::Running)
        ->and($nodeProcess->fresh()->desired_state)
        ->toBe(DesiredProcessState::Running);
});

function hibernation_action_process(
    AppInstance $instance,
    string $name,
    DesiredProcessState $desired,
    string $restartPolicy = 'on-failure',
): Process {
    return Process::query()->create([
        'owner_type' => AppInstance::class,
        'owner_id' => $instance->id,
        'name' => $name,
        'runtime' => 'systemd',
        'working_directory' => $instance->checkout_path,
        'runtime_config' => ['command' => ['/usr/bin/true']],
        'restart_policy' => $restartPolicy,
        'desired_state' => $desired,
        'status' => 'active',
    ]);
}

final class HibernationFakeMarkerStore implements HibernationMarkerStore
{
    /** @var list<string> */
    public array $awake = [];

    /** @var list<string> */
    public array $asleep = [];

    /** @var array<string, int> */
    public array $activity = [];

    public function markAwake(Node $node, string $key): void
    {
        $this->awake[] = $key;
    }

    public function markAsleep(Node $node, string $key): void
    {
        $this->asleep[] = $key;
    }

    public function lastActivityUnix(Node $node, string $key): ?int
    {
        return $this->activity[$key] ?? null;
    }
}
