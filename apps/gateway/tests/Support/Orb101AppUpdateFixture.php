<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Apps\AppUpdateProjectionMutator;
use App\Domain\Apps\AppUpdateSourceMutator;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\SourceControl\RepositoryDefaultBranchResolver;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;
use Tests\TestCase;

final class Orb101AppUpdateFixture
{
    public function __construct(
        public OrbitApp $app,
        public Node $node,
        public AppInstance $defaultInstance,
        public Route $defaultRoute,
        public FakeAppUpdateSourceMutator $sources,
        public FakeAppUpdateProjectionMutator $projections,
    ) {}

    public static function bind(TestCase $test): self
    {
        $sources = new FakeAppUpdateSourceMutator;
        $projections = new FakeAppUpdateProjectionMutator;
        app()->instance(AppUpdateSourceMutator::class, $sources);
        app()->instance(AppUpdateProjectionMutator::class, $projections);
        app()->instance(
            RepositoryDefaultBranchResolver::class,
            new class implements RepositoryDefaultBranchResolver
            {
                public function resolve(string $repository): string
                {
                    return 'main';
                }

                public function verify(string $repository, string $branch): void {}
            },
        );

        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.80',
            'wireguard_ip' => '10.44.0.80',
            'tld' => 'test',
        ]);
        $app = OrbitApp::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'repository_url' => 'git@github.com:acme/site.git',
            'default_branch' => 'main',
            'root' => 'public',
        ]);
        $default = AppInstance::query()->create([
            'app_id' => $app->id,
            'node_id' => $node->id,
            'name' => 'default',
            'environment' => 'development',
            'source_layout' => AppInstanceSourceLayout::Checkout->value,
            'checkout_path' => '/srv/orbit/apps/acme/default',
            'branch' => 'main',
            'starting_commit' => str_repeat('a', 40),
            'status' => AppInstanceState::Active,
        ]);
        $route = Route::query()->create([
            'app_id' => $app->id,
            'node_id' => $node->id,
            'generation_basis_node_id' => $node->id,
            'domain' => 'acme.test',
            'provenance' => RouteProvenance::Generated,
            'publication' => RoutePublication::Private,
        ]);
        $route->targets()->create(['app_instance_id' => $default->id, 'position' => 0]);
        $route->update(['status' => RouteStatus::Active]);

        return new self($app, $node, $default, $route, $sources, $projections);
    }
}
