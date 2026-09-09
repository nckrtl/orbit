<?php

declare(strict_types=1);

use App\Actions\Routes\ConvergeRouteAction;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DevelopmentAppInstanceConfigurator;
use App\Domain\AppInstances\DevelopmentSourceProfile;
use App\Domain\Routes\RouteHostnameChangeDirection;
use App\Domain\Routes\RouteHostnameChangeStep;
use App\Domain\Routes\RouteHostnameProjector;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->events = new RouteHostnameChangeEvents;
    $this->projector = new RouteHostnameChangeProjectorFake($this->events);
    $this->configuration = new RouteHostnameChangeConfiguratorFake($this->events);
    app()->instance(RouteHostnameProjector::class, $this->projector);
    app()->instance(DevelopmentAppInstanceConfigurator::class, $this->configuration);
    app()->instance(
        DevelopmentProjectionOperationLock::class,
        new RouteHostnameChangeOwnerFake($this->events),
    );
});

it('prepares every projection and Laravel URL before DNS then cuts over and cleans up', function (): void {
    $route = route_hostname_change_route(laravel: true);

    $updated = app(ConvergeRouteAction::class)->execute($route, 'next.example.test');

    expect($this->events->values)
        ->toBe([
            'owner',
            'workload-certificate',
            'workload-caddy',
            'router-certificate',
            'firewall-policy',
            'workload-verify',
            'router-caddy',
            'url:https://next.example.test',
            'dns-publication',
            'cleanup',
        ])
        ->and($updated->only([
            'hostname',
            'status',
            'failed_step',
            'error_code',
            'hostname_change_previous',
            'hostname_change_target',
            'hostname_change_direction',
            'hostname_change_step',
        ]))
        ->toBe([
            'hostname' => 'next.example.test',
            'status' => \App\Domain\Routes\RouteStatus::Active,
            'failed_step' => null,
            'error_code' => null,
            'hostname_change_previous' => null,
            'hostname_change_target' => null,
            'hostname_change_direction' => null,
            'hostname_change_step' => null,
        ]);
});

it('does not configure Laravel for a source profile classified as non-Laravel', function (): void {
    $route = route_hostname_change_route(laravel: false);

    app(ConvergeRouteAction::class)->execute($route, 'next.example.test');

    expect($this->events->values)->not->toContain('url:https://next.example.test', 'url:https://old.example.test');
});

it('records each forward boundary failure and completes rollback to the old authoritative state', function (
    string $failure,
    string $failedStep,
): void {
    $route = route_hostname_change_route(laravel: true);
    $this->projector->failures[$failure] = 1;

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'next.example.test'))
        ->toThrow(ResourceOperationException::class, "Injected {$failure} failure.");

    expect($route
        ->refresh()
        ->only([
            'hostname',
            'status',
            'failed_step',
            'error_code',
            'hostname_change_previous',
            'hostname_change_target',
            'hostname_change_direction',
            'hostname_change_step',
        ]))->toBe([
        'hostname' => 'old.example.test',
        'status' => \App\Domain\Routes\RouteStatus::Active,
        'failed_step' => $failedStep,
        'error_code' => "route.test_{$failure}",
        'hostname_change_previous' => 'old.example.test',
        'hostname_change_target' => 'next.example.test',
        'hostname_change_direction' => RouteHostnameChangeDirection::Rollback,
        'hostname_change_step' => RouteHostnameChangeStep::RolledBack,
    ]);
})->with([
    'workload certificate' => ['workload-certificate', 'workload-certificate'],
    'workload Caddy' => ['workload-caddy', 'workload-caddy'],
    'Router certificate' => ['router-certificate', 'router-certificate'],
    'firewall policy' => ['firewall-policy', 'firewall-policy'],
    'workload verification' => ['workload-verify', 'workload-verify'],
    'Router Caddy' => ['router-caddy', 'router-caddy'],
    'DNS publication' => ['dns-publication', 'dns-publication'],
]);

it('records Laravel URL failure and restores the old URL during rollback', function (): void {
    $route = route_hostname_change_route(laravel: true);
    $this->configuration->failures = 1;

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'next.example.test'))
        ->toThrow(ResourceOperationException::class, 'Injected Laravel URL failure.');

    expect($route->refresh()->failed_step)
        ->toBe('laravel-url')
        ->and($route->error_code)
        ->toBe('route.test_laravel_url')
        ->and($route->hostname_change_step)
        ->toBe(RouteHostnameChangeStep::RolledBack)
        ->and($this->events->values)
        ->toContain('url:https://old.example.test');
});

it('records database cutover failure and rolls authoritative DNS back before serving projections', function (): void {
    $route = route_hostname_change_route(laravel: false);
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER route_hostname_change_cutover_failure
        BEFORE UPDATE OF hostname ON routes
        WHEN NEW.hostname = 'next.example.test'
        BEGIN
            SELECT RAISE(ABORT, 'Injected database cutover failure.');
        END
        SQL);

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'next.example.test'))
        ->toThrow(\Illuminate\Database\QueryException::class);

    expect($route->refresh()->hostname)
        ->toBe('old.example.test')
        ->and($route->failed_step)
        ->toBe('database-cutover')
        ->and($route->error_code)
        ->toBe('route.hostname_change_failed')
        ->and(array_slice($this->events->values, -3))
        ->toBe(['rollback-dns', 'rollback-caddy', 'rollback-certificates']);
});

it('resumes after the last durable forward checkpoint', function (): void {
    $route = route_hostname_change_route(laravel: false);
    $route->update([
        'hostname_change_previous' => 'old.example.test',
        'hostname_change_target' => 'next.example.test',
        'hostname_change_direction' => RouteHostnameChangeDirection::Forward,
        'hostname_change_step' => RouteHostnameChangeStep::RouterCertificate,
    ]);

    app(ConvergeRouteAction::class)->execute($route, 'next.example.test');

    expect($this->events->values)->toBe([
        'owner',
        'firewall-policy',
        'workload-verify',
        'router-caddy',
        'dns-publication',
        'cleanup',
    ]);
});

it('records each rollback boundary failure and resumes at its first unfinished step', function (
    string $failure,
    RouteHostnameChangeStep $checkpoint,
    string $errorCode,
    array $completed,
): void {
    $route = route_hostname_change_route(laravel: true);
    $this->projector->failures['workload-caddy'] = 1;

    if ($failure === 'rollback-laravel-url') {
        $this->configuration->failures = 1;
    } else {
        $this->projector->failures[$failure] = 1;
    }

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'next.example.test'))
        ->toThrow(ResourceOperationException::class);

    expect($route->refresh()->hostname_change_direction)
        ->toBe(RouteHostnameChangeDirection::Rollback)
        ->and($route->hostname_change_step)
        ->toBe($checkpoint)
        ->and($route->failed_step)
        ->toBe($failure)
        ->and($route->error_code)
        ->toBe($errorCode);

    app(ConvergeRouteAction::class)->execute($route, 'next.example.test');

    $counts = array_count_values($this->events->values);

    foreach ($completed as $event) {
        expect($counts[$event])->toBe(1);
    }

    expect($route->refresh()->hostname)
        ->toBe('next.example.test')
        ->and($route->hostname_change_target)
        ->toBeNull();
})->with([
    'DNS restoration' => [
        'rollback-dns',
        RouteHostnameChangeStep::RollbackPending,
        'route.test_rollback-dns',
        [],
    ],
    'Caddy restoration' => [
        'rollback-caddy',
        RouteHostnameChangeStep::RollbackDns,
        'route.test_rollback-caddy',
        ['rollback-dns'],
    ],
    'certificate restoration' => [
        'rollback-certificates',
        RouteHostnameChangeStep::RollbackCaddy,
        'route.test_rollback-certificates',
        ['rollback-dns', 'rollback-caddy'],
    ],
    'Laravel URL restoration' => [
        'rollback-laravel-url',
        RouteHostnameChangeStep::RollbackCertificates,
        'route.test_laravel_url',
        ['rollback-dns', 'rollback-caddy', 'rollback-certificates'],
    ],
]);

it('keeps interrupted rollback visible and resumes it before retrying the same change', function (): void {
    $route = route_hostname_change_route(laravel: true);
    $this->projector->failures = ['workload-caddy' => 1, 'rollback-caddy' => 1];

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'next.example.test'))
        ->toThrow(ResourceOperationException::class, 'Injected rollback-caddy failure.');

    expect($route->refresh()->hostname_change_direction)
        ->toBe(RouteHostnameChangeDirection::Rollback)
        ->and($route->hostname_change_step)
        ->toBe(RouteHostnameChangeStep::RollbackDns)
        ->and($route->failed_step)
        ->toBe('rollback-caddy')
        ->and($route->error_code)
        ->toBe('route.test_rollback-caddy');

    $updated = app(ConvergeRouteAction::class)->execute($route, 'next.example.test');

    expect($updated->hostname)
        ->toBe('next.example.test')
        ->and($updated->hostname_change_target)
        ->toBeNull()
        ->and(array_count_values($this->events->values)['rollback-dns'])
        ->toBe(1);
});

it('refuses a conflicting retry without changing durable rollback evidence', function (): void {
    $route = route_hostname_change_route(laravel: false);
    $this->projector->failures['workload-certificate'] = 1;

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'next.example.test'))
        ->toThrow(ResourceOperationException::class);
    $before = $route->refresh()->getAttributes();
    $eventCount = count($this->events->values);

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'other.example.test'))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('route.hostname_change_conflict');
        });

    expect($route->fresh()->getAttributes())
        ->toBe($before)
        ->and(count($this->events->values))
        ->toBe($eventCount + 1);
});

it('keeps the new hostname authoritative when cleanup fails and retries cleanup only', function (): void {
    $route = route_hostname_change_route(laravel: false);
    $this->projector->failures['cleanup'] = 1;

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'next.example.test'))
        ->toThrow(ResourceOperationException::class, 'Injected cleanup failure.');

    expect($route->refresh()->hostname)
        ->toBe('next.example.test')
        ->and($route->hostname_change_step)
        ->toBe(RouteHostnameChangeStep::DatabaseCutover)
        ->and($route->failed_step)
        ->toBe('cleanup');
    $eventCount = count($this->events->values);

    $updated = app(ConvergeRouteAction::class)->execute($route, 'next.example.test');

    expect(array_slice($this->events->values, $eventCount))
        ->toBe(['owner', 'cleanup'])
        ->and($updated->hostname_change_target)
        ->toBeNull();
});

function route_hostname_change_route(bool $laravel): Route
{
    $app = OrbitApp::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node = Node::query()->create([
        'name' => 'workload',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'tld' => 'dev.test',
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
        'user' => 'orbit',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'main',
        'environment' => 'development',
        'checkout_path' => '/srv/acme/main',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'selected_php_version' => '8.5',
        'source_is_laravel' => $laravel,
        'provisioning_step' => 'active',
        'status' => AppInstanceState::Active,
    ]);
    $route = Route::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'hostname' => 'old.example.test',
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);

    return $route->refresh()->load(['targets.appInstance.app', 'targets.appInstance.node']);
}

final class RouteHostnameChangeEvents
{
    /** @var list<string> */
    public array $values = [];
}

/** @mago-expect lint:too-many-methods The fake records every ordered projector boundary in one event stream. */
final class RouteHostnameChangeProjectorFake implements RouteHostnameProjector
{
    /** @var array<string, int> */
    public array $failures = [];

    public function __construct(
        private readonly RouteHostnameChangeEvents $events,
    ) {}

    public function prepareWorkloadCertificate(AppInstance $appInstance, Route $current, Route $candidate): void
    {
        $this->event('workload-certificate');
    }

    public function prepareWorkloadCaddy(AppInstance $appInstance, Route $current, Route $candidate): void
    {
        $this->event('workload-caddy');
    }

    public function prepareRouterCertificate(AppInstance $appInstance, Route $current, Route $candidate): void
    {
        $this->event('router-certificate');
    }

    public function prepareFirewallPolicy(AppInstance $appInstance, Route $candidate): void
    {
        $this->event('firewall-policy');
    }

    public function verifyWorkload(AppInstance $appInstance, Route $candidate): void
    {
        $this->event('workload-verify');
    }

    public function prepareRouterCaddy(AppInstance $appInstance, Route $current, Route $candidate): void
    {
        $this->event('router-caddy');
    }

    public function publishDns(Route $current, Route $candidate): void
    {
        $this->event('dns-publication');
    }

    public function cleanup(AppInstance $appInstance, Route $route): void
    {
        $this->event('cleanup');
    }

    public function rollbackDns(Route $route): void
    {
        $this->event('rollback-dns');
    }

    public function rollbackCaddy(AppInstance $appInstance, Route $route): void
    {
        $this->event('rollback-caddy');
    }

    public function rollbackCertificates(AppInstance $appInstance, Route $route): void
    {
        $this->event('rollback-certificates');
    }

    private function event(string $event): void
    {
        $this->events->values[] = $event;

        if (($this->failures[$event] ?? 0) < 1) {
            return;
        }

        $this->failures[$event]--;

        throw new ResourceOperationException(
            errorCode: "route.test_{$event}",
            message: "Injected {$event} failure.",
        );
    }
}

final class RouteHostnameChangeConfiguratorFake implements DevelopmentAppInstanceConfigurator
{
    public int $failures = 0;

    public function __construct(
        private RouteHostnameChangeEvents $events,
    ) {}

    public function inspect(AppInstance $appInstance): DevelopmentSourceProfile
    {
        return new DevelopmentSourceProfile('8.5', (bool) $appInstance->source_is_laravel);
    }

    public function configureLaravelUrl(AppInstance $appInstance, string $url): void
    {
        $this->events->values[] = "url:{$url}";

        if ($this->failures < 1) {
            return;
        }

        $this->failures--;

        throw new ResourceOperationException(
            errorCode: 'route.test_laravel_url',
            message: 'Injected Laravel URL failure.',
        );
    }
}

final readonly class RouteHostnameChangeOwnerFake implements DevelopmentProjectionOperationLock
{
    public function __construct(
        private RouteHostnameChangeEvents $events,
    ) {}

    public function run(Closure $operation): mixed
    {
        $this->events->values[] = 'owner';

        return $operation();
    }
}
