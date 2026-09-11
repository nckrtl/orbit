<?php

declare(strict_types=1);

use App\Actions\AppInstances\AdoptProductionLayoutAction;
use App\Actions\Processes\StartProcessAction;
use App\Data\AppInstances\PrepareAppInstanceDeploymentLayoutData;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppInstances\DeploymentLayout\DeploymentLayoutInventory;
use App\Domain\AppInstances\DeploymentLayout\ProductionLayoutConverter;
use App\Domain\AppInstances\DeploymentLayout\ProductionPhpRuntimeAdopter;
use App\Domain\AppInstances\DevelopmentSourceProfile;
use App\Domain\AppInstances\DevelopmentSourceResolution;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContext;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentReader;
use App\Domain\AppInstances\ProductionAppInstanceSourceLifecycle;
use App\Domain\AppInstances\ProductionReleaseLayout;
use App\Domain\AppInstances\ProductionRouteProjector;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceDeploymentLayout;
use App\Models\Node;
use App\Models\Process;
use App\Models\Route;
use App\Models\Schedule;

beforeEach(function (): void {
    $this->instance = orb217_domain_instance();
    $this->converter = new Orb217DomainConverter;
    $this->runtime = new Orb217DomainRuntime;
    $this->source = new Orb217DomainSourceLifecycle;
    $this->projection = new Orb217DomainProjection;
    $this->processLock = new Orb217DomainLock;
    app()->instance(ProductionLayoutConverter::class, $this->converter);
    app()->instance(ProductionPhpRuntimeAdopter::class, $this->runtime);
    app()->instance(ProductionAppInstanceSourceLifecycle::class, $this->source);
    app()->instance(ProductionRouteProjector::class, $this->projection);
    app()->instance(ProductionReleaseLayout::class, new Orb217DomainReleaseLayout);
    app()->instance(AppInstanceEnvironmentReader::class, new Orb217DomainEnvironmentReader);
    app()->instance(AppInstanceEnvironmentOperationLock::class, new Orb217DomainLock);
    app()->instance(ProcessAdmissionLock::class, $this->processLock);
    app()->instance(DevelopmentProjectionOperationLock::class, new Orb217DomainProjectionLock);
});

it('records each successful boundary and resumes the failed effect without replacing inventory', function (
    string $failure,
    string $expectedStep,
): void {
    match ($failure) {
        'move' => $this->converter->failOnce = 'move',
        'persistent' => $this->converter->failOnce = 'persistent',
        'runtime' => $this->runtime->failOnce = true,
        'caddy' => $this->source->failOnce = true,
        'route' => $this->projection->failOnce = true,
    };
    $action = app(AdoptProductionLayoutAction::class);

    expect(fn () => $action->execute($this->instance, new PrepareAppInstanceDeploymentLayoutData(null)))
        ->toThrow(ResourceOperationException::class);

    $record = AppInstanceDeploymentLayout::query()->sole();
    $inventory = $record->inventory;
    expect($record->step->value)
        ->toBe($expectedStep)
        ->and($record->failed_step)
        ->toBe($expectedStep)
        ->and($record->error_code)
        ->toBe("deployment_layout.{$failure}_interrupted");

    $completed = $action->execute($this->instance->refresh(), new PrepareAppInstanceDeploymentLayoutData(null));

    expect($record->refresh()->step->value)
        ->toBe('completed')
        ->and($record->completed_at)
        ->not->toBeNull()
        ->and($record->inventory)
        ->toBe($inventory)
        ->and($completed->checkout_path)
        ->toBe("{$this->instance->production_home}/releases/initial");
})->with([
    'source movement' => ['move', 'accepted'],
    'persistent placement' => ['persistent', 'source_moved'],
    'runtime publication' => ['runtime', 'persistent_state_placed'],
    'Caddy access preparation' => ['caddy', 'runtime_published'],
    'Route projection' => ['route', 'runtime_published'],
]);

it('prepares Caddy access to the recorded release before Route publication', function (): void {
    $events = new Orb217DomainEvents;
    $this->source->events = $events;
    $this->projection->events = $events;

    app(AdoptProductionLayoutAction::class)->execute(
        $this->instance,
        new PrepareAppInstanceDeploymentLayoutData(null),
    );

    expect($events->values)
        ->toBe(['caddy-access', 'route-publication'])
        ->and($this->source->checkoutPaths)
        ->toBe(["{$this->instance->production_home}/releases/initial"]);
});

it('refuses conversion before mutation when local and stored environment values disagree', function (): void {
    app()->instance(AppInstanceEnvironmentReader::class, new class implements AppInstanceEnvironmentReader
    {
        public function read(AppInstanceEnvironmentContext $context): string
        {
            return "KEY=other\n";
        }
    });

    expect(fn () => app(AdoptProductionLayoutAction::class)->execute(
        $this->instance,
        new PrepareAppInstanceDeploymentLayoutData(null),
    ))->toThrow(ResourceOperationException::class, 'stored and local');

    expect($this->converter->calls)->toBe([])->and(AppInstanceDeploymentLayout::query()->count())->toBe(0);
});

it('holds Process admission and refuses an active owned Process before SQLite preflight', function (): void {
    Process::query()->create([
        'owner_type' => AppInstance::class,
        'owner_id' => $this->instance->id,
        'name' => 'queue',
        'runtime' => 'systemd',
        'working_directory' => '.',
        'runtime_config' => ['command' => ['/usr/bin/php', 'artisan', 'queue:work']],
        'desired_state' => 'running',
        'status' => 'active',
    ]);

    expect(fn () => app(AdoptProductionLayoutAction::class)->execute(
        $this->instance,
        new PrepareAppInstanceDeploymentLayoutData('database.sqlite'),
    ))->toThrow(ResourceOperationException::class, 'active AppInstance Process');

    expect($this->processLock->runs)
        ->toBe(1)
        ->and($this->converter->calls)
        ->toBe([])
        ->and(AppInstanceDeploymentLayout::query()->count())
        ->toBe(0);
});

it('holds Process admission and refuses layout conversion while a Schedule uses its stable path', function (): void {
    Schedule::query()->create([
        'target_type' => AppInstance::class,
        'target_id' => $this->instance->id,
        'host_node_id' => $this->instance->node_id,
        'name' => 'daily',
        'calendar' => 'daily',
        'command' => 'true',
        'timeout_seconds' => 3600,
        'desired_timer_state' => DesiredTimerState::Enabled,
        'status' => LifecycleStatus::Active,
    ]);

    expect(fn () => app(AdoptProductionLayoutAction::class)->execute(
        $this->instance,
        new PrepareAppInstanceDeploymentLayoutData(null),
    ))->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'schedule.target_in_use');

    expect($this->processLock->runs)->toBe(1)
        ->and($this->converter->calls)->toBe([])
        ->and(AppInstanceDeploymentLayout::query()->count())->toBe(0);
});

it('rechecks owned Processes and open-file quiescence before a retried SQLite placement', function (): void {
    $this->converter->failOnce = 'persistent';
    $action = app(AdoptProductionLayoutAction::class);
    $data = new PrepareAppInstanceDeploymentLayoutData('database.sqlite');

    expect(fn () => $action->execute($this->instance, $data))->toThrow(ResourceOperationException::class);

    Process::query()->create([
        'owner_type' => AppInstance::class,
        'owner_id' => $this->instance->id,
        'name' => 'queue',
        'runtime' => 'systemd',
        'working_directory' => '.',
        'runtime_config' => ['command' => ['/usr/bin/php', 'artisan', 'queue:work']],
        'desired_state' => 'running',
        'status' => 'active',
    ]);

    expect(fn () => $action->execute($this->instance->refresh(), $data))
        ->toThrow(ResourceOperationException::class, 'active AppInstance Process');

    Process::query()->delete();
    $this->converter->failSqliteQuiescenceOnce = true;

    expect(fn () => $action->execute($this->instance->refresh(), $data))
        ->toThrow(ResourceOperationException::class, 'SQLite handle appeared');

    expect(array_count_values($this->converter->calls))
        ->toMatchArray(['persistent' => 1, 'sqlite-quiescence' => 2]);
});

it('refuses Route drift under the projection owner before publication', function (): void {
    $this->projection->failOnce = true;
    $action = app(AdoptProductionLayoutAction::class);
    $data = new PrepareAppInstanceDeploymentLayoutData(null);

    expect(fn () => $action->execute($this->instance, $data))->toThrow(ResourceOperationException::class);

    Route::query()->sole()->update(['hostname' => 'changed-layout-domain.test']);

    expect(fn () => $action->execute($this->instance->refresh(), $data))
        ->toThrow(ResourceOperationException::class, 'serving association changed');

    expect($this->projection->failOnce)
        ->toBeFalse()
        ->and($this->projection->publications)
        ->toBe(0);
});

it('keeps an action-level Process start behind the clean SQLite snapshot and placement', function (): void {
    $process = Process::query()->create([
        'owner_type' => AppInstance::class,
        'owner_id' => $this->instance->id,
        'name' => 'queue',
        'runtime' => 'systemd',
        'working_directory' => '.',
        'runtime_config' => ['command' => ['/usr/bin/php', 'artisan', 'queue:work']],
        'desired_state' => 'stopped',
        'status' => 'failed',
    ]);
    $events = new Orb217DomainEvents;
    $lock = new Orb217QueuedDomainProcessLock($process);
    $runtime = new Orb217ProcessRuntime($events);
    $this->converter->events = $events;
    $this->converter->duringSqliteQuiescence = function () use ($lock, $process, $runtime): void {
        $started = new StartProcessAction($runtime, $lock)->execute($process);

        expect($started->desired_state->value)->toBe('stopped');
    };
    app()->instance(ProcessAdmissionLock::class, $lock);

    app(AdoptProductionLayoutAction::class)->execute(
        $this->instance,
        new PrepareAppInstanceDeploymentLayoutData('database.sqlite'),
    );

    expect($events->values)
        ->toBe(['sqlite-quiescence', 'persistent-placement', 'process-start'])
        ->and($process->refresh()->desired_state->value)
        ->toBe('running');
});

function orb217_domain_instance(): AppInstance
{
    $node = Node::query()->create([
        'name' => 'layout-domain-owner',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.217',
        'wireguard_ip' => '10.44.0.217',
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $app = OrbitApp::query()->create([
        'name' => 'Layout domain',
        'slug' => 'layout-domain',
        'repository_url' => 'https://example.test/layout-domain.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $home = "/home/orbit-app-{$app->id}";
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'default',
        'environment' => 'production',
        'checkout_path' => $home,
        'production_home' => $home,
        'production_user' => "orbit-app-{$app->id}",
        'root' => 'public',
        'selected_php_version' => '8.5',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
    $instance->environmentValues()->create(['env_key' => 'KEY', 'env_value' => 'value']);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'hostname' => 'layout-domain.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);

    return $instance->fresh(['app', 'node']);
}

final class Orb217DomainConverter implements ProductionLayoutConverter
{
    /** @var list<string> */
    public array $calls = [];

    public ?string $failOnce = null;

    public ?Closure $duringSqliteQuiescence = null;

    public bool $failSqliteQuiescenceOnce = false;

    public ?Orb217DomainEvents $events = null;

    public function preflight(AppInstance $appInstance, Route $route, string $expectedEnvironment, ?string $sqliteSourcePath): DeploymentLayoutInventory
    {
        $this->calls[] = 'preflight';
        $tuning = "[orbit-{$appInstance->production_user}]\npm = ondemand\n";

        return new DeploymentLayoutInventory(
            $appInstance->checkout_path,
            "{$appInstance->production_home}/releases/initial",
            '1:2',
            str_repeat('a', 40),
            hash('sha256', ''),
            hash('sha256', $expectedEnvironment),
            $sqliteSourcePath,
            $sqliteSourcePath === null ? null : hash('sha256', 'sqlite'),
            $sqliteSourcePath === null ? null : '8:217',
            hash('sha256', $tuning),
            $route->id,
            (int) $route->node_id,
            $route->hostname,
            $route->status->value,
            hash('sha256', json_encode([
                ['app_instance_id' => $appInstance->id, 'position' => 0],
            ], JSON_THROW_ON_ERROR)),
            'public',
            "/run/php/orbit-app-instance-{$appInstance->id}.sock",
        );
    }

    public function moveSource(AppInstance $appInstance, DeploymentLayoutInventory $inventory): void
    {
        $this->effect('move');
    }

    public function assertSqliteQuiescent(AppInstance $appInstance, DeploymentLayoutInventory $inventory): void
    {
        $this->calls[] = 'sqlite-quiescence';

        if ($this->events instanceof Orb217DomainEvents) {
            $this->events->values[] = 'sqlite-quiescence';
        }

        if ($this->failSqliteQuiescenceOnce) {
            $this->failSqliteQuiescenceOnce = false;
            throw new ResourceOperationException('deployment_layout.sqlite_not_quiescent', 'A SQLite handle appeared.', 409);
        }

        if ($this->duringSqliteQuiescence instanceof Closure) {
            ($this->duringSqliteQuiescence)();
        }
    }

    public function placePersistentState(AppInstance $appInstance, DeploymentLayoutInventory $inventory): void
    {
        if ($this->events instanceof Orb217DomainEvents) {
            $this->events->values[] = 'persistent-placement';
        }

        $this->effect('persistent');
    }

    public function runtimeTuning(AppInstance $appInstance, DeploymentLayoutInventory $inventory): string
    {
        $this->calls[] = 'tuning';

        return "[orbit-{$appInstance->production_user}]\npm = ondemand\n";
    }

    public function validateServingAssociation(AppInstance $appInstance, DeploymentLayoutInventory $inventory): void
    {
        $this->calls[] = 'serving-validation';
    }

    public function validatePlacedLayout(AppInstance $appInstance, DeploymentLayoutInventory $inventory): void
    {
        $this->calls[] = 'validate';
    }

    private function effect(string $name): void
    {
        $this->calls[] = $name;

        if ($this->failOnce === $name) {
            $this->failOnce = null;
            throw new ResourceOperationException("deployment_layout.{$name}_interrupted", 'Interrupted.', 409);
        }
    }
}

final class Orb217DomainRuntime implements ProductionPhpRuntimeAdopter
{
    public bool $failOnce = false;

    public function adopt(AppInstance $appInstance, string $initialLocalTuning): void
    {
        if ($this->failOnce) {
            $this->failOnce = false;
            throw new ResourceOperationException('deployment_layout.runtime_interrupted', 'Interrupted.', 409);
        }
    }
}

final class Orb217DomainSourceLifecycle implements ProductionAppInstanceSourceLifecycle
{
    public bool $failOnce = false;

    public ?Orb217DomainEvents $events = null;

    /** @var list<string> */
    public array $checkoutPaths = [];

    public function prepareUser(AppInstance $appInstance): void {}

    public function prepareSource(AppInstance $appInstance, bool $allowExisting): void {}

    public function resolve(AppInstance $appInstance): DevelopmentSourceResolution
    {
        throw new LogicException('Not used by this test fake.');
    }

    public function inspectProfile(AppInstance $appInstance): DevelopmentSourceProfile
    {
        throw new LogicException('Not used by this test fake.');
    }

    public function prepareCaddyAccess(AppInstance $appInstance): void
    {
        if ($this->events instanceof Orb217DomainEvents) {
            $this->events->values[] = 'caddy-access';
        }

        $this->checkoutPaths[] = $appInstance->checkout_path;

        if ($this->failOnce) {
            $this->failOnce = false;
            throw new ResourceOperationException('deployment_layout.caddy_interrupted', 'Interrupted.', 409);
        }
    }
}

final class Orb217DomainProjection implements ProductionRouteProjector
{
    public bool $failOnce = false;

    public int $publications = 0;

    public ?Orb217DomainEvents $events = null;

    public function prepareRuntime(AppInstance $appInstance, Route $route): void {}

    public function prepareCertificate(AppInstance $appInstance, Route $route): void {}

    public function prepareFirewall(AppInstance $appInstance): void {}

    public function publish(AppInstance $appInstance, Route $route): void
    {
        if ($this->events instanceof Orb217DomainEvents) {
            $this->events->values[] = 'route-publication';
        }

        if ($this->failOnce) {
            $this->failOnce = false;
            throw new ResourceOperationException('deployment_layout.route_interrupted', 'Interrupted.', 409);
        }

        $this->publications++;
    }
}

final class Orb217DomainEnvironmentReader implements AppInstanceEnvironmentReader
{
    public function read(AppInstanceEnvironmentContext $context): string
    {
        return "KEY=value\n";
    }
}

class Orb217DomainLock implements AppInstanceEnvironmentOperationLock, ProcessAdmissionLock
{
    public int $runs = 0;

    public function run(array $appInstanceIds, Closure $operation): mixed
    {
        $this->runs++;

        return $operation();
    }
}

final class Orb217DomainProjectionLock implements DevelopmentProjectionOperationLock
{
    public function run(Closure $operation): mixed
    {
        return $operation();
    }
}

final class Orb217DomainReleaseLayout implements ProductionReleaseLayout
{
    public function validateCurrent(AppInstance $appInstance): void {}

    public function clearCurrent(AppInstance $appInstance): void {}
}

final class Orb217DomainEvents
{
    /** @var list<string> */
    public array $values = [];
}

final class Orb217QueuedDomainProcessLock implements ProcessAdmissionLock
{
    private bool $held = false;

    /** @var list<Closure(): mixed> */
    private array $pending = [];

    public function __construct(private readonly Process $queuedReturn) {}

    public function run(array $appInstanceIds, Closure $operation): mixed
    {
        if ($this->held) {
            $this->pending[] = $operation;

            return $this->queuedReturn;
        }

        $this->held = true;

        try {
            return $operation();
        } finally {
            $this->held = false;

            foreach ($this->pending as $pending) {
                $pending();
            }

            $this->pending = [];
        }
    }
}

final readonly class Orb217ProcessRuntime implements ProcessRuntimeManager
{
    public function __construct(private Orb217DomainEvents $events) {}

    public function assertCanStart(Process $process): void {}

    public function converge(Process $process): void {}

    public function start(Process $process): void
    {
        $this->events->values[] = 'process-start';
    }

    public function stop(Process $process): void {}

    public function restart(Process $process): void {}

    public function remove(Process $process): void {}

    public function status(Process $process): string
    {
        return 'active';
    }

    public function logs(Process $process, int $lines): string
    {
        return '';
    }
}
