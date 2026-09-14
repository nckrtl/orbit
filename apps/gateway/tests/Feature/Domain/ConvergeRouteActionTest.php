<?php

declare(strict_types=1);

use App\Actions\Routes\ConvergeRouteAction;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DevelopmentAppInstanceConfigurator;
use App\Domain\AppInstances\DevelopmentSourceProfile;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentResult;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentRouteDomain;
use App\Domain\AppInstances\Environment\AppInstanceRouteEnvironmentSynchronizer;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteDomainProjector;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->events = new RouteDomainChangeEvents;
    $this->projector = new RouteDomainChangeProjectorFake($this->events);
    $this->configuration = new RouteDomainChangeConfiguratorFake($this->events);
    $this->environment = new RouteDomainChangeEnvironmentFake($this->events);
    app()->instance(RouteDomainProjector::class, $this->projector);
    app()->instance(DevelopmentAppInstanceConfigurator::class, $this->configuration);
    app()->instance(AppInstanceRouteEnvironmentSynchronizer::class, $this->environment);
    app()->instance(
        DevelopmentProjectionOperationLock::class,
        new RouteDomainChangeOwnerFake($this->events),
    );
});

it('prepares every projection and Laravel URL before DNS then cuts over and cleans up', function (): void {
    $route = route_domain_change_route(laravel: true);

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
            'url:https://next.example.test',
            'workload-verify',
        ])
        ->and($updated->domain)
        ->toBe('next.example.test')
        ->and($updated->status)
        ->toBe(RouteStatus::Active)
        ->and($updated->replaces_route_id)
        ->toBeNull()
        ->and($updated->replaced_by_route_id)
        ->toBeNull()
        ->and($updated->replacement_step)
        ->toBeNull()
        ->and($updated->id)
        ->not->toBe($route->id)
        ->and(Route::query()->find($route->id))
        ->toBeNull();
});

it('does not configure Laravel for a source profile classified as non-Laravel', function (): void {
    $route = route_domain_change_route(laravel: false);

    app(ConvergeRouteAction::class)->execute($route, 'next.example.test');

    expect($this->events->values)->not->toContain('url:https://next.example.test', 'url:https://old.example.test');
});

it('synchronizes the production candidate environment before DNS and preserves the Route target', function (): void {
    $route = route_domain_change_route(laravel: true, environment: 'production');
    $targetId = $route->targets->sole()->app_instance_id;

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
            'environment:candidate',
            'dns-publication',
            'cleanup',
            'environment:candidate',
            'workload-verify',
        ])
        ->and($updated->domain)
        ->toBe('next.example.test')
        ->and($updated->targets)
        ->toHaveCount(1)
        ->and($updated->targets->sole()->app_instance_id)
        ->toBe($targetId);
});

it('replaces a shared production Route while preserving the ordered target pool', function (): void {
    $route = route_domain_change_shared_production_route();
    $expected = $route->targets()->orderBy('position')->pluck('app_instance_id')->all();
    expect($expected)->toHaveCount(2);

    $updated = app(ConvergeRouteAction::class)->execute($route, 'next.example.test');

    expect($updated->domain)
        ->toBe('next.example.test')
        ->and($updated->targets()->orderBy('position')->pluck('app_instance_id')->all())
        ->toBe($expected)
        ->and($updated->id)
        ->not->toBe($route->id)
        ->and(Route::query()->find($route->id))
        ->toBeNull()
        ->and(array_count_values($this->events->values)['workload-certificate'])
        ->toBe(2);
});

it('leaves the old Route authoritative and removes the replacement after a pre-cutover failure', function (
    string $failure,
): void {
    $route = route_domain_change_route(laravel: true);
    $this->projector->failures[$failure] = 1;

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'next.example.test'))
        ->toThrow(ResourceOperationException::class, "Injected {$failure} failure.");

    expect($route->refresh()->domain)
        ->toBe('old.example.test')
        ->and($route->status)
        ->toBe(RouteStatus::Active)
        ->and($route->replaced_by_route_id)
        ->toBeNull()
        ->and(Route::query()->where('domain', 'next.example.test')->exists())
        ->toBeFalse()
        ->and($this->events->values)
        ->toContain('rollback-certificates', 'rollback-caddy', 'rollback-dns');
})->with([
    'workload certificate' => ['workload-certificate', 'workload-certificate'],
    'workload Caddy' => ['workload-caddy', 'workload-caddy'],
    'Router certificate' => ['router-certificate', 'router-certificate'],
    'firewall policy' => ['firewall-policy', 'firewall-policy'],
    'workload verification' => ['workload-verify', 'workload-verify'],
    'Router Caddy' => ['router-caddy', 'router-caddy'],
    'DNS publication' => ['dns-publication', 'dns-publication'],
]);

it('records Laravel URL failure, restores the old URL, and removes the replacement', function (): void {
    $route = route_domain_change_route(laravel: true);
    $this->configuration->failures = 1;

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'next.example.test'))
        ->toThrow(ResourceOperationException::class, 'Injected Laravel URL failure.');

    expect($route->refresh()->domain)
        ->toBe('old.example.test')
        ->and($route->replaced_by_route_id)
        ->toBeNull()
        ->and($this->events->values)
        ->toContain('url:https://old.example.test')
        ->and(Route::query()->where('domain', 'next.example.test')->exists())
        ->toBeFalse();
});

it('retains an inspectable failed replacement when pre-cutover cleanup is incomplete', function (): void {
    $route = route_domain_change_route(laravel: false);
    $this->projector->failures = [
        'workload-caddy' => 1,
        'rollback-caddy' => 1,
    ];

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'next.example.test'))
        ->toThrow(ResourceOperationException::class, 'Injected workload-caddy failure.');

    $replacement = Route::query()->where('domain', 'next.example.test')->sole();

    expect($route->refresh()->domain)
        ->toBe('old.example.test')
        ->and($route->status)
        ->toBe(RouteStatus::Active)
        ->and($route->replaced_by_route_id)
        ->toBe($replacement->id)
        ->and($replacement->status)
        ->toBe(RouteStatus::Failed)
        ->and($replacement->replaces_route_id)
        ->toBe($route->id)
        ->and($replacement->failed_step)
        ->toBe('workload-caddy');
});

it('recovers only the identical failed replacement and refuses a conflicting domain', function (): void {
    $route = route_domain_change_route(laravel: false);
    $this->projector->failures = [
        'workload-certificate' => 1,
        'rollback-certificates' => 1,
    ];

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'next.example.test'))
        ->toThrow(ResourceOperationException::class);

    $replacement = Route::query()->where('domain', 'next.example.test')->sole();
    $before = $replacement->fresh()->getAttributes();
    $oldBefore = $route->refresh()->getAttributes();

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'other.example.test'))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('route.domain_change_conflict');
        });

    expect($replacement->fresh()->getAttributes())
        ->toBe($before)
        ->and($route->fresh()->getAttributes())
        ->toBe($oldBefore);

    $this->projector->failures = [];
    $updated = app(ConvergeRouteAction::class)->execute($route, 'next.example.test');

    expect($updated->domain)
        ->toBe('next.example.test')
        ->and($updated->status)
        ->toBe(RouteStatus::Active)
        ->and(Route::query()->find($route->id))
        ->toBeNull();
});

it('records database cutover failure without making the replacement authoritative', function (): void {
    $route = route_domain_change_route(laravel: false);
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER route_domain_change_cutover_failure
        BEFORE UPDATE OF status ON routes
        WHEN NEW.status = 'activating' AND NEW.domain = 'next.example.test'
        BEGIN
            SELECT RAISE(ABORT, 'Injected database cutover failure.');
        END
        SQL);

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'next.example.test'))
        ->toThrow(QueryException::class);

    expect($route->refresh()->domain)
        ->toBe('old.example.test')
        ->and($route->status)
        ->toBe(RouteStatus::Active)
        ->and($this->events->values)
        ->toContain('rollback-dns', 'rollback-caddy', 'rollback-certificates');
});

it('exposes activating and retiring Routes through one cutover transition', function (): void {
    $route = route_domain_change_route(laravel: false);
    $this->projector->failures['cleanup'] = 1;

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'next.example.test'))
        ->toThrow(ResourceOperationException::class, 'Injected cleanup failure.');

    $replacement = Route::query()->where('domain', 'next.example.test')->sole();

    expect($replacement->status)
        ->toBe(RouteStatus::Activating)
        ->and($replacement->replacement_step)
        ->toBe(RouteReplacementStep::DatabaseCutover)
        ->and($replacement->failed_step)
        ->toBe('cleanup')
        ->and($route->refresh()->status)
        ->toBe(RouteStatus::Retiring)
        ->and($route->domain)
        ->toBe('old.example.test')
        ->and($replacement->replaces_route_id)
        ->toBe($route->id)
        ->and($route->replaced_by_route_id)
        ->toBe($replacement->id);
});

it('keeps the replacement authoritative when cleanup fails and retries cleanup only', function (): void {
    $route = route_domain_change_route(laravel: false);
    $this->projector->failures['cleanup'] = 1;

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'next.example.test'))
        ->toThrow(ResourceOperationException::class, 'Injected cleanup failure.');

    $replacement = Route::query()->where('domain', 'next.example.test')->sole();
    $eventCount = count($this->events->values);

    $updated = app(ConvergeRouteAction::class)->execute($route, 'next.example.test');

    expect($updated->id)
        ->toBe($replacement->id)
        ->and($updated->domain)
        ->toBe('next.example.test')
        ->and($updated->status)
        ->toBe(RouteStatus::Active)
        ->and($updated->replaces_route_id)
        ->toBeNull()
        ->and(Route::query()->find($route->id))
        ->toBeNull()
        ->and(array_slice($this->events->values, $eventCount))
        ->toBe(['owner', 'cleanup', 'workload-verify']);
});

it('records cleanup failure when deleting the retiring Route fails', function (): void {
    $route = route_domain_change_route(laravel: false);
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER route_domain_change_cleanup_failure
        BEFORE DELETE ON routes
        WHEN OLD.status = 'retiring'
        BEGIN
            SELECT RAISE(ABORT, 'Injected cleanup persistence failure.');
        END
        SQL);

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'next.example.test'))
        ->toThrow(QueryException::class);

    $replacement = Route::query()->where('domain', 'next.example.test')->sole();

    expect($replacement->status)
        ->toBe(RouteStatus::Activating)
        ->and($replacement->domain)
        ->toBe('next.example.test')
        ->and($replacement->failed_step)
        ->toBe('cleanup')
        ->and($route->refresh()->status)
        ->toBe(RouteStatus::Retiring);
});

function route_domain_change_route(bool $laravel, string $environment = 'development'): Route
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
        'environment' => $environment,
        'checkout_path' => '/srv/acme/main',
        'production_home' => $environment === 'production' ? '/srv/acme/main' : null,
        'production_user' => $environment === 'production' ? 'orbit-acme' : null,
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
        'domain' => 'old.example.test',
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
    ]);
    $route->targets()->create(['app_instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);

    return $route->refresh()->load(['targets.appInstance.app', 'targets.appInstance.node']);
}

function route_domain_change_shared_production_route(): Route
{
    $app = OrbitApp::query()->create([
        'name' => 'Shared',
        'slug' => 'shared',
        'repository_url' => 'https://example.test/shared.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $cluster = Cluster::query()->create(['name' => 'shared', 'state' => 'active']);
    $instances = collect(['one', 'two'])->map(function (string $name) use ($app, $cluster): AppInstance {
        $suffix = $name === 'one' ? '71' : '72';
        $node = Node::query()->create([
            'name' => "shared-{$name}",
            'cluster_id' => $cluster->id,
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'public_ssh_host' => "192.0.2.{$suffix}",
            'wireguard_ip' => "10.44.0.{$suffix}",
            'user' => 'orbit',
        ]);
        $node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);

        return AppInstance::query()->create([
            'app_id' => $app->id,
            'node_id' => $node->id,
            'name' => $name,
            'environment' => 'production',
            'checkout_path' => "/var/www/shared/{$name}",
            'production_home' => "/var/www/shared/{$name}",
            'production_user' => 'orbit-shared',
            'branch' => 'main',
            'starting_commit' => str_repeat('a', 40),
            'selected_php_version' => '8.5',
            'source_is_laravel' => false,
            'provisioning_step' => 'active',
            'status' => AppInstanceState::SourceResolved,
        ]);
    });
    $route = Route::query()->create([
        'app_id' => $app->id,
        'cluster_id' => $cluster->id,
        'domain' => 'old.example.test',
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
    ]);
    $route->targets()->create(['app_instance_id' => $instances[0]->id, 'position' => 0]);
    $route->targets()->create(['app_instance_id' => $instances[1]->id, 'position' => 1]);
    $route->update(['status' => 'active']);
    $instances[0]->update(['status' => AppInstanceState::Active]);
    $instances[1]->update(['status' => AppInstanceState::Active]);

    return $route->refresh()->load(['targets.appInstance.app', 'targets.appInstance.node', 'cluster']);
}

final class RouteDomainChangeEnvironmentFake implements AppInstanceRouteEnvironmentSynchronizer
{
    /** @var array<string, int> */
    public array $failures = [];

    public function __construct(
        private readonly RouteDomainChangeEvents $events,
    ) {}

    public function synchronizeRouteDomain(
        AppInstance $instance,
        AppInstanceEnvironmentRouteDomain $domain,
    ): AppInstanceEnvironmentResult {
        $this->events->values[] = "environment:{$domain->value}";

        if (($this->failures[$domain->value] ?? 0) > 0) {
            $this->failures[$domain->value]--;

            throw new ResourceOperationException(
                errorCode: "route.test_environment_{$domain->value}",
                message: "Injected environment {$domain->value} failure.",
            );
        }

        return new AppInstanceEnvironmentResult($instance->id, 'sync', true, 1);
    }
}

final class RouteDomainChangeEvents
{
    /** @var list<string> */
    public array $values = [];
}

final class RouteDomainChangeProjectorFake implements RouteDomainProjector
{
    /** @var array<string, int> */
    public array $failures = [];

    public function __construct(
        private readonly RouteDomainChangeEvents $events,
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

final class RouteDomainChangeConfiguratorFake implements DevelopmentAppInstanceConfigurator
{
    public int $failures = 0;

    public function __construct(
        private RouteDomainChangeEvents $events,
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

final readonly class RouteDomainChangeOwnerFake implements DevelopmentProjectionOperationLock
{
    public function __construct(
        private RouteDomainChangeEvents $events,
    ) {}

    public function run(Closure $operation): mixed
    {
        $this->events->values[] = 'owner';

        return $operation();
    }
}
