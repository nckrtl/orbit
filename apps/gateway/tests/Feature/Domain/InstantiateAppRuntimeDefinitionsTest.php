<?php

declare(strict_types=1);

use App\Actions\AppInstances\InstantiateAppRuntimeDefinitionsAction;
use App\Actions\Processes\CascadeAppInstanceProcessesAction;
use App\Actions\Processes\RemoveProcessAction;
use App\Actions\Schedules\CascadeAppInstanceSchedulesAction;
use App\Actions\Schedules\RemoveScheduleAction;
use App\Data\Schedules\ScheduleLogsData;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessSpecification;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Schedules\ScheduleErrorCode;
use App\Domain\Schedules\ScheduleOperationException;
use App\Domain\Schedules\ScheduleRuntimeManager;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use App\Models\ProcessDefinition;
use App\Models\Schedule;
use App\Models\ScheduleDefinition;
use Tests\Support\Schedules\FakeScheduleRuntimeAccountResolver;

beforeEach(function (): void {
    $this->node = Node::query()->create([
        'name' => 'app-prod',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.30',
        'wireguard_ip' => '10.44.0.3',
        'user' => 'orbit',
    ]);
    $this->orbitApp = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'git@example.test:acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $this->candidate = orb225_instance($this->orbitApp, $this->node, 'candidate', 'development');
    $this->target = orb225_instance($this->orbitApp, $this->node, 'target', 'production');
    $this->processRuntime = new Orb225ProcessRuntimeManager;
    $this->scheduleRuntime = new Orb225ScheduleRuntimeManager;
    $this->action = orb225_action($this->processRuntime, $this->scheduleRuntime);
});

it('selects App production definitions instead of development or candidate overrides', function (): void {
    $worker = orb225_process_definition($this->orbitApp, 'queue', ['production'], [
        'runtime' => 'systemd',
        'command' => ['/usr/bin/php', 'artisan', 'queue:work', '--tries=3'],
        'restart_policy' => 'always',
    ]);
    orb225_process_definition($this->orbitApp, 'vite', ['development'], [
        'runtime' => 'systemd',
        'command' => ['/usr/bin/vp', 'run', 'dev'],
    ]);
    $scheduleDefinition = orb225_schedule_definition($this->orbitApp, 'cleanup');
    $candidate = orb225_process($this->candidate, 'queue', ['/usr/bin/php', 'artisan', 'queue:work', '--tries=1']);

    $this->action->execute($this->target);

    $copy = Process::query()
        ->where('owner_type', AppInstance::class)
        ->where('owner_id', $this->target->id)
        ->sole();
    $schedule = Schedule::query()
        ->where('target_type', AppInstance::class)
        ->where('target_id', $this->target->id)
        ->sole();

    expect($this->target->refresh()->runtime_definitions_captured_at)->not->toBeNull()
        ->and($copy->source_definition_id)->toBe($worker->id)
        ->and($copy->runtime_config['command'])->toBe(['/usr/bin/php', 'artisan', 'queue:work', '--tries=3'])
        ->and($copy->desired_state)->toBe(DesiredProcessState::Stopped)
        ->and($copy->status)->toBe(LifecycleStatus::Active)
        ->and($schedule->source_definition_id)->toBe($scheduleDefinition->id)
        ->and($schedule->host_node_id)->toBe($this->target->node_id)
        ->and($schedule->desired_timer_state)->toBe(DesiredTimerState::Disabled)
        ->and($schedule->status)->toBe(LifecycleStatus::Active)
        ->and($candidate->refresh()->runtime_config['command'])
        ->toBe(['/usr/bin/php', 'artisan', 'queue:work', '--tries=1'])
        ->and(Process::query()->where('owner_id', $this->target->id)->where('name', 'vite')->exists())
        ->toBeFalse();
});

it('creates independent stopped copies for both Process backends and a disabled Schedule without a release', function (): void {
    $systemd = orb225_process_definition($this->orbitApp, 'queue', ['development', 'production'], [
        'runtime' => 'systemd',
        'command' => ['/usr/bin/php', 'artisan', 'queue:work'],
        'working_directory' => '/home/acme/shared',
        'restart_policy' => 'on-failure',
    ]);
    $docker = orb225_process_definition($this->orbitApp, 'redis', ['production'], [
        'runtime' => 'docker',
        'image' => 'redis:8-alpine',
        'command' => ['redis-server'],
        'environment' => ['ZEBRA' => 'last', 'ALPHA' => 'first'],
        'ports' => ['127.0.0.1:6380:6379/tcp'],
        'volumes' => [['source' => 'redis-data', 'target' => '/data', 'read_only' => false]],
        'restart_policy' => 'unless-stopped',
    ]);
    $definition = orb225_schedule_definition($this->orbitApp, 'report');

    $this->action->execute($this->target);

    $copies = Process::query()
        ->where('owner_type', AppInstance::class)
        ->where('owner_id', $this->target->id)
        ->orderBy('name')
        ->get()
        ->keyBy('name');
    $schedule = Schedule::query()->where('target_id', $this->target->id)->sole();

    expect($copies)->toHaveCount(2)
        ->and($copies['queue']->id)->toBeInt()
        ->and($copies['queue']->source_definition_id)->toBe($systemd->id)
        ->and($copies['queue']->working_directory)->toBe('/home/acme/shared')
        ->and($copies['queue']->runtime_config)->toBe([
            'command' => ['/usr/bin/php', 'artisan', 'queue:work'],
            'environment_file' => '/home/acme-target/.env',
        ])
        ->and($copies['redis']->source_definition_id)->toBe($docker->id)
        ->and($copies['redis']->runtime_config)->toBe([
            'image' => 'redis:8-alpine',
            'command' => ['redis-server'],
            'environment' => ['ALPHA' => 'first', 'ZEBRA' => 'last'],
            'ports' => ['127.0.0.1:6380:6379/tcp'],
            'volumes' => [['source' => 'redis-data', 'target' => '/data', 'read_only' => false]],
        ])
        ->and($copies->pluck('desired_state')->unique()->all())->toBe([DesiredProcessState::Stopped])
        ->and($schedule->id)->not->toBe($definition->id)
        ->and($schedule->desired_timer_state)->toBe(DesiredTimerState::Disabled)
        ->and($this->processRuntime->converged)->toBe(['queue', 'redis'])
        ->and($this->scheduleRuntime->installed)->toBe(['report']);
});

it('permits only captured copies to install before the production target becomes active', function (): void {
    $definition = orb225_process_definition($this->orbitApp, 'queue', ['production'], [
        'runtime' => 'systemd',
        'command' => ['/usr/bin/php', 'artisan', 'queue:work'],
    ]);
    $scheduleDefinition = orb225_schedule_definition($this->orbitApp, 'report');
    $this->action->execute($this->target);
    $process = Process::query()->where('source_definition_id', $definition->id)->sole();
    $schedule = Schedule::query()->where('source_definition_id', $scheduleDefinition->id)->sole();
    $processTargets = new ProcessTargetResolver;
    $scheduleTargets = new ScheduleTargetResolver(new FakeScheduleRuntimeAccountResolver);

    expect(fn () => $processTargets->forAdmission($this->target->refresh()))
        ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'process.target_inactive')
        ->and(fn () => $processTargets->forProcess($process))
        ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'process.target_inactive')
        ->and($processTargets->forInstallation($process)->appInstance->id)->toBe($this->target->id)
        ->and(fn () => $scheduleTargets->forSchedule($schedule))
        ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'schedule.target_unavailable')
        ->and($scheduleTargets->forInstallation($schedule)->appInstance?->id)->toBe($this->target->id);
});

it('captures an empty production selection once', function (): void {
    $this->action->execute($this->target);
    $capturedAt = $this->target->refresh()->runtime_definitions_captured_at;
    orb225_process_definition($this->orbitApp, 'late-worker', ['production'], [
        'runtime' => 'systemd',
        'command' => ['/usr/bin/php', 'artisan', 'queue:work'],
    ]);

    $this->action->execute($this->target->refresh());

    expect($capturedAt)->not->toBeNull()
        ->and($this->target->refresh()->runtime_definitions_captured_at?->equalTo($capturedAt))->toBeTrue()
        ->and(Process::query()->where('owner_id', $this->target->id)->exists())->toBeFalse()
        ->and($this->processRuntime->converged)->toBeEmpty()
        ->and($this->scheduleRuntime->installed)->toBeEmpty();
});

it('retries only unfinished captured copies after definitions and completed operator state change', function (): void {
    $worker = orb225_process_definition($this->orbitApp, 'queue', ['production'], [
        'runtime' => 'systemd',
        'command' => ['/usr/bin/php', 'artisan', 'queue:work'],
    ]);
    $scheduleDefinition = orb225_schedule_definition($this->orbitApp, 'report');
    $this->scheduleRuntime->failName = 'report';

    expect(fn () => $this->action->execute($this->target))
        ->toThrow(ScheduleOperationException::class, 'Schedule artifact conflict.');

    $process = Process::query()->where('owner_id', $this->target->id)->sole();
    $schedule = Schedule::query()->where('target_id', $this->target->id)->sole();
    $capturedAt = $this->target->refresh()->runtime_definitions_captured_at;

    expect($process->restart_policy)->toBe('never');

    $process->update([
        'runtime_config' => [
            'command' => ['/usr/bin/php', 'artisan', 'queue:listen'],
            'environment_file' => '/home/acme-target/.env',
        ],
        'desired_state' => DesiredProcessState::Running,
    ]);
    $worker->delete();
    $scheduleDefinition->delete();
    orb225_process_definition($this->orbitApp, 'new-worker', ['production'], [
        'runtime' => 'systemd',
        'command' => ['/usr/bin/php', 'artisan', 'queue:work', '--new'],
    ]);
    $this->scheduleRuntime->failName = null;

    $this->action->execute($this->target->refresh());

    expect($this->target->refresh()->runtime_definitions_captured_at?->equalTo($capturedAt))->toBeTrue()
        ->and($process->refresh()->runtime_config['command'])->toBe(['/usr/bin/php', 'artisan', 'queue:listen'])
        ->and($process->desired_state)->toBe(DesiredProcessState::Running)
        ->and($schedule->refresh()->status)->toBe(LifecycleStatus::Active)
        ->and($schedule->source_definition_id)->toBe($scheduleDefinition->id)
        ->and($this->processRuntime->converged)->toBe(['queue'])
        ->and($this->scheduleRuntime->installed)->toBe(['report', 'report'])
        ->and(Process::query()->where('owner_id', $this->target->id)->where('name', 'new-worker')->exists())
        ->toBeFalse();
});

it('refuses record and artifact conflicts without adoption and removes only target copies', function (): void {
    $worker = orb225_process_definition($this->orbitApp, 'queue', ['production'], [
        'runtime' => 'systemd',
        'command' => ['/usr/bin/php', 'artisan', 'queue:work'],
    ]);
    $scheduleDefinition = orb225_schedule_definition($this->orbitApp, 'report');
    $candidate = orb225_process($this->candidate, 'candidate-worker', ['/usr/bin/php', 'artisan', 'queue:work']);
    $collision = orb225_process($this->target, 'queue', ['/usr/bin/php', 'artisan', 'queue:listen']);
    $scheduleCollision = orb225_schedule($this->target, 'report');

    expect(fn () => $this->action->execute($this->target))
        ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'process.name_taken');

    expect($this->target->refresh()->runtime_definitions_captured_at)->toBeNull()
        ->and($collision->refresh()->source_definition_id)->toBeNull()
        ->and($scheduleCollision->refresh()->source_definition_id)->toBeNull()
        ->and($this->processRuntime->converged)->toBeEmpty()
        ->and($this->scheduleRuntime->installed)->toBeEmpty();

    $collision->delete();

    expect(fn () => $this->action->execute($this->target->refresh()))
        ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'schedule.retry_conflict');

    expect($this->target->refresh()->runtime_definitions_captured_at)->toBeNull()
        ->and(Process::query()->where('owner_id', $this->target->id)->exists())->toBeFalse()
        ->and($scheduleCollision->refresh()->source_definition_id)->toBeNull();

    $scheduleCollision->delete();
    $this->processRuntime->failName = 'queue';

    expect(fn () => $this->action->execute($this->target->refresh()))
        ->toThrow(ProcessOperationException::class, 'Process artifact conflict.');

    $copy = Process::query()->where('owner_id', $this->target->id)->sole();
    expect($copy->source_definition_id)->toBe($worker->id)
        ->and($copy->status)->toBe(LifecycleStatus::Failed);

    $this->processRuntime->failName = null;
    $this->scheduleRuntime->failName = 'report';

    expect(fn () => $this->action->execute($this->target->refresh()))
        ->toThrow(ScheduleOperationException::class, 'Schedule artifact conflict.');

    $schedule = Schedule::query()->where('target_id', $this->target->id)->sole();
    expect($schedule->source_definition_id)->toBe($scheduleDefinition->id)
        ->and($schedule->status)->toBe(LifecycleStatus::Failed);

    $this->scheduleRuntime->failName = null;
    $this->action->execute($this->target->refresh());
    new CascadeAppInstanceProcessesAction(
        new RemoveProcessAction($this->processRuntime, new ProcessTargetResolver),
    )->execute($this->target->id);
    new CascadeAppInstanceSchedulesAction(
        new RemoveScheduleAction($this->scheduleRuntime),
    )->execute($this->target->id);
    $this->target->delete();

    expect(Process::query()->where('owner_id', $this->target->id)->exists())->toBeFalse()
        ->and(Schedule::query()->where('target_id', $this->target->id)->exists())->toBeFalse()
        ->and($worker->fresh())->not->toBeNull()
        ->and($scheduleDefinition->fresh())->not->toBeNull()
        ->and($candidate->fresh())->not->toBeNull()
        ->and($this->processRuntime->removed)->toBe(['queue'])
        ->and($this->scheduleRuntime->removed)->toBe(['report']);
});

function orb225_action(
    Orb225ProcessRuntimeManager $processRuntime,
    Orb225ScheduleRuntimeManager $scheduleRuntime,
): InstantiateAppRuntimeDefinitionsAction {
    return new InstantiateAppRuntimeDefinitionsAction(
        app(ProcessAdmissionLock::class),
        new ProcessTargetResolver,
        new ProcessSpecification,
        $processRuntime,
        $scheduleRuntime,
    );
}

function orb225_instance(OrbitApp $app, Node $node, string $name, string $environment): AppInstance
{
    $production = $environment === 'production';

    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => $name,
        'environment' => $environment,
        'source_layout' => 'checkout',
        'checkout_path' => $production ? "/home/acme-{$name}/releases/prepared" : "/srv/acme/{$name}",
        'production_user' => $production ? "acme-{$name}" : null,
        'production_home' => $production ? "/home/acme-{$name}" : null,
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'provisioning_step' => $production ? 'source-resolved' : 'active',
        'status' => $production ? 'source_resolved' : 'active',
    ]);
}

/** @param list<string> $command */
function orb225_process(AppInstance $instance, string $name, array $command): Process
{
    return Process::query()->create([
        'owner_type' => AppInstance::class,
        'owner_id' => $instance->id,
        'name' => $name,
        'runtime' => ProcessRuntime::Systemd,
        'working_directory' => $instance->checkout_path,
        'runtime_config' => ['command' => $command],
        'restart_policy' => 'always',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);
}

/**
 * @param  list<string>  $environments
 * @param  array<string, mixed>  $specification
 */
function orb225_process_definition(
    OrbitApp $app,
    string $name,
    array $environments,
    array $specification,
): ProcessDefinition {
    return $app->processDefinitions()->create([
        'name' => $name,
        'environments' => $environments,
        'spec' => $specification,
    ]);
}

function orb225_schedule_definition(OrbitApp $app, string $name): ScheduleDefinition
{
    return $app->scheduleDefinitions()->create([
        'name' => $name,
        'environments' => ['production'],
        'spec' => [
            'command' => '/usr/bin/php artisan schedule:run',
            'calendar' => 'daily',
            'timeout_seconds' => 60,
        ],
    ]);
}

function orb225_schedule(AppInstance $instance, string $name): Schedule
{
    return Schedule::query()->create([
        'target_type' => AppInstance::class,
        'target_id' => $instance->id,
        'host_node_id' => $instance->node_id,
        'name' => $name,
        'calendar' => 'daily',
        'command' => '/usr/bin/php artisan schedule:run',
        'timeout_seconds' => 60,
        'desired_timer_state' => DesiredTimerState::Disabled,
        'status' => LifecycleStatus::Active,
    ]);
}

final class Orb225ProcessRuntimeManager implements ProcessRuntimeManager
{
    /** @var list<string> */
    public array $converged = [];

    /** @var list<string> */
    public array $removed = [];

    public ?string $failName = null;

    public function assertCanStart(Process $process): void {}

    public function converge(Process $process): void
    {
        $this->converged[] = $process->name;

        if ($this->failName === $process->name) {
            throw new ProcessOperationException(
                step: 'inspect-artifacts',
                errorCode: 'process.artifact_conflict',
                message: 'Process artifact conflict.',
            );
        }
    }

    public function start(Process $process): void {}

    public function stop(Process $process): void {}

    public function restart(Process $process): void {}

    public function remove(Process $process): void
    {
        $this->removed[] = $process->name;
    }

    public function status(Process $process): string
    {
        return 'stopped';
    }

    public function logs(Process $process, int $lines): string
    {
        return '';
    }
}

final class Orb225ScheduleRuntimeManager implements ScheduleRuntimeManager
{
    /** @var list<string> */
    public array $installed = [];

    /** @var list<string> */
    public array $removed = [];

    public ?string $failName = null;

    public function install(Schedule $schedule): void
    {
        $this->installed[] = $schedule->name;

        if ($this->failName === $schedule->name) {
            throw new ScheduleOperationException(
                step: 'inspect-artifacts',
                error: ScheduleErrorCode::ArtifactConflict,
                message: 'Schedule artifact conflict.',
            );
        }
    }

    public function activate(Schedule $schedule): void {}

    public function run(Schedule $schedule): void {}

    public function logs(Schedule $schedule, int $lines): ScheduleLogsData
    {
        return new ScheduleLogsData('', false);
    }

    public function remove(Schedule $schedule, bool $cascade): bool
    {
        $this->removed[] = $schedule->name;

        return true;
    }
}
