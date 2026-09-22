<?php

declare(strict_types=1);

use App\Actions\Hibernation\ActivateAppInstanceRuntimeAction;
use App\Actions\Hibernation\SweepIdleAppDevRuntimesAction;
use App\Domain\AppDev\AppDevPhpFpmManager;
use App\Domain\Hibernation\AppDevHibernationPolicy;
use App\Domain\Hibernation\AppInstanceCheckoutInspector;
use App\Domain\Hibernation\AppInstanceRuntimeReadiness;
use App\Domain\Hibernation\HibernationException;
use App\Domain\Hibernation\HibernationMarkerStore;
use App\Domain\Hibernation\RuntimeDependencyState;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Schedule;
use Illuminate\Support\Carbon;
use Tests\Support\FakeAppInstanceCheckoutInspector;
use Tests\Support\FakeAppInstanceRuntimeReadiness;
use Tests\Support\ProcessesApiFakeRuntimeManager;

beforeEach(function (): void {
    $this->runtime = new ProcessesApiFakeRuntimeManager;
    $this->markers = new HibernationFakeMarkerStore;
    $this->readiness = new FakeAppInstanceRuntimeReadiness;
    $this->checkouts = new FakeAppInstanceCheckoutInspector;
    $this->checkouts->runtime = $this->runtime;
    app()->instance(ProcessRuntimeManager::class, $this->runtime);
    app()->instance(HibernationMarkerStore::class, $this->markers);
    app()->instance(AppInstanceRuntimeReadiness::class, $this->readiness);
    app()->instance(AppInstanceCheckoutInspector::class, $this->checkouts);

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

it('defaults the soft idle window to one hour and the dependency idle window to seven days', function (): void {
    expect(config('orbit.hibernation.idle_seconds'))
        ->toBe(3_600)
        ->and(config('orbit.hibernation.dependency_idle_seconds'))
        ->toBe(604_800)
        ->and(config('orbit.hibernation.wake_timeout_seconds'))
        ->toBe(60)
        ->and(config('orbit.hibernation.cold_wake_timeout_seconds'))
        ->toBe(1_800);
});

it('does not converge PHP-FPM when it wakes or halts AppInstance Processes', function (): void {
    $fpm = new HibernationRecordingPhpFpmManager;
    app()->instance(AppDevPhpFpmManager::class, $fpm);
    $running = hibernation_action_process($this->instance, 'vite', DesiredProcessState::Running);

    app(ActivateAppInstanceRuntimeAction::class)->execute($this->instance);
    $this->markers->activity[RuntimeHibernation::key((int) $this->instance->id)] = Carbon::now()->subSeconds(3_601)->getTimestamp();
    app(SweepIdleAppDevRuntimesAction::class)->execute(Carbon::now());

    expect($fpm->converges)
        ->toBe(0)
        ->and($this->runtime->started)
        ->toBe([$running->id])
        ->and($this->runtime->stopped)
        ->toBe([$running->id]);
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

    expect($halted->halted)
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

it('leaves keep-alive Processes running while it hibernates the rest of the group', function (): void {
    $vite = hibernation_action_process($this->instance, 'vite', DesiredProcessState::Running);
    $queue = hibernation_action_process($this->instance, 'queue', DesiredProcessState::Running, keepAlive: true);
    $this->markers->activity[RuntimeHibernation::key((int) $this->instance->id)] = Carbon::now()->subSeconds(3_601)->getTimestamp();

    $halted = app(SweepIdleAppDevRuntimesAction::class)->execute(Carbon::now());

    expect($halted->halted)
        ->toBe(1)
        ->and($this->runtime->stopped)
        ->toBe([$vite->id])
        ->and($queue->fresh()->desired_state)
        ->toBe(DesiredProcessState::Running)
        ->and($vite->fresh()->desired_state)
        ->toBe(DesiredProcessState::Running)
        ->and($this->markers->asleep)
        ->toBe([RuntimeHibernation::key((int) $this->instance->id)]);
});

it('does not mark an AppInstance asleep when every desired-running Process is keep-alive', function (): void {
    hibernation_action_process($this->instance, 'queue', DesiredProcessState::Running, keepAlive: true);

    $halted = app(SweepIdleAppDevRuntimesAction::class)->execute(Carbon::now());

    expect($halted->halted)
        ->toBe(0)
        ->and($this->runtime->stopped)
        ->toBe([])
        ->and($this->markers->asleep)
        ->toBe([]);
});

it('stops and starts an Antigravity watcher with AppInstance hibernation', function (): void {
    $http = hibernation_action_process($this->instance, 'agentation', DesiredProcessState::Running);
    $http->forceFill([
        'runtime_config' => ['preset' => 'agentation-mcp', 'command' => ['/usr/local/bin/agentation-mcp', 'server']],
    ])->save();
    $watcher = hibernation_action_process($this->instance, 'watch', DesiredProcessState::Running, 'always');
    $watcher->forceFill([
        'runtime_config' => ['preset' => 'antigravity-watch', 'command' => ['/usr/local/bin/agy']],
        'keep_alive' => false,
    ])->save();
    $this->markers->activity[RuntimeHibernation::key((int) $this->instance->id)] = Carbon::now()->subSeconds(3_601)->getTimestamp();

    $halted = app(SweepIdleAppDevRuntimesAction::class)->execute(Carbon::now());

    expect($halted->halted)
        ->toBe(1)
        ->and($this->runtime->stopped)
        ->toBe([$http->id, $watcher->id])
        ->and($watcher->fresh()->desired_state)
        ->toBe(DesiredProcessState::Running)
        ->and($watcher->fresh()->keep_alive)
        ->toBeFalse();

    app(ActivateAppInstanceRuntimeAction::class)->execute($this->instance);

    expect($this->runtime->started)
        ->toBe([$http->id, $watcher->id])
        ->and($this->markers->awake)
        ->toBe([RuntimeHibernation::key((int) $this->instance->id)]);
});

it('starts a desired-running keep-alive Process on wake when it is down', function (): void {
    $queue = hibernation_action_process($this->instance, 'queue', DesiredProcessState::Running, keepAlive: true);
    $vite = hibernation_action_process($this->instance, 'vite', DesiredProcessState::Running);

    app(ActivateAppInstanceRuntimeAction::class)->execute($this->instance);

    expect($this->runtime->started)
        ->toBe([$queue->id, $vite->id])
        ->and($this->markers->awake)
        ->toBe([RuntimeHibernation::key((int) $this->instance->id)]);
});

it('halts desired-running Processes that have no recorded HTTP activity', function (): void {
    $running = hibernation_action_process($this->instance, 'vite', DesiredProcessState::Running);

    $halted = app(SweepIdleAppDevRuntimesAction::class)->execute(Carbon::now());

    expect($halted->halted)
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

    expect($halted->halted)
        ->toBe(0)
        ->and($this->runtime->stopped)
        ->toBe([])
        ->and($productionProcess->fresh()->desired_state)
        ->toBe(DesiredProcessState::Running)
        ->and($nodeProcess->fresh()->desired_state)
        ->toBe(DesiredProcessState::Running);
});

it('prunes reconstructable checkout dependencies after the dependency idle window', function (): void {
    $process = hibernation_action_process($this->instance, 'vite', DesiredProcessState::Running);
    hibernation_age_process($process);
    $this->markers->asleep[] = RuntimeHibernation::key((int) $this->instance->id);
    $this->markers->activity[RuntimeHibernation::key((int) $this->instance->id)] = Carbon::now()->subSeconds(604_801)->getTimestamp();

    $result = app(SweepIdleAppDevRuntimesAction::class)->execute(Carbon::now());

    expect($result->pruned)
        ->toBe(1)
        ->and($this->checkouts->pruned)
        ->toBe([(string) $this->instance->id])
        ->and($this->markers->cold)
        ->toBe([RuntimeHibernation::key((int) $this->instance->id)]);
});

it('skips prune while the AppInstance is still awake', function (): void {
    $process = hibernation_action_process($this->instance, 'vite', DesiredProcessState::Running);
    hibernation_age_process($process);
    $key = RuntimeHibernation::key((int) $this->instance->id);
    $this->markers->awake[] = $key;
    $this->markers->activity[$key] = Carbon::now()->subSeconds(604_801)->getTimestamp();

    $result = new SweepIdleAppDevRuntimesAction(
        policy: app(AppDevHibernationPolicy::class),
        admissions: app(ProcessAdmissionLock::class),
        runtime: $this->runtime,
        markers: $this->markers,
        checkouts: $this->checkouts,
        idleSeconds: 10_000_000,
        dependencyIdleSeconds: RuntimeHibernation::DefaultDependencyIdleSeconds,
    )->execute(Carbon::now());

    expect($result->halted)
        ->toBe(0)
        ->and($result->pruned)
        ->toBe(0)
        ->and($this->checkouts->pruned)
        ->toBe([]);
});

it('skips prune when the AppInstance is already cold or still inside an activity window', function (string $reason): void {
    $process = hibernation_action_process($this->instance, 'vite', DesiredProcessState::Running);
    hibernation_age_process($process);
    $key = RuntimeHibernation::key((int) $this->instance->id);
    $this->markers->activity[$key] = Carbon::now()->subSeconds(604_801)->getTimestamp();

    match ($reason) {
        'cold' => $this->markers->cold[] = $key,
        'http' => $this->markers->activity[$key] = Carbon::now()->subMinutes(5)->getTimestamp(),
        'process' => $process->forceFill(['updated_at' => Carbon::now()])->save(),
        'source' => $this->checkouts->state = new RuntimeDependencyState(
            vendorReconstructable: true,
            vendorPresent: true,
            nodeModulesReconstructable: true,
            nodeModulesPresent: true,
            sourceTreeLastActivityUnix: Carbon::now()->getTimestamp(),
        ),
        'deps' => $this->checkouts->state = new RuntimeDependencyState(
            vendorReconstructable: false,
            vendorPresent: true,
            nodeModulesReconstructable: false,
            nodeModulesPresent: true,
        ),
        default => null,
    };

    $result = app(SweepIdleAppDevRuntimesAction::class)->execute(Carbon::now());

    expect($result->pruned)
        ->toBe(0)
        ->and($this->checkouts->pruned)
        ->toBe([]);
})->with(['cold', 'http', 'process', 'source', 'deps']);

it('skips prune when any keep-alive desired-running Process exists', function (): void {
    $vite = hibernation_action_process($this->instance, 'vite', DesiredProcessState::Running);
    hibernation_action_process($this->instance, 'queue', DesiredProcessState::Running, keepAlive: true);
    hibernation_age_process($vite);
    $this->markers->asleep[] = RuntimeHibernation::key((int) $this->instance->id);
    $this->markers->activity[RuntimeHibernation::key((int) $this->instance->id)] = Carbon::now()->subSeconds(604_801)->getTimestamp();

    $result = app(SweepIdleAppDevRuntimesAction::class)->execute(Carbon::now());

    expect($result->pruned)
        ->toBe(0)
        ->and($this->checkouts->pruned)
        ->toBe([]);
});

it('wakes a soft AppInstance without restoring checkout dependencies', function (): void {
    $running = hibernation_action_process($this->instance, 'vite', DesiredProcessState::Running);

    app(ActivateAppInstanceRuntimeAction::class)->execute($this->instance);

    expect($this->checkouts->restored)
        ->toBe([])
        ->and($this->runtime->started)
        ->toBe([$running->id])
        ->and($this->markers->awake)
        ->toBe([RuntimeHibernation::key((int) $this->instance->id)]);
});

it('leaves a runtime awake when its wake finishes before sweep admission', function (): void {
    $this->freezeTime();
    $process = hibernation_action_process($this->instance, 'vite', DesiredProcessState::Running);
    hibernation_age_process($process);
    $key = RuntimeHibernation::key((int) $this->instance->id);
    $this->markers->activity[$key] = now()->subSeconds(604_801)->getTimestamp();
    $wake = app(ActivateAppInstanceRuntimeAction::class);
    $admissions = new HibernationAdmissionLock(function () use ($wake, $key): void {
        $wake->execute($this->instance);
        $this->markers->activity[$key] = now()->getTimestamp();
    });
    app()->instance(ProcessAdmissionLock::class, $admissions);

    $result = app(SweepIdleAppDevRuntimesAction::class)->execute(Carbon::now());

    expect($result)->halted->toBe(0)->pruned->toBe(0);
    expect($this->runtime->started)->toBe([$process->id]);
    expect($this->runtime->stopped)->toBe([]);
    expect($this->checkouts->pruned)->toBe([]);
    expect($this->markers->awake)->toBe([$key]);
    expect($admissions->runs)->toBe([[$this->instance->id]]);
});

it('keeps dependencies needed by a keep-alive Process admitted before the sweep', function (): void {
    $this->freezeTime();
    $process = hibernation_action_process($this->instance, 'vite', DesiredProcessState::Running);
    hibernation_age_process($process);
    $key = RuntimeHibernation::key((int) $this->instance->id);
    $this->markers->activity[$key] = now()->subSeconds(604_801)->getTimestamp();
    app()->instance(ProcessAdmissionLock::class, new HibernationAdmissionLock(function (): void {
        hibernation_action_process($this->instance, 'worker', DesiredProcessState::Running, keepAlive: true);
    }));

    $result = app(SweepIdleAppDevRuntimesAction::class)->execute(Carbon::now());

    expect($result)->halted->toBe(1)->pruned->toBe(0);
    expect($this->runtime->stopped)->toBe([$process->id]);
    expect($this->checkouts->pruned)->toBe([]);
    expect($this->markers->cold)->toBe([]);
});

it('skips an Instance whose placement or eligibility changes before sweep admission', function (string $change): void {
    $this->freezeTime();
    $process = hibernation_action_process($this->instance, 'vite', DesiredProcessState::Running);
    hibernation_age_process($process);
    app()->instance(ProcessAdmissionLock::class, new HibernationAdmissionLock(function () use ($change): void {
        match ($change) {
            'deleted' => $this->instance->delete(),
            'checkout' => $this->instance->update(['checkout_path' => '/srv/relocated']),
            'environment' => $this->instance->update(['environment' => 'production']),
            'inactive' => $this->instance->update(['status' => 'reserved']),
            'migration-required' => $this->instance->update(['migration_required' => true]),
            'provisioning' => $this->instance->update(['provisioning_step' => 'prepare']),
            'node-inactive' => $this->node->update(['status' => LifecycleStatus::Failed]),
            'role-inactive' => $this->node->roles()->update(['status' => LifecycleStatus::Failed]),
            'relocated' => $this->instance->update(['node_id' => Node::query()->create([
                'name' => 'relocated',
                'status' => LifecycleStatus::Active,
                'platform' => 'linux',
                'public_ssh_host' => '192.0.2.21',
                'user' => 'orbit',
                'wireguard_ip' => '10.44.0.4',
            ])->id]),
        };
    }));

    $result = app(SweepIdleAppDevRuntimesAction::class)->execute(Carbon::now());

    expect($result)->halted->toBe(0)->pruned->toBe(0);
    expect($this->runtime->stopped)->toBe([]);
    expect($this->checkouts->pruned)->toBe([]);
    expect($this->markers->asleep)->toBe([]);
    expect($this->markers->cold)->toBe([]);
})->with(['deleted', 'checkout', 'environment', 'inactive', 'migration-required', 'provisioning', 'node-inactive', 'role-inactive', 'relocated']);

it('observes and prunes under the same Instance admission owner', function (): void {
    $this->freezeTime();
    $process = hibernation_action_process($this->instance, 'vite', DesiredProcessState::Running);
    hibernation_age_process($process);
    $admissions = new HibernationAdmissionLock;
    app()->instance(ProcessAdmissionLock::class, $admissions);
    $this->markers->beforeObservation = static function () use ($admissions): void {
        expect($admissions->held)->toBeTrue();
    };
    $state = $this->checkouts->state;
    $checkouts = Mockery::mock(AppInstanceCheckoutInspector::class);
    $checkouts->shouldReceive('inspect')->once()->andReturnUsing(static function () use ($admissions, $state): RuntimeDependencyState {
        expect($admissions->held)->toBeTrue();

        return $state;
    });
    $checkouts->shouldReceive('prune')->once()->andReturnUsing(static function () use ($admissions): void {
        expect($admissions->held)->toBeTrue();
    });
    app()->instance(AppInstanceCheckoutInspector::class, $checkouts);

    $result = app(SweepIdleAppDevRuntimesAction::class)->execute(Carbon::now());

    expect($result)->halted->toBe(1)->pruned->toBe(1);
    expect($admissions->runs)->toBe([[$this->instance->id]]);
    expect($admissions->held)->toBeFalse();
    expect($this->markers->cold)->toBe([RuntimeHibernation::key((int) $this->instance->id)]);
});

it('restores cold checkout dependencies before it starts Processes', function (): void {
    $running = hibernation_action_process($this->instance, 'vite', DesiredProcessState::Running);
    $this->markers->cold[] = RuntimeHibernation::key((int) $this->instance->id);
    $this->checkouts->state = new RuntimeDependencyState(
        vendorReconstructable: true,
        vendorPresent: false,
        nodeModulesReconstructable: true,
        nodeModulesPresent: false,
    );

    app(ActivateAppInstanceRuntimeAction::class)->execute($this->instance);

    expect($this->checkouts->startedBeforeRestore)
        ->toBe([])
        ->and($this->checkouts->restored)
        ->toBe([(string) $this->instance->id])
        ->and($this->runtime->started)
        ->toBe([$running->id])
        ->and($this->markers->cold)
        ->toBe([])
        ->and($this->markers->awake)
        ->toBe([RuntimeHibernation::key((int) $this->instance->id)]);
});

it('keeps the cold marker and skips the awake marker when restore fails', function (): void {
    hibernation_action_process($this->instance, 'vite', DesiredProcessState::Running);
    $this->markers->cold[] = RuntimeHibernation::key((int) $this->instance->id);
    $this->checkouts->restoreFailure = new HibernationException(
        errorCode: 'hibernation.checkout_restore_failed',
        message: 'Composer install failed.',
    );

    expect(fn () => app(ActivateAppInstanceRuntimeAction::class)->execute($this->instance))
        ->toThrow(HibernationException::class);
    expect($this->runtime->started)
        ->toBe([])
        ->and($this->markers->cold)
        ->toBe([RuntimeHibernation::key((int) $this->instance->id)])
        ->and($this->markers->awake)
        ->toBe([]);
});

function hibernation_action_process(
    AppInstance $instance,
    string $name,
    DesiredProcessState $desired,
    string $restartPolicy = 'on-failure',
    bool $keepAlive = false,
): Process {
    return Process::query()->create([
        'owner_type' => AppInstance::class,
        'owner_id' => $instance->id,
        'name' => $name,
        'runtime' => 'systemd',
        'working_directory' => $instance->checkout_path,
        'runtime_config' => ['command' => ['/usr/bin/true']],
        'restart_policy' => $restartPolicy,
        'keep_alive' => $keepAlive,
        'desired_state' => $desired,
        'status' => 'active',
    ]);
}

function hibernation_age_process(Process $process): void
{
    $process->forceFill([
        'updated_at' => Carbon::now()->subSeconds(604_801),
    ])->save();
}

final class HibernationRecordingPhpFpmManager implements AppDevPhpFpmManager
{
    public int $converges = 0;

    public function converge(Node $node): void
    {
        $this->converges++;
    }
}

final class HibernationFakeMarkerStore implements HibernationMarkerStore
{
    public ?Closure $beforeObservation = null;

    /** @var list<string> */
    public array $awake = [];

    /** @var list<string> */
    public array $asleep = [];

    /** @var array<string, int> */
    public array $activity = [];

    /** @var list<string> */
    public array $cold = [];

    public function markAwake(Node $node, string $key): void
    {
        $this->awake[] = $key;
        $this->asleep = array_values(array_filter($this->asleep, static fn (string $item): bool => $item !== $key));
    }

    public function markAsleep(Node $node, string $key): void
    {
        $this->asleep[] = $key;
        $this->awake = array_values(array_filter($this->awake, static fn (string $item): bool => $item !== $key));
    }

    public function markCold(Node $node, string $key): void
    {
        $this->cold[] = $key;
    }

    public function clearCold(Node $node, string $key): void
    {
        $this->cold = array_values(array_filter($this->cold, static fn (string $item): bool => $item !== $key));
    }

    public function lastActivityUnix(Node $node, string $key): ?int
    {
        $this->beforeObservation?->__invoke();

        return $this->activity[$key] ?? null;
    }

    public function isAwake(Node $node, string $key): bool
    {
        $this->beforeObservation?->__invoke();

        return in_array($key, $this->awake, true) && ! in_array($key, $this->asleep, true);
    }

    public function isCold(Node $node, string $key): bool
    {
        $this->beforeObservation?->__invoke();

        return in_array($key, $this->cold, true);
    }
}

final class HibernationAdmissionLock implements ProcessAdmissionLock
{
    public bool $held = false;

    /** @var list<list<int>> */
    public array $runs = [];

    public function __construct(private ?Closure $beforeAdmission = null) {}

    public function run(array $appInstanceIds, Closure $operation): mixed
    {
        $this->runs[] = $appInstanceIds;
        $before = $this->beforeAdmission;
        $this->beforeAdmission = null;
        $before?->__invoke();
        $this->held = true;

        try {
            return $operation();
        } finally {
            $this->held = false;
        }
    }
}
