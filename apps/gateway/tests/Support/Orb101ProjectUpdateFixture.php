<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectUpdateProjectionMutator;
use App\Domain\Projects\ProjectUpdateSourceMutator;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\SourceControl\RepositoryDefaultBranchResolver;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Tests\TestCase;

final class Orb101ProjectUpdateFixture
{
    public function __construct(
        public Project $project,
        public Node $node,
        public Instance $defaultInstance,
        public Route $defaultRoute,
        public FakeProjectUpdateSourceMutator $sources,
        public FakeProjectUpdateProjectionMutator $projections,
    ) {}

    public static function bind(TestCase $test): self
    {
        $sources = new FakeProjectUpdateSourceMutator;
        $projections = new FakeProjectUpdateProjectionMutator;
        app()->instance(ProjectUpdateSourceMutator::class, $sources);
        app()->instance(ProjectUpdateProjectionMutator::class, $projections);
        app()->instance(
            RepositoryDefaultBranchResolver::class,
            new class implements RepositoryDefaultBranchResolver
            {
                public function resolve(string $repository, ProjectSourceAccess $source): string
                {
                    return 'main';
                }

                public function verify(string $repository, string $branch, ProjectSourceAccess $source): void {}
            },
        );

        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.80',
            'wireguard_ip' => '10.44.0.80',
            'tld' => 'test',
        ]);
        $node->roles()->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);
        $project = Project::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'repository_url' => 'git@github.com:acme/site.git',
            'default_branch' => 'main',
            'apps' => fixture_apps('public'),
        ]);
        $default = Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => 'default',
            'environment' => 'development',
            'source_layout' => InstanceSourceLayout::Checkout->value,
            'checkout_path' => '/srv/orbit/apps/acme/default',
            'branch' => 'main',
            'starting_commit' => str_repeat('a', 40),
            'status' => InstanceState::Active,
        ]);
        $route = Route::query()->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'generation_basis_node_id' => $node->id,
            'domain' => 'acme.test',
            'provenance' => RouteProvenance::Generated,
            'publication' => RoutePublication::Private,
        ]);
        $route->targets()->create(['instance_id' => $default->id, 'position' => 0]);
        $route->update(['status' => RouteStatus::Active]);

        return new self($project, $node, $default, $route, $sources, $projections);
    }
}
