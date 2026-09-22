<?php

declare(strict_types=1);

use App\Actions\Processes\AddProcessAction;
use App\Actions\Processes\ListProcessesAction;
use App\Actions\Processes\RemoveProcessAction;
use App\Actions\Processes\RestartProcessAction;
use App\Actions\Processes\ShowProcessLogsAction;
use App\Actions\Processes\StartProcessAction;
use App\Actions\Processes\StopProcessAction;
use App\Data\Processes\AddProcessData;
use App\Domain\Analytics\AnalyticsRoleSettings;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\Analytics\AnalyticsStorageConnection;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessRuntimeStatusIndex;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Processes\ProcessUsageIndex;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Infrastructure\Analytics\NativePlausibleRuntimeLifecycle;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    $this->runtime = new ProcessActionsFakeRuntimeManager;
    app()->instance(ProcessRuntimeManager::class, $this->runtime);
    $this->targets = new ProcessTargetResolver;

    $this->node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'public_ssh_port' => 22,
        'user' => 'nckrtl',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $this->orbitApp = OrbitApp::query()->create([
        'name' => 'Docs',
        'slug' => 'docs',
        'repository_url' => 'git@example.test:docs.git',
    ]);
    $this->instance = AppInstance::query()->create([
        'app_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'main',
        'environment' => 'development',
        'checkout_path' => '/home/orbit/apps/docs',
        'source_is_laravel' => true,
        'provisioning_step' => 'active',
        'status' => AppInstanceState::Active,
    ]);
});

it('adds a stopped systemd process idempotently with the target defaults', function (): void {
    $data = new AddProcessData(
        targetType: ProcessTargetType::AppInstance,
        targetId: $this->instance->id,
        name: 'queue',
        runtime: ProcessRuntime::Systemd,
        command: ['/usr/bin/php', 'artisan', 'queue:work'],
        image: null,
        workingDirectory: null,
        environment: [],
        ports: [],
        volumes: [],
        restartPolicy: 'on-failure',
        start: false,
    );
    $action = new AddProcessAction($this->targets, $this->runtime, app(ProcessAdmissionLock::class));

    $first = $action->execute($data);
    $second = $action->execute($data);
    $process = $first['process'];

    expect($first['created'])
        ->toBeTrue()
        ->and($second['created'])
        ->toBeFalse()
        ->and(Process::query()->count())
        ->toBe(1)
        ->and($process->working_directory)
        ->toBe('/home/orbit/apps/docs')
        ->and($process->runtime_config)
        ->toBe([
            'command' => ['/usr/bin/php', 'artisan', 'queue:work'],
            'environment_file' => '/home/orbit/apps/docs/.env',
        ])
        ->and($process->desired_state->value)
        ->toBe('stopped')
        ->and($process->status)
        ->toBe(LifecycleStatus::Active)
        ->and($this->runtime->converged)
        ->toBe([
            ['id' => $process->id, 'desired_state' => 'stopped'],
            ['id' => $process->id, 'desired_state' => 'stopped'],
        ])
        ->and($this->runtime->started)
        ->toBeEmpty();
});

it('preserves the desired state when an existing process is added again', function (): void {
    $process = process_actions_record($this->instance);
    $process->update(['desired_state' => 'running']);
    $data = new AddProcessData(
        targetType: ProcessTargetType::AppInstance,
        targetId: $this->instance->id,
        name: 'queue',
        runtime: ProcessRuntime::Systemd,
        command: ['/usr/bin/php', 'artisan', 'queue:work'],
        image: null,
        workingDirectory: null,
        environment: [],
        ports: [],
        volumes: [],
        restartPolicy: 'always',
        start: false,
    );

    $result = new AddProcessAction($this->targets, $this->runtime, app(ProcessAdmissionLock::class))->execute($data);

    expect($result['created'])
        ->toBeFalse()
        ->and($result['process']->desired_state->value)
        ->toBe('running')
        ->and($this->runtime->started)
        ->toBeEmpty();
});

it('canonicalizes Docker environment maps before persistence and idempotency comparison', function (): void {
    $action = new AddProcessAction($this->targets, $this->runtime, app(ProcessAdmissionLock::class));
    $first = new AddProcessData(
        targetType: ProcessTargetType::AppInstance,
        targetId: $this->instance->id,
        name: 'worker',
        runtime: ProcessRuntime::Docker,
        command: ['php', 'artisan', 'queue:work'],
        image: 'php:8.5-cli',
        workingDirectory: '/app',
        environment: ['ZEBRA' => 'last', 'APP_KEY' => 'secret', 'ALPHA' => 'first'],
        ports: [],
        volumes: [],
        restartPolicy: 'unless-stopped',
        start: false,
    );
    $sameWithDifferentOrder = new AddProcessData(
        targetType: ProcessTargetType::AppInstance,
        targetId: $this->instance->id,
        name: 'worker',
        runtime: ProcessRuntime::Docker,
        command: ['php', 'artisan', 'queue:work'],
        image: 'php:8.5-cli',
        workingDirectory: '/app',
        environment: ['ALPHA' => 'first', 'ZEBRA' => 'last', 'APP_KEY' => 'secret'],
        ports: [],
        volumes: [],
        restartPolicy: 'unless-stopped',
        start: false,
    );

    $created = $action->execute($first);
    $readded = $action->execute($sameWithDifferentOrder);

    expect($created['process']->runtime_config['environment'])
        ->toBe(['ALPHA' => 'first', 'APP_KEY' => 'secret', 'ZEBRA' => 'last'])
        ->and($readded['created'])
        ->toBeFalse()
        ->and(Process::query()->count())
        ->toBe(1);
});

it('canonicalizes systemd environment maps before persistence and idempotency comparison', function (): void {
    $action = new AddProcessAction($this->targets, $this->runtime, app(ProcessAdmissionLock::class));
    $first = new AddProcessData(
        targetType: ProcessTargetType::AppInstance,
        targetId: $this->instance->id,
        name: 'proxycli',
        runtime: ProcessRuntime::Systemd,
        command: ['/usr/bin/python3', '/var/lib/orbit/proxycli/server.py'],
        image: null,
        workingDirectory: '/var/lib/orbit/proxycli',
        environment: ['ZEBRA' => 'last', 'PROXYCLI_READ_TOKEN' => 'secret', 'ALPHA' => 'first'],
        ports: [],
        volumes: [],
        restartPolicy: 'unless-stopped',
        start: false,
    );
    $sameWithDifferentOrder = new AddProcessData(
        targetType: ProcessTargetType::AppInstance,
        targetId: $this->instance->id,
        name: 'proxycli',
        runtime: ProcessRuntime::Systemd,
        command: ['/usr/bin/python3', '/var/lib/orbit/proxycli/server.py'],
        image: null,
        workingDirectory: '/var/lib/orbit/proxycli',
        environment: ['ALPHA' => 'first', 'ZEBRA' => 'last', 'PROXYCLI_READ_TOKEN' => 'secret'],
        ports: [],
        volumes: [],
        restartPolicy: 'unless-stopped',
        start: false,
    );

    $created = $action->execute($first);
    $readded = $action->execute($sameWithDifferentOrder);

    expect($created['process']->runtime_config)
        ->toBe([
            'command' => ['/usr/bin/python3', '/var/lib/orbit/proxycli/server.py'],
            'environment_file' => '/home/orbit/apps/docs/.env',
            'environment' => [
                'ALPHA' => 'first',
                'PROXYCLI_READ_TOKEN' => 'secret',
                'ZEBRA' => 'last',
            ],
        ])
        ->and($readded['created'])
        ->toBeFalse()
        ->and(Process::query()->count())
        ->toBe(1);
});

it('keeps Docker environment values out of action exception traces', function (): void {
    $sensitiveValue = 'action-boundary-secret';
    $data = new AddProcessData(
        targetType: ProcessTargetType::AppInstance,
        targetId: $this->instance->id,
        name: 'worker',
        runtime: ProcessRuntime::Docker,
        command: ['php', 'artisan', 'queue:work'],
        image: 'php:8.5-cli',
        workingDirectory: '/app',
        environment: ['APP_KEY' => $sensitiveValue],
        ports: [],
        volumes: [],
        restartPolicy: 'unless-stopped',
        start: false,
    );
    $this->runtime->startFailure = new ProcessOperationException(
        step: 'create-container',
        errorCode: 'process.docker_converge_failed',
        message: 'Docker convergence failed.',
    );

    try {
        new AddProcessAction($this->targets, $this->runtime, app(ProcessAdmissionLock::class))->execute($data);
    } catch (ProcessOperationException $exception) {
        expect(json_encode($exception->getTrace(), JSON_THROW_ON_ERROR))->not->toContain($sensitiveValue);

        return;
    }

    $this->fail('Expected Docker convergence to fail.');
});

it('adds and starts one Docker container process with explicit configuration', function (): void {
    $data = new AddProcessData(
        targetType: ProcessTargetType::AppInstance,
        targetId: $this->instance->id,
        name: 'redis',
        runtime: ProcessRuntime::Docker,
        command: ['redis-server'],
        image: 'redis:8-alpine',
        workingDirectory: '/data',
        environment: ['APP_MODE' => 'test'],
        ports: ['127.0.0.1:6380:6379/tcp'],
        volumes: [['source' => 'redis-data', 'target' => '/data', 'read_only' => false]],
        restartPolicy: 'unless-stopped',
        start: true,
    );

    $result = new AddProcessAction($this->targets, $this->runtime, app(ProcessAdmissionLock::class))->execute($data);
    $process = $result['process'];

    expect($process->owner)
        ->toBeInstanceOf(AppInstance::class)
        ->and($process->runtime_config)
        ->toBe([
            'image' => 'redis:8-alpine',
            'command' => ['redis-server'],
            'environment' => ['APP_MODE' => 'test'],
            'ports' => ['127.0.0.1:6380:6379/tcp'],
            'volumes' => [['source' => 'redis-data', 'target' => '/data', 'read_only' => false]],
        ])
        ->and($process->desired_state->value)
        ->toBe('running')
        ->and($this->runtime->converged)
        ->toBe([['id' => $process->id, 'desired_state' => 'running']])
        ->and($this->runtime->started)
        ->toBeEmpty();
});

it('refuses a new desired-running production process before admission when no release is selected', function (): void {
    $this->instance->update([
        'environment' => 'production',
        'checkout_path' => '/home/orbit-docs/releases/20260910',
        'production_user' => 'orbit-docs',
        'production_home' => '/home/orbit-docs',
    ]);
    $this->runtime->startUnavailable = true;
    $data = new AddProcessData(
        targetType: ProcessTargetType::AppInstance,
        targetId: $this->instance->id,
        name: 'queue',
        runtime: ProcessRuntime::Systemd,
        command: ['/usr/bin/php', 'artisan', 'queue:work'],
        image: null,
        workingDirectory: null,
        environment: [],
        ports: [],
        volumes: [],
        restartPolicy: 'always',
        start: true,
    );

    expect(fn () => new AddProcessAction(
        $this->targets,
        $this->runtime,
        app(ProcessAdmissionLock::class),
    )->execute($data))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('process.release_unavailable');
    });

    expect(Process::query()->count())
        ->toBe(0)
        ->and($this->runtime->startPreflights)
        ->toBe([['name' => 'queue', 'exists' => false]])
        ->and($this->runtime->converged)
        ->toBeEmpty();
});

it('refuses an idempotent desired-running production add without changing its record or runtime', function (): void {
    $this->instance->update([
        'environment' => 'production',
        'checkout_path' => '/home/orbit-docs/releases/20260910',
        'production_user' => 'orbit-docs',
        'production_home' => '/home/orbit-docs',
    ]);
    $process = Process::query()->create([
        'owner_type' => AppInstance::class,
        'owner_id' => $this->instance->id,
        'name' => 'worker',
        'runtime' => ProcessRuntime::Docker,
        'working_directory' => '/app',
        'runtime_config' => [
            'image' => 'php:8.5-cli',
            'command' => ['php', 'artisan', 'queue:work'],
            'environment' => [],
            'ports' => [],
            'volumes' => [],
        ],
        'restart_policy' => 'unless-stopped',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);
    $original = $process->fresh()->getRawOriginal();
    $this->runtime->startUnavailable = true;
    $data = new AddProcessData(
        targetType: ProcessTargetType::AppInstance,
        targetId: $this->instance->id,
        name: 'worker',
        runtime: ProcessRuntime::Docker,
        command: ['php', 'artisan', 'queue:work'],
        image: 'php:8.5-cli',
        workingDirectory: '/app',
        environment: [],
        ports: [],
        volumes: [],
        restartPolicy: 'unless-stopped',
        start: true,
    );

    expect(fn () => new AddProcessAction(
        $this->targets,
        $this->runtime,
        app(ProcessAdmissionLock::class),
    )->execute($data))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('process.release_unavailable');
    });

    expect($process->refresh()->getRawOriginal())
        ->toBe($original)
        ->and($this->runtime->startPreflights)
        ->toBe([['name' => 'worker', 'exists' => true]])
        ->and($this->runtime->converged)
        ->toBeEmpty();
});

it('retains a failed desired-running process definition and clears recovery state on retry', function (): void {
    $data = new AddProcessData(
        targetType: ProcessTargetType::AppInstance,
        targetId: $this->instance->id,
        name: 'worker',
        runtime: ProcessRuntime::Docker,
        command: ['php', 'artisan', 'queue:work'],
        image: 'php:8.5-cli',
        workingDirectory: '/app',
        environment: ['APP_ENV' => 'production'],
        ports: [],
        volumes: [],
        restartPolicy: 'unless-stopped',
        start: true,
    );
    $action = new AddProcessAction($this->targets, $this->runtime, app(ProcessAdmissionLock::class));
    $this->runtime->startFailure = new ProcessOperationException(
        step: 'start',
        errorCode: 'process.start_failed',
        message: 'The replacement did not start; the previous container was restored.',
    );

    expect(fn () => $action->execute($data))
        ->toThrow(ProcessOperationException::class, 'previous container was restored');

    $failed = Process::query()->sole();

    expect($failed)
        ->desired_state->toBe(DesiredProcessState::Running)
        ->status->toBe(LifecycleStatus::Failed)
        ->failed_step->toBe('start')
        ->error_code->toBe('process.start_failed');

    $this->runtime->startFailure = null;
    $retryResult = $action->execute($data);
    $retried = $retryResult['process'];

    expect($retried)
        ->id->toBe($failed->id)
        ->desired_state->toBe(DesiredProcessState::Running)
        ->status->toBe(LifecycleStatus::Active)
        ->failed_step->toBeNull()
        ->error_code->toBeNull()->and($retryResult['created'])->toBeFalse()->and(Process::query()->count())->toBe(
            1,
        )->and($this->runtime->started)->toBeEmpty();
});

it('rejects a conflicting process definition with the same owner and name', function (): void {
    $action = new AddProcessAction($this->targets, $this->runtime, app(ProcessAdmissionLock::class));
    $initial = new AddProcessData(
        targetType: ProcessTargetType::AppInstance,
        targetId: $this->instance->id,
        name: 'queue',
        runtime: ProcessRuntime::Systemd,
        command: ['/usr/bin/php', 'artisan', 'queue:work'],
        image: null,
        workingDirectory: null,
        environment: [],
        ports: [],
        volumes: [],
        restartPolicy: 'always',
        start: true,
    );
    $changed = new AddProcessData(
        targetType: ProcessTargetType::AppInstance,
        targetId: $this->instance->id,
        name: 'queue',
        runtime: ProcessRuntime::Systemd,
        command: ['/usr/bin/php', 'artisan', 'queue:listen'],
        image: null,
        workingDirectory: null,
        environment: [],
        ports: [],
        volumes: [],
        restartPolicy: 'always',
        start: true,
    );
    $action->execute($initial);

    expect(fn () => $action->execute($changed))
        ->toThrow(ResourceOperationException::class, 'already exists with different configuration');
});

it('uses an isolated app user for app-prod systemd processes', function (): void {
    $this->instance->update([
        'environment' => 'production',
        'checkout_path' => '/home/orbit-docs/releases/20260910',
        'production_user' => 'orbit-docs',
        'production_home' => '/home/orbit-docs',
    ]);

    $target = $this->targets->resolve(ProcessTargetType::AppInstance, $this->instance->id);

    expect($target->user)
        ->toBe('orbit-docs')
        ->and($target->defaultWorkingDirectory)
        ->toBe('/home/orbit-docs/current')
        ->and($target->certificateScope)
        ->toBeNull();
});

it('uses the node managed user and AppInstance certificate scope for app-dev targets', function (): void {
    $instanceTarget = $this->targets->resolve(ProcessTargetType::AppInstance, $this->instance->id);

    expect($instanceTarget->user)
        ->toBe('nckrtl')
        ->and($instanceTarget->certificateScope)
        ->toBe("app-instance-{$this->instance->id}");

    $removalTarget = $this->targets->forRemoval(Process::query()->create([
        'owner_type' => AppInstance::class,
        'owner_id' => $this->instance->id,
        'name' => 'removal-target',
        'runtime' => 'systemd',
        'working_directory' => '/tmp',
        'runtime_config' => ['command' => ['/bin/true']],
        'desired_state' => 'stopped',
        'status' => LifecycleStatus::Removing,
    ]));

    expect($removalTarget->user)->toBe('nckrtl');
});

it('rejects leftover Process owners before runtime removal', function (): void {
    $process = Process::query()->create([
        'owner_type' => 'App\\Models\\Instance',
        'owner_id' => 999_999,
        'name' => 'legacy',
        'runtime' => ProcessRuntime::Systemd,
        'working_directory' => '/home/orbit/apps/legacy',
        'runtime_config' => ['command' => ['/bin/true']],
        'restart_policy' => 'never',
        'desired_state' => 'stopped',
        'status' => LifecycleStatus::Active,
    ]);

    expect(fn () => new RemoveProcessAction($this->runtime, $this->targets)->execute($process))
        ->toThrow(ResourceOperationException::class, 'not a supported AppInstance or Node')
        ->and($this->runtime->removed)
        ->toBeEmpty();
});

it('rejects process targets on non-Linux nodes before runtime execution', function (): void {
    $this->node->update(['platform' => 'windows']);
    $data = new AddProcessData(
        targetType: ProcessTargetType::AppInstance,
        targetId: $this->instance->id,
        name: 'queue',
        runtime: ProcessRuntime::Systemd,
        command: ['/usr/bin/php', 'artisan', 'queue:work'],
        image: null,
        workingDirectory: null,
        environment: [],
        ports: [],
        volumes: [],
        restartPolicy: 'always',
        start: false,
    );

    try {
        new AddProcessAction($this->targets, $this->runtime, app(ProcessAdmissionLock::class))->execute($data);
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)
            ->toBe('process.platform_unsupported')
            ->and(Process::query()->count())
            ->toBe(0)
            ->and($this->runtime->converged)
            ->toBeEmpty();

        return;
    }

    $this->fail('Expected a non-Linux process target to be rejected.');
});

it('runs idempotent lifecycle actions and returns bounded logs', function (): void {
    $process = process_actions_record($this->instance);
    $this->runtime->status = 'running';
    $this->runtime->logs = "one\ntwo\n";

    $started = new StartProcessAction($this->runtime, app(ProcessAdmissionLock::class))->execute($process);
    $startedState = $started->desired_state->value;
    $stopped = new StopProcessAction($this->runtime)->execute($started);
    $stoppedState = $stopped->desired_state->value;
    $restarted = new RestartProcessAction($this->runtime, app(ProcessAdmissionLock::class))->execute($stopped);
    $restartedState = $restarted->desired_state->value;
    $logs = new ShowProcessLogsAction($this->runtime, new CommandActivityInputSanitizer)->execute($restarted, 25);

    expect($this->runtime->started)
        ->toBe([$process->id])
        ->and($this->runtime->stopped)
        ->toBe([$process->id])
        ->and($this->runtime->restarted)
        ->toBe([$process->id])
        ->and($this->runtime->logLines)
        ->toBe([25])
        ->and($startedState)
        ->toBe('running')
        ->and($stoppedState)
        ->toBe('stopped')
        ->and($restartedState)
        ->toBe('running')
        ->and($logs)
        ->toBe("one\ntwo\n");
});

it('locks and re-resolves AppInstance Processes before start and restart', function (): void {
    $startedProcess = process_actions_record($this->instance);
    $restartedProcess = $startedProcess->replicate();
    $restartedProcess->name = 'scheduler';
    $restartedProcess->save();
    $startLock = new ProcessActionsFakeAdmissionLock(function () use ($startedProcess): void {
        Process::query()->whereKey($startedProcess->id)->update(['name' => 'fresh-queue']);
    });
    $restartLock = new ProcessActionsFakeAdmissionLock(function () use ($restartedProcess): void {
        Process::query()->whereKey($restartedProcess->id)->update(['name' => 'fresh-scheduler']);
    });

    new StartProcessAction($this->runtime, $startLock)->execute($startedProcess);
    new RestartProcessAction($this->runtime, $restartLock)->execute($restartedProcess);

    expect($startLock->runs)
        ->toBe([[$this->instance->id]])
        ->and($restartLock->runs)
        ->toBe([[$this->instance->id]])
        ->and($this->runtime->startedNames)
        ->toBe(['fresh-queue'])
        ->and($this->runtime->restartedNames)
        ->toBe(['fresh-scheduler']);
});

it('refuses an AppInstance Process whose ownership changes before admitted execution', function (string $action): void {
    $process = process_actions_record($this->instance);
    $lock = new ProcessActionsFakeAdmissionLock(function () use ($process): void {
        Process::query()->whereKey($process->id)->update([
            'owner_type' => Node::class,
            'owner_id' => $this->node->id,
        ]);
    });

    expect(fn () => new $action($this->runtime, $lock)->execute($process))
        ->toThrow(ModelNotFoundException::class);

    expect($lock->runs)
        ->toBe([[$this->instance->id]])
        ->and($this->runtime->started)
        ->toBeEmpty()
        ->and($this->runtime->restarted)
        ->toBeEmpty();
})->with([
    'start' => StartProcessAction::class,
    'restart' => RestartProcessAction::class,
]);

it('preserves lifecycle behavior for Processes not owned by an AppInstance', function (): void {
    $process = process_actions_record($this->instance);
    $process->update([
        'owner_type' => Node::class,
        'owner_id' => $this->node->id,
    ]);
    $lock = new ProcessActionsFakeAdmissionLock(fn (): null => null);

    new StartProcessAction($this->runtime, $lock)->execute($process);
    new RestartProcessAction($this->runtime, $lock)->execute($process);

    expect($lock->runs)
        ->toBeEmpty()
        ->and($this->runtime->started)
        ->toBe([$process->id])
        ->and($this->runtime->restarted)
        ->toBe([$process->id]);
});

it('lists runtime status and removes only the selected process', function (): void {
    $process = process_actions_record($this->instance);
    $this->runtime->status = 'stopped';

    // The list reads status through the index now; this one answers from the same fake runtime,
    // so the assertion still describes what a caller sees.
    $listed = new ListProcessesAction(new class($this->runtime) implements ProcessRuntimeStatusIndex
    {
        public function __construct(private readonly object $runtime) {}

        public function statuses(Collection $processes): array
        {
            return $processes->mapWithKeys(fn (Process $process): array => [
                (int) $process->id => $this->runtime->status($process),
            ])->all();
        }
    }, new class implements ProcessUsageIndex
    {
        public function usage(Collection $processes): array
        {
            return $processes->mapWithKeys(fn (Process $process): array => [
                (int) $process->id => ['cpu' => null, 'memory_bytes' => null],
            ])->all();
        }
    })->execute(
        ProcessTargetType::AppInstance,
        $this->instance->id,
    );
    $removed = new RemoveProcessAction($this->runtime, $this->targets)->execute($process);

    expect($listed)
        ->toHaveCount(1)
        ->and($listed->first()['runtime_status'])
        ->toBe('stopped')
        ->and($removed->exists)
        ->toBeFalse()
        ->and($this->runtime->removed)
        ->toBe([$process->id])
        ->and(Process::query()->count())
        ->toBe(0);
});

it('does not persist a start outcome after a peer writes a completed stop', function (): void {
    $process = process_actions_record($this->instance);
    $peerPersisted = false;
    $this->runtime->duringStart = function () use ($process, &$peerPersisted): void {
        $peer = Cache::lock(process_actions_runtime_lock_key($process), 60);

        if ($peer->get()) {
            $peerPersisted = true;
            $process->update([
                'desired_state' => DesiredProcessState::Stopped,
                'status' => LifecycleStatus::Active,
            ]);
            $peer->release();
        }
    };

    $started = new StartProcessAction($this->runtime, app(ProcessAdmissionLock::class))->execute($process);

    expect($peerPersisted)
        ->toBeFalse()
        ->and($started->desired_state)
        ->toBe(DesiredProcessState::Running)
        ->and($process->refresh()->desired_state)
        ->toBe(DesiredProcessState::Running);
});

it('does not persist a start outcome while remove still owns the runtime lease', function (): void {
    $process = process_actions_record($this->instance);
    $peerPersisted = false;
    $this->runtime->duringRemove = function () use ($process, &$peerPersisted): void {
        $peer = Cache::lock(process_actions_runtime_lock_key($process), 60);

        if ($peer->get()) {
            $peerPersisted = true;
            $process->update([
                'desired_state' => DesiredProcessState::Running,
                'status' => LifecycleStatus::Active,
            ]);
            $peer->release();
        }
    };

    $removed = new RemoveProcessAction($this->runtime, $this->targets)->execute($process);

    expect($peerPersisted)
        ->toBeFalse()
        ->and($removed->exists)
        ->toBeFalse()
        ->and(Process::query()->count())
        ->toBe(0);
});

it('leaves intent and lifecycle unchanged when a start loses the runtime owner', function (): void {
    $process = process_actions_record($this->instance);
    $original = $process->fresh()->getRawOriginal();
    $lock = Cache::lock(process_actions_runtime_lock_key($process), 60);
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => new StartProcessAction($this->runtime, app(ProcessAdmissionLock::class))->execute($process))
            ->toThrow(function (ProcessOperationException $exception): void {
                expect($exception->errorCode)
                    ->toBe('process.runtime_lock_failed')
                    ->and($exception->step)
                    ->toBe('lock-runtime');
            });

        expect($process->refresh()->getRawOriginal())
            ->toBe($original)
            ->and($this->runtime->started)
            ->toBeEmpty();
    } finally {
        $lock->release();
    }
});

it('leaves intent and lifecycle unchanged when a stop loses the runtime owner', function (): void {
    $process = process_actions_record($this->instance);
    $process->update(['desired_state' => DesiredProcessState::Running]);
    $original = $process->fresh()->getRawOriginal();
    $lock = Cache::lock(process_actions_runtime_lock_key($process), 60);
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => new StopProcessAction($this->runtime)->execute($process))
            ->toThrow(ProcessOperationException::class);

        expect($process->refresh()->getRawOriginal())
            ->toBe($original)
            ->and($this->runtime->stopped)
            ->toBeEmpty();
    } finally {
        $lock->release();
    }
});

it('leaves the process definition unchanged when removal loses the runtime owner', function (): void {
    $process = process_actions_record($this->instance);
    $original = $process->fresh()->getRawOriginal();
    $lock = Cache::lock(process_actions_runtime_lock_key($process), 60);
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => new RemoveProcessAction($this->runtime, $this->targets)->execute($process))
            ->toThrow(function (ProcessOperationException $exception): void {
                expect($exception->errorCode)->toBe('process.runtime_lock_failed');
                expect($exception->step)->toBe('lock-runtime');
            });

        expect($process->refresh()->getRawOriginal())
            ->toBe($original)
            ->and($process->refresh()->status)
            ->toBe(LifecycleStatus::Active)
            ->and($this->runtime->removed)
            ->toBeEmpty()
            ->and(Process::query()->count())
            ->toBe(1);
    } finally {
        $lock->release();
    }
});

it('refreshes the surviving process identity when an identical add retries under the runtime owner', function (): void {
    $process = process_actions_record($this->instance);
    $process->update([
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Failed,
        'failed_step' => 'start',
        'error_code' => 'process.start_failed',
    ]);
    $data = new AddProcessData(
        targetType: ProcessTargetType::AppInstance,
        targetId: $this->instance->id,
        name: 'queue',
        runtime: ProcessRuntime::Systemd,
        command: ['/usr/bin/php', 'artisan', 'queue:work'],
        image: null,
        workingDirectory: null,
        environment: [],
        ports: [],
        volumes: [],
        restartPolicy: 'always',
        start: false,
    );

    $result = new AddProcessAction($this->targets, $this->runtime, app(ProcessAdmissionLock::class))->execute($data);

    expect($result['created'])
        ->toBeFalse()
        ->and($result['process']->id)
        ->toBe($process->id)
        ->and($result['process']->desired_state)
        ->toBe(DesiredProcessState::Running)
        ->and($result['process']->status)
        ->toBe(LifecycleStatus::Active)
        ->and($result['process']->failed_step)
        ->toBeNull()
        ->and($result['process']->error_code)
        ->toBeNull()
        ->and(Process::query()->count())
        ->toBe(1);
});

it('does not rewrite an existing process when an identical add loses the runtime owner', function (): void {
    $process = process_actions_record($this->instance);
    $process->update([
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Failed,
        'failed_step' => 'start',
        'error_code' => 'process.start_failed',
    ]);
    $original = $process->fresh()->getRawOriginal();
    $lock = Cache::lock(process_actions_runtime_lock_key($process), 60);
    expect($lock->get())->toBeTrue();

    try {
        expect(fn () => new AddProcessAction($this->targets, $this->runtime, app(ProcessAdmissionLock::class))->execute(
            new AddProcessData(
                targetType: ProcessTargetType::AppInstance,
                targetId: $this->instance->id,
                name: 'queue',
                runtime: ProcessRuntime::Systemd,
                command: ['/usr/bin/php', 'artisan', 'queue:work'],
                image: null,
                workingDirectory: null,
                environment: [],
                ports: [],
                volumes: [],
                restartPolicy: 'always',
                start: false,
            ),
        ))->toThrow(ProcessOperationException::class);

        expect($process->refresh()->getRawOriginal())
            ->toBe($original)
            ->and($this->runtime->converged)
            ->toBeEmpty();
    } finally {
        $lock->release();
    }
});

it('keeps the process definition until runtime removal succeeds', function (): void {
    $process = process_actions_record($this->instance);
    $statusDuringRemoval = null;
    $this->runtime->duringRemove = function () use ($process, &$statusDuringRemoval): void {
        $statusDuringRemoval = $process->fresh()?->status;
    };

    new RemoveProcessAction($this->runtime, $this->targets)->execute($process);

    expect($statusDuringRemoval)->toBe(LifecycleStatus::Removing);
    expect($this->runtime->removed)->toBe([$process->id]);
    $this->assertModelMissing($process);
});

it('records stable lifecycle failure state without losing the process definition', function (): void {
    $process = process_actions_record($this->instance);
    $this->runtime->startFailure = new ProcessOperationException(
        step: 'start',
        errorCode: 'process.start_failed',
        message: 'Start failed.',
    );

    expect(fn () => new StartProcessAction($this->runtime, app(ProcessAdmissionLock::class))->execute($process))
        ->toThrow(ProcessOperationException::class, 'Start failed.');

    expect($process->refresh())
        ->status->toBe(LifecycleStatus::Failed)
        ->failed_step->toBe('start')
        ->error_code->toBe('process.start_failed')->and(Process::query()->count())->toBe(1);
});

it('retains the process definition and exact failure when runtime removal fails', function (string $errorCode): void {
    $process = process_actions_record($this->instance);
    $this->runtime->removeFailure = new ProcessOperationException(
        step: 'stop',
        errorCode: $errorCode,
        message: 'The owned unit could not be stopped.',
    );

    expect(fn () => new RemoveProcessAction($this->runtime, $this->targets)->execute($process))
        ->toThrow(ProcessOperationException::class, 'could not be stopped');

    expect($process->refresh())
        ->status->toBe(LifecycleStatus::Failed)
        ->failed_step->toBe('stop')
        ->error_code->toBe($errorCode)->and(Process::query()->count())->toBe(1);
})->with([
    'remove failure' => 'process.remove_failed',
    'stop failure' => 'process.stop_failed',
]);

function process_actions_runtime_lock_key(Process $process): string
{
    $nodeId = $process->owner_type === AppInstance::class
        ? (int) AppInstance::query()->whereKey($process->owner_id)->value('node_id')
        : 0;

    return "orbit:process-runtime:{$nodeId}:{$process->id}";
}

function process_actions_record(AppInstance $instance): Process
{
    return Process::query()->create([
        'owner_type' => AppInstance::class,
        'owner_id' => $instance->id,
        'name' => 'queue',
        'runtime' => ProcessRuntime::Systemd,
        'working_directory' => $instance->checkout_path,
        'runtime_config' => [
            'command' => ['/usr/bin/php', 'artisan', 'queue:work'],
            'environment_file' => $instance->checkout_path.'/.env',
        ],
        'restart_policy' => 'always',
        'desired_state' => 'stopped',
        'status' => LifecycleStatus::Active,
    ]);
}

final class ProcessActionsFakeRuntimeManager implements ProcessRuntimeManager
{
    /** @var list<array{id: int, desired_state: string}> */
    public array $converged = [];

    /** @var list<int> */
    public array $started = [];

    /** @var list<string> */
    public array $startedNames = [];

    /** @var list<int> */
    public array $stopped = [];

    /** @var list<int> */
    public array $restarted = [];

    /** @var list<string> */
    public array $restartedNames = [];

    /** @var list<int> */
    public array $removed = [];

    /** @var list<int> */
    public array $logLines = [];

    public string $status = 'stopped';

    public string $logs = '';

    public ?ProcessOperationException $startFailure = null;

    public ?ProcessOperationException $removeFailure = null;

    /** @var list<array{name: string, exists: bool}> */
    public array $startPreflights = [];

    public bool $startUnavailable = false;

    public ?Closure $duringStart = null;

    public ?Closure $duringRemove = null;

    public function assertCanStart(Process $process): void
    {
        $this->startPreflights[] = ['name' => $process->name, 'exists' => $process->exists];

        if ($this->startUnavailable) {
            throw new ResourceOperationException(
                errorCode: 'process.release_unavailable',
                message: "Process [{$process->name}] has no selected production release.",
                status: 409,
            );
        }
    }

    public function converge(#[SensitiveParameter] Process $process): void
    {
        if ($this->startFailure instanceof ProcessOperationException) {
            throw new ProcessOperationException(
                step: $this->startFailure->step,
                errorCode: $this->startFailure->errorCode,
                message: $this->startFailure->getMessage(),
            );
        }

        $this->converged[] = [
            'id' => $process->id,
            'desired_state' => $process->desired_state->value,
        ];
    }

    public function start(Process $process): void
    {
        if ($this->duringStart instanceof Closure) {
            ($this->duringStart)();
        }

        if ($this->startFailure instanceof ProcessOperationException) {
            throw $this->startFailure;
        }

        $this->started[] = $process->id;
        $this->startedNames[] = $process->name;
    }

    public function stop(Process $process): void
    {
        $this->stopped[] = $process->id;
    }

    public function restart(Process $process): void
    {
        $this->restarted[] = $process->id;
        $this->restartedNames[] = $process->name;
    }

    public function remove(Process $process): void
    {
        if ($this->duringRemove instanceof Closure) {
            ($this->duringRemove)();
        }

        if ($this->removeFailure instanceof ProcessOperationException) {
            throw $this->removeFailure;
        }

        $this->removed[] = $process->id;
    }

    public function status(Process $process): string
    {
        return $this->status;
    }

    public function logs(Process $process, int $lines): string
    {
        $this->logLines[] = $lines;

        return $this->logs;
    }
}

final class ProcessActionsFakeAdmissionLock implements ProcessAdmissionLock
{
    /** @var list<list<int>> */
    public array $runs = [];

    public function __construct(
        private readonly Closure $beforeOperation,
    ) {}

    public function run(array $appInstanceIds, Closure $operation): mixed
    {
        $this->runs[] = $appInstanceIds;
        ($this->beforeOperation)();

        return $operation();
    }
}

describe('the analytics role\'s plausible Process', function (): void {
    beforeEach(function (): void {
        $storage = analytics_storage_processes();
        $this->storageProcesses = $storage;
        $this->connection = AnalyticsStorageConnection::from($storage['postgres'], $storage['clickhouse']);
        $this->lifecycle = app(NativePlausibleRuntimeLifecycle::class);
        $this->assignAnalytics = function () use ($storage): void {
            $this->node->roles()->create(['role' => RoleName::Analytics, 'status' => LifecycleStatus::Active]);
            app(AnalyticsRoleSettingsRepository::class)->store(
                $this->node,
                new AnalyticsRoleSettings($storage['postgres']->id, $storage['clickhouse']->id),
            );
        };
    });

    it('creates a running Node-targeted Docker Process, and converges it again without replacing it', function (): void {
        $first = $this->lifecycle->converge($this->node, '3.2.1', $this->connection, str_repeat('k', 64));
        $second = $this->lifecycle->converge($this->node, '3.2.1', $this->connection, str_repeat('k', 64));

        expect($first->name)->toBe('plausible')
            ->and($first->owner_type)->toBe(Node::class)
            ->and($first->owner_id)->toBe($this->node->id)
            ->and($first->runtime)->toBe(ProcessRuntime::Docker)
            ->and($first->desired_state)->toBe(DesiredProcessState::Running)
            ->and($first->runtime_config['image'])->toBe('ghcr.io/plausible/community-edition:v3.2.1')
            ->and($second->id)->toBe($first->id)
            ->and($this->runtime->removed)->toBe([]);
    });

    it('replaces the Process when the pinned version changes', function (): void {
        $first = $this->lifecycle->converge($this->node, '3.2.1', $this->connection, str_repeat('k', 64));
        $second = $this->lifecycle->converge($this->node, '3.3.0', $this->connection, str_repeat('k', 64));

        expect($this->runtime->removed)->toBe([$first->id])
            ->and($second->id)->not->toBe($first->id)
            ->and($second->runtime_config['image'])->toBe('ghcr.io/plausible/community-edition:v3.3.0')
            ->and(Process::query()->where('name', 'plausible')->count())->toBe(1);
    });

    it('refuses an operator who removes a Process the assigned role needs', function (string $which): void {
        $plausible = $this->lifecycle->converge($this->node, '3.2.1', $this->connection, str_repeat('k', 64));
        ($this->assignAnalytics)();
        $process = ['plausible' => $plausible, 'postgres' => $this->storageProcesses['postgres'], 'clickhouse' => $this->storageProcesses['clickhouse']][$which];

        expect(fn () => app(RemoveProcessAction::class)->execute($process))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('process.required_by_analytics')->and($exception->status)->toBe(409));
        expect($this->runtime->removed)->toBe([])
            ->and(Process::query()->whereKey($process->id)->exists())->toBeTrue();
    })->with(['plausible', 'postgres', 'clickhouse']);

    it('lets the role remove its own Process, and lets an operator remove the storage once the role is gone', function (): void {
        $plausible = $this->lifecycle->converge($this->node, '3.2.1', $this->connection, str_repeat('k', 64));
        ($this->assignAnalytics)();

        $this->lifecycle->remove($this->node);
        $this->node->roles()->delete();
        app(RemoveProcessAction::class)->execute($this->storageProcesses['postgres']);

        expect($this->runtime->removed)->toBe([$plausible->id, $this->storageProcesses['postgres']->id]);
    });

    it('forgets the record of a node it cannot reach without touching the runtime', function (): void {
        $this->lifecycle->converge($this->node, '3.2.1', $this->connection, str_repeat('k', 64));

        $this->lifecycle->forget($this->node);

        expect(Process::query()->where('name', 'plausible')->exists())->toBeFalse()
            ->and($this->runtime->removed)->toBe([]);
    });
});
