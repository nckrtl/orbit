<?php

declare(strict_types=1);

use App\Actions\Doctor\InstanceDoctorProbe;
use App\Actions\Instances\RenameInstanceAction;
use App\Actions\Routes\CreateRouteAction;
use App\Data\Instances\RenameInstanceData;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\VitePortRuntime;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\InstanceInspectionData;
use App\Domain\Doctor\InstanceStateInspector;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\PrivateRouteProjectionInspector;
use App\Domain\Doctor\PrivateRouteProjectionObservation;
use App\Domain\Instances\DevelopmentInstanceBranchInspector;
use App\Domain\Instances\DevelopmentInstanceConfigurator;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\DevelopmentSourceProfile;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Routes\RouteDomainProjector;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentPhpFpmConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Instances\NativeDevelopmentInstanceProvisioner;
use App\Models\Instance;
use App\Models\InstanceRename;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;

it('refuses another app taking over a retained per-app route rename request', function (): void {
    $project = Project::query()->create(['name' => 'Rename ownership', 'slug' => 'rename-ownership', 'repository_url' => 'https://example.test/rename.git', 'apps' => [
        ['name' => 'web', 'path' => 'apps/web', 'web_root' => 'public', 'type' => 'laravel-app'],
        ['name' => 'docs', 'path' => 'apps/docs', 'web_root' => 'public', 'type' => 'laravel-app'],
    ]]);
    $node = Node::query()->create(['name' => 'rename-owner', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.16', 'wireguard_ip' => '10.44.0.16', 'status' => 'active']);
    $node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'main', 'checkout_path' => '/srv/rename', 'source_layout' => 'checkout', 'branch' => 'main', 'status' => 'source_resolved', 'source_is_laravel' => false]);
    foreach (['web', 'docs'] as $app) {
        $instance->recordAppRuntime($app, ['laravel' => false, 'app_identity' => true, 'vite_environment_identity' => true, 'annotator_store_identity' => true]);
        $route = Route::query()->create(['project_id' => $project->id, 'app' => $app, 'node_id' => $node->id, 'domain' => "{$app}.rename.test", 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
        $route->targets()->create(['instance_id' => $instance->id, 'app' => $app, 'position' => 0]);
        $route->update(['status' => 'active']);
    }
    $instance->update(['status' => 'active']);
    $source = Mockery::mock(DevelopmentInstanceBranchInspector::class)->shouldIgnoreMissing();
    app()->instance(DevelopmentInstanceBranchInspector::class, $source);
    $projection = Mockery::mock(RouteDomainProjector::class)->shouldIgnoreMissing();
    $projection->shouldReceive('prepareWorkloadCertificate')->once()->andThrow(new ResourceOperationException('route.domain_change_failed', 'Interrupted preparation.', 502));
    app()->instance(RouteDomainProjector::class, $projection);
    $action = app(RenameInstanceAction::class);
    expect(fn () => $action->execute($instance, new RenameInstanceData(branch: 'feature', domain: 'new.rename.test', app: 'web')))->toThrow(ResourceOperationException::class, 'Interrupted preparation.');
    expect(fn () => $action->execute($instance, new RenameInstanceData(branch: 'feature', domain: 'new.rename.test', app: 'docs')))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('route.domain_change_conflict'));
    expect(InstanceRename::query()->sole()->app)->toBe('web')->and($instance->refresh()->branch)->toBe('main')
        ->and($instance->authoritativeRoute('docs')->domain)->toBe('docs.rename.test');
});

it('keeps per-app route PHP classification separate from PHP serving eligibility', function (string $type, bool $laravel, bool $serves): void {
    $project = Project::query()->create(['name' => 'PHP eligibility', 'slug' => 'php-eligibility', 'repository_url' => 'https://example.test/eligibility.git', 'apps' => [
        ['name' => 'web', 'path' => 'apps/web', 'web_root' => 'public', 'type' => 'laravel-app'],
        ['name' => 'docs', 'path' => 'apps/docs', 'web_root' => 'public', 'type' => $type],
    ]]);
    $node = Node::query()->create(['name' => 'eligibility', 'public_ssh_host' => '192.0.2.15', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.15', 'status' => 'active']);
    $node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'main', 'checkout_path' => '/srv/eligibility', 'status' => 'active', 'source_is_laravel' => true, 'selected_php_version' => '8.5']);
    foreach (['web' => true, 'docs' => $laravel] as $app => $classifiedLaravel) {
        $instance->recordAppRuntime($app, ['laravel' => $classifiedLaravel, 'php_version' => '8.5', 'app_identity' => true]);
        $route = Route::query()->create(['project_id' => $project->id, 'app' => $app, 'node_id' => $node->id, 'domain' => "{$app}.main.eligibility.test", 'generation_basis_node_id' => $node->id, 'provenance' => 'generated', 'publication' => 'private', 'status' => 'pending']);
        $route->targets()->create(['instance_id' => $instance->id, 'app' => $app, 'position' => 0]);
        $route->update(['status' => 'active']);
        $route->publishSites();
    }
    $sites = new DevelopmentSiteRepository()->forNode($node);
    $docs = $sites->firstWhere('app', 'docs');
    expect($instance->runtimeForApp('docs')['php_version'])->toBe('8.5')
        ->and($docs->phpVersion)->toBe($serves ? '8.5' : null)
        ->and($sites->firstWhere('app', 'web')->phpVersion)->toBe('8.5');
    $pools = new DevelopmentPhpFpmConfigRenderer()->render($sites, new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
    $caddy = new DevelopmentCaddyConfigRenderer()->render(collect([$docs]));
    if ($serves) {
        expect($pools)->toContain("[orbit-instance-{$instance->id}-docs]")->and($caddy)->toContain('php_fastcgi');
    } else {
        expect($pools)->not->toContain("[orbit-instance-{$instance->id}-docs]")->and($caddy)->not->toContain('php_fastcgi');
    }
    expect($pools)->toContain("[orbit-instance-{$instance->id}-web]");
})->with(['nested package' => ['laravel-package', true, false], 'non-Laravel monorepo' => ['monorepo', false, false], 'Laravel monorepo' => ['monorepo', true, true], 'Laravel app' => ['laravel-app', true, true]]);

it('creates a per-app route and isolated PHP pool for every serving app', function (): void {
    $project = Project::query()->create([
        'name' => 'Two apps', 'slug' => 'two-apps', 'repository_url' => 'https://example.test/two-apps.git', 'default_branch' => 'main',
        'apps' => [
            ['name' => 'web', 'path' => 'apps/web', 'web_root' => 'public', 'type' => 'laravel-app'],
            ['name' => 'docs', 'path' => 'apps/docs', 'web_root' => 'public', 'type' => 'laravel-app'],
        ],
    ]);
    $node = Node::query()->create(['name' => 'development', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.10', 'wireguard_ip' => '10.44.0.10', 'tld' => 'test']);
    $node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'main', 'checkout_path' => '/srv/two-apps/main', 'status' => InstanceState::SourceResolved]);
    $configuration = new class implements DevelopmentInstanceConfigurator
    {
        /** @var array<string, string> */
        public array $urls = [];

        public function inspect(Instance $instance, ?string $app = null): DevelopmentSourceProfile
        {
            return new DevelopmentSourceProfile($app === 'docs' ? '8.4' : '8.5', true);
        }

        public function configureLaravelUrl(Instance $instance, string $url, ?string $app = null): void
        {
            $this->urls[$instance->applicationDirectory($app)] = $url;
        }
    };
    $projection = new class implements DevelopmentRouteProjector
    {
        public function converge(Instance $instance, Route $route): void
        {
            $route->publishSites();
        }
    };
    $lock = new class implements DevelopmentProjectionOperationLock
    {
        public function run(Closure $operation): mixed
        {
            return $operation();
        }
    };
    $ports = Mockery::mock(VitePortRuntime::class);
    $ports->shouldReceive('selectPort')->andReturnUsing(static function (Node $node, int $preferred, array $excluded): int {
        while (in_array($preferred, $excluded, true)) {
            $preferred++;
        }

        return $preferred;
    });
    app()->instance(VitePortRuntime::class, $ports);
    $provisioner = new NativeDevelopmentInstanceProvisioner(app(CreateRouteAction::class), $configuration, $projection, $lock);
    $provisioner->reserve($instance, null);
    $active = $provisioner->complete($instance, null);

    expect($active->status)->toBe(InstanceState::Active)
        ->and($active->routes->count())->toBe(2)
        ->and($active->routes->every(static fn (Route $route): bool => $route->status === RouteStatus::Active))->toBeTrue()
        ->and($active->routes->pluck('domain', 'app')->sortKeys()->all())->toBe(['docs' => 'docs.main.two-apps.test', 'web' => 'web.main.two-apps.test'])
        ->and($configuration->urls)->toBe(['/srv/two-apps/main/apps/docs' => 'https://docs.main.two-apps.test', '/srv/two-apps/main/apps/web' => 'https://web.main.two-apps.test']);
    $sites = new DevelopmentSiteRepository()->forNode($node);
    expect($sites->count())->toBe(2)
        ->and($sites->pluck('app')->sort()->values()->all())->toBe(['docs', 'web']);
    $pools = new DevelopmentPhpFpmConfigRenderer()->render($sites, new ManagedUserAccount('orbit', 'orbit', '/home/orbit'));
    foreach (['web', 'docs'] as $app) {
        expect($pools)->toContain("[orbit-instance-{$instance->id}-{$app}]", "listen = /run/php/orbit-{$instance->id}-{$app}.sock", "chdir = /srv/two-apps/main/apps/{$app}");
    }
    $provisioner->complete($active, null);
    expect($active->routes()->count())->toBe(2)->and(count($configuration->urls))->toBe(2);
});

it('keeps exactly one app-prefixed domain for a single-app default per-app route', function (): void {
    $project = Project::query()->create(['name' => 'Catalog', 'slug' => 'catalog', 'repository_url' => 'https://example.test/catalog.git', 'root' => 'public']);
    $node = Node::query()->create(['name' => 'single', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.20', 'wireguard_ip' => '10.44.0.20', 'tld' => 'test']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/srv/catalog/default', 'status' => InstanceState::SourceResolved]);
    $first = app(CreateRouteAction::class)->ensureForInstance($instance, null);
    $retry = app(CreateRouteAction::class)->ensureForInstance($instance, null);
    expect($first->app)->toBe('web')->and($first->domain)->toBe('web.catalog.test')->and($retry->id)->toBe($first->id)->and($instance->routes()->count())->toBe(1);
});

it('names the app in per-app route Doctor pool and APP_URL findings and counts the Instance once', function (): void {
    $project = Project::query()->create(['name' => 'Doctor apps', 'slug' => 'doctor-apps', 'repository_url' => 'https://example.test/apps.git', 'apps' => [
        ['name' => 'web', 'path' => 'apps/web', 'web_root' => 'public', 'type' => 'laravel-app'],
        ['name' => 'docs', 'path' => 'apps/docs', 'web_root' => 'public', 'type' => 'laravel-app'],
    ]]);
    $node = Node::query()->create(['name' => 'doctor', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.10', 'wireguard_ip' => '10.44.0.10', 'tld' => 'test']);
    $node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'main', 'checkout_path' => '/srv/apps/main', 'status' => 'source_resolved']);
    foreach (['web', 'docs'] as $app) {
        $instance->recordAppRuntime($app, ['php_version' => '8.5', 'laravel' => true]);
        app(CreateRouteAction::class)->ensureForInstance($instance, null, $app)->update(['status' => RouteStatus::Active]);
    }
    $instance->update(['status' => InstanceState::Active, 'provisioning_step' => 'active']);
    $repository = new class implements InstanceStateInspector
    {
        public function inspect(Instance $instance): InstanceInspectionData
        {
            return new InstanceInspectionData(true, true, true, true);
        }
    };
    $projection = new class implements PrivateRouteProjectionInspector
    {
        public bool $drift = true;

        public function inspect(Instance $instance, Route $route): PrivateRouteProjectionObservation
        {
            $healthy = ! $this->drift || $route->app !== 'docs';

            return new PrivateRouteProjectionObservation(true, true, true, true, true, true, $healthy, phpFpmProjectionMatches: $healthy);
        }
    };
    $doctor = new InstanceDoctorProbe($repository, privateProjection: $projection);
    $context = new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'x86_64', true));
    $report = $doctor->inspect($context);
    expect($report->checked)->toBe(1)->and($report->issues)->toHaveCount(2);
    foreach ($report->issues as $issue) {
        expect($issue->app)->toBe('docs')->and($issue->resourceId)->toBe($instance->id)->and($issue->summary)->toContain('app [docs]');
    }
    expect(array_map(static fn ($issue): string => $issue->code, $report->issues))->toBe(['instance.laravel_url_mismatch', 'instance.php_fpm_projection_mismatch']);
    $projection->drift = false;
    expect($doctor->inspect($context)->issues)->toBe([])->and($doctor->inspect($context)->checked)->toBe(1);
});
