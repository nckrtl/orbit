<?php

declare(strict_types=1);

use App\Domain\Clusters\ClusterState;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\InstanceState;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\SourceControl\RepositoryDefaultBranchResolver;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\SourceControl\NativeRepositoryDefaultBranchResolver;
use App\Models\Activity;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use App\Models\Task;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Projects\CreateProjectRequest;
use Orbit\Sdk\Requests\Projects\DestroyProjectRequest;
use Orbit\Sdk\Requests\Projects\ListProjectsRequest;
use Orbit\Sdk\Requests\Projects\ShowProjectRequest;

beforeEach(function (): void {
    $this->operator = Node::query()->create([
        'name' => 'operator',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $this->operator = $this->markAsGateway($this->operator);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    $this->fakeRepositoryBranches();
});

describe('app creation', function (): void {
    it('creates an app with a default name and returns it unchanged for an exact retry', function (): void {
        $requestId = (string) Str::uuid();
        $first = $this
            ->withHeader('X-Orbit-Request-Id', $requestId)
            ->postJson('/api/v1/projects', [
                'slug' => 'acme',
                'type' => 'laravel-app',
                'repository_url' => 'git@github.com:acme/site.git',
                'default_branch' => 'main',
                'apps' => fixture_apps('public', 'laravel-app'),
            ]);

        $first
            ->assertCreated()
            ->assertJsonPath('data.name', 'acme')
            ->assertJsonPath('data.slug', 'acme')
            ->assertJsonPath('data.repository_url', 'git@github.com:acme/site.git')
            ->assertJsonStructure(['meta' => ['request_id']]);
        record_fixture($first, 'projects/project-create/created', CreateProjectRequest::class, 'POST /api/v1/projects');

        $second = $this
            ->withHeader('X-Orbit-Request-Id', (string) Str::uuid())
            ->postJson('/api/v1/projects', [
                'slug' => 'acme',
                'type' => 'laravel-app',
                'repository_url' => 'git@github.com:acme/site.git',
                'default_branch' => 'main',
                'apps' => fixture_apps('public', 'laravel-app'),
            ]);

        $second
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.name', 'acme');

        expect(Project::query()->count())
            ->toBe(1)
            ->and(Activity::query()->where('request_id', $requestId)->sole()->command)
            ->toBe('project:create');

        $activity = Activity::query()->where('request_id', $requestId)->sole();

        expect($activity->subject_type)
            ->toBe(Project::class)
            ->and($activity->subject_id)
            ->toBe($first->json('data.id'));
    });

    it('rejects an incompatible repository change on an existing app', function (): void {
        $project = Project::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'repository_url' => 'git@github.com:acme/site.git',
            'default_branch' => 'main',
            'apps' => fixture_apps('public'),
        ]);

        $this
            ->postJson('/api/v1/projects', [
                'slug' => 'acme',
                'type' => 'laravel-app',
                'repository_url' => 'https://github.com/acme/other.git',
                'default_branch' => 'main',
                'apps' => fixture_apps('public', 'laravel-app'),
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'project.identity_conflict');

        expect($project->refresh()->repository_url)->toBe('git@github.com:acme/site.git');
    });

    it('returns 409 without mutation when another App owns the repository identity', function (
        string $repository,
    ): void {
        $requestId = (string) Str::uuid();
        $original = $this
            ->postJson('/api/v1/projects', [
                'name' => 'Acme',
                'slug' => 'acme',
                'type' => 'laravel-app',
                'repository_url' => 'git@github.com:acme/site.git',
                'default_branch' => 'main',
                'apps' => fixture_apps('public', 'laravel-app'),
            ])
            ->assertCreated();
        $branches = new class implements RepositoryDefaultBranchResolver
        {
            public int $calls = 0;

            public function resolve(string $repository, ProjectSourceAccess $source): string
            {
                $this->calls++;

                return 'main';
            }

            public function verify(string $repository, string $branch, ProjectSourceAccess $source): void
            {
                $this->calls++;
            }
        };
        app()->instance(RepositoryDefaultBranchResolver::class, $branches);

        $response = $this
            ->withHeader('X-Orbit-Request-Id', $requestId)
            ->postJson('/api/v1/projects', [
                'name' => 'Other',
                'slug' => 'other',
                'type' => 'laravel-app',
                'repository_url' => $repository,
                'default_branch' => 'main',
                'apps' => fixture_apps('web/public', 'laravel-app'),
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'project.repository_identity_conflict');

        expect(Project::query()->count())
            ->toBe(1)
            ->and(Project::query()
                ->sole()
                ->only([
                    'id',
                    'name',
                    'slug',
                    'repository_url',
                    'default_branch',
                    'apps',
                ]))
            ->toBe([
                'id' => $original->json('data.id'),
                'name' => 'Acme',
                'slug' => 'acme',
                'repository_url' => 'git@github.com:acme/site.git',
                'default_branch' => 'main',
                'apps' => fixture_apps('public'),
            ])
            ->and($branches->calls)
            ->toBe(0)
            ->and(Activity::query()->where('request_id', $requestId)->sole()->error_code)
            ->toBe('project.repository_identity_conflict')
            ->and($response->getContent())
            ->not->toContain($repository, 'repository_identity');
    })->with([
        'same access URL' => ['git@github.com:acme/site.git'],
        'equivalent HTTPS URL' => ['https://github.com/acme/site'],
        'equivalent HTTPS URL with trailing separator' => ['https://github.com/acme/site/'],
        'equivalent HTTPS URL with suffix and trailing separator' => ['https://github.com/acme/site.git/'],
        'equivalent SSH URL' => ['ssh://deploy@github.com/acme/site.git'],
    ]);

    it('returns 409 when repository ownership is claimed during creation', function (): void {
        $requestId = (string) Str::uuid();
        $repository = 'https://github.com/acme/site.git';
        $ownerCreated = false;

        Project::creating(static function (Project $project) use (&$ownerCreated): void {
            if ($ownerCreated || $project->slug !== 'candidate') {
                return;
            }

            $ownerCreated = true;
            Project::query()->create([
                'name' => 'Owner',
                'slug' => 'owner',
                'repository_url' => 'git@github.com:acme/site.git',
                'default_branch' => 'main',
                'apps' => fixture_apps('public'),
            ]);
        });

        $response = $this
            ->withHeader('X-Orbit-Request-Id', $requestId)
            ->postJson('/api/v1/projects', [
                'name' => 'Candidate',
                'slug' => 'candidate',
                'type' => 'laravel-app',
                'repository_url' => $repository,
                'default_branch' => 'main',
                'apps' => fixture_apps('public', 'laravel-app'),
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'project.repository_identity_conflict');

        expect(Project::query()->sole()->only(['name', 'slug', 'repository_url']))
            ->toBe([
                'name' => 'Owner',
                'slug' => 'owner',
                'repository_url' => 'git@github.com:acme/site.git',
            ])
            ->and(Activity::query()->where('request_id', $requestId)->sole()->error_code)
            ->toBe('project.repository_identity_conflict')
            ->and($response->getContent())
            ->not->toContain($repository, 'repository_identity', 'UNIQUE constraint failed');
    });
});

describe('app lifecycle', function (): void {
    it('lists, shows, and removes apps by numeric id', function (): void {
        $project = Project::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'repository_url' => 'https://github.com/acme/site.git',
            'apps' => fixture_apps(null),
        ]);

        record_fixture($this
            ->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonPath('data.0.id', $project->id), 'projects/project-list/default', ListProjectsRequest::class, 'GET /api/v1/projects');

        record_fixture($this
            ->getJson("/api/v1/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.slug', 'acme'), 'projects/project-show/default', ShowProjectRequest::class, 'GET /api/v1/projects/{project}');

        $requestId = (string) Str::uuid();
        record_fixture($this
            ->withHeader('X-Orbit-Request-Id', $requestId)
            ->deleteJson("/api/v1/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $project->id), 'projects/project-destroy/removed', DestroyProjectRequest::class, 'DELETE /api/v1/projects/{project}');

        expect(Project::query()->count())
            ->toBe(0)
            ->and(Activity::query()->where('request_id', $requestId)->sole()->command)
            ->toBe('project:destroy');
    });

    it('records a list of several apps and one of them', function (): void {
        foreach ([
            ['Acme', 'acme', 'https://github.com/acme/site.git', 'main', 'public'],
            ['Bravo docs', 'bravo-docs', 'git@github.com:bravo/docs.git', 'main', 'public'],
            ['Charlie shop', 'charlie-shop', 'git@github.com:charlie/shop.git', 'release', 'web/public'],
            ['Delta api', 'delta-api', 'https://github.com/delta/api.git', 'main', 'public'],
        ] as [$name, $slug, $repository, $branch, $root]) {
            Project::query()->create(['name' => $name, 'slug' => $slug, 'repository_url' => $repository, 'default_branch' => $branch, 'apps' => fixture_apps($root)]);
        }

        record_fixture($this->getJson('/api/v1/projects')->assertOk()->assertJsonCount(4, 'data'), 'projects/project-list/several', ListProjectsRequest::class, 'GET /api/v1/projects');
        record_fixture($this->getJson('/api/v1/projects/3')->assertOk()->assertJsonPath('data.slug', 'charlie-shop'), 'projects/project-show/charlie-shop', ShowProjectRequest::class, 'GET /api/v1/projects/{project}');
    });

    it('records a long list of apps for scrolling', function (): void {
        $names = ['Acme', 'Bravo docs', 'Charlie shop', 'Delta api', 'Echo mail', 'Foxtrot crm', 'Golf billing', 'Hotel booking',
            'India search', 'Juliet chat', 'Kilo metrics', 'Lima auth', 'Mike media', 'November news', 'Oscar orders', 'Papa payments',
            'Quebec queue', 'Romeo reports', 'Sierra store', 'Tango tickets', 'Uniform uploads', 'Victor video', 'Whiskey wiki', 'X-ray export'];
        foreach ($names as $index => $name) {
            $slug = str_replace(' ', '-', strtolower($name));
            Project::query()->create([
                'name' => $name,
                'slug' => $slug,
                'repository_url' => ($index % 3 === 0 ? 'https://github.com/example/' : 'git@github.com:example/').$slug.'.git',
                'default_branch' => $index % 4 === 0 ? 'release' : 'main',
                'apps' => fixture_apps($index % 5 === 0 ? 'web/public' : 'public'),
            ]);
        }

        record_fixture($this->getJson('/api/v1/projects')->assertOk()->assertJsonCount(24, 'data'), 'projects/project-list/many', ListProjectsRequest::class, 'GET /api/v1/projects');
    });

    it('does not remove a Project that still owns Instances', function (): void {
        $cluster = Cluster::query()->create(['name' => 'development', 'state' => ClusterState::Active]);
        $node = Node::query()->create([
            'cluster_id' => $cluster->id,
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
        ]);
        $project = Project::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'repository_url' => 'https://github.com/acme/site.git',
            'default_branch' => 'main',
            'apps' => fixture_apps('public'),
        ]);
        Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => 'dev',
            'checkout_path' => '/srv/orbit/apps/acme/dev',
            'branch' => 'dev',
            'starting_commit' => str_repeat('a', 40),
            'status' => InstanceState::Active,
        ]);

        $this
            ->deleteJson("/api/v1/projects/{$project->id}")
            ->assertConflict()
            ->assertJsonPath('error.code', 'project.has_instances');

        expect($project->fresh())
            ->not
            ->toBeNull()
            ->and(Instance::query()->count())
            ->toBe(1);
    });

    it('refuses removal when the Project has task groups', function (): void {
        $project = Project::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'repository_url' => 'https://github.com/acme/site.git',
            'default_branch' => 'main',
            'apps' => fixture_apps('public'),
        ]);
        $parent = Task::topLevel()->create([
            'project_id' => $project->id,
            'title' => 'Ship the feature',
            'brief' => 'Implement and verify the feature.',
        ]);

        $this
            ->deleteJson("/api/v1/projects/{$project->id}")
            ->assertConflict()
            ->assertJsonPath('error.code', 'project.has_task_groups');

        expect($project->fresh())
            ->not
            ->toBeNull()
            ->and($parent->fresh())
            ->not
            ->toBeNull();
    });

    it('refuses removal for owned Instances before owned Routes whatever the Route status', function (): void {
        $cluster = Cluster::query()->create(['name' => 'development', 'state' => ClusterState::Active]);
        $node = Node::query()->create([
            'cluster_id' => $cluster->id,
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
        ]);
        $project = Project::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'repository_url' => 'https://github.com/acme/site.git',
            'default_branch' => 'main',
            'apps' => fixture_apps('public'),
        ]);
        $instance = Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => 'dev',
            'checkout_path' => '/srv/orbit/apps/acme/dev',
            'branch' => 'dev',
            'starting_commit' => str_repeat('a', 40),
            'status' => InstanceState::Active,
        ]);
        $route = Route::query()->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'domain' => 'acme.dev.orbit',
            'provenance' => RouteProvenance::Explicit,
            'publication' => RoutePublication::Private,
        ]);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        $route->update(['status' => RouteStatus::Active]);

        $this
            ->deleteJson("/api/v1/projects/{$project->id}")
            ->assertConflict()
            ->assertJsonPath('error.code', 'project.has_instances');

        $routed = Project::query()->create([
            'name' => 'Routed',
            'slug' => 'routed',
            'repository_url' => 'https://github.com/acme/routed.git',
            'default_branch' => 'main',
            'apps' => fixture_apps('public'),
        ]);
        Route::query()->create([
            'project_id' => $routed->id,
            'node_id' => $node->id,
            'domain' => 'routed.dev.orbit',
            'provenance' => RouteProvenance::Explicit,
            'publication' => RoutePublication::Private,
        ]);

        $this
            ->deleteJson("/api/v1/projects/{$routed->id}")
            ->assertConflict()
            ->assertJsonPath('error.code', 'project.has_routes');

        expect($project->fresh())
            ->not
            ->toBeNull()
            ->and($routed->fresh())
            ->not
            ->toBeNull()
            ->and($route->refresh()->status)
            ->toBe(RouteStatus::Active)
            ->and(Route::query()->count())
            ->toBe(2);
    });
});

describe('app validation', function (): void {
    it('validates repository and slug input', function (array $payload, string $field): void {
        $this
            ->postJson('/api/v1/projects', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonPath("error.details.{$field}.0", fn (string $message): bool => $message !== '');
    })->with([
        'invalid slug' => [
            [
                'slug' => '../acme',
                'repository_url' => 'https://github.com/acme/site.git',
            ],
            'slug',
        ],
        'repository contains whitespace' => [
            [
                'slug' => 'acme',
                'repository_url' => "https://github.com/acme/site.git\n--upload-pack=bad",
            ],
            'repository_url',
        ],
        'repository contains URL credentials' => [
            [
                'slug' => 'acme',
                'repository_url' => 'https://alice:super-secret@example.com/acme/site.git',
            ],
            'repository_url',
        ],
    ]);

    it('keeps repository credentials out of validation errors and activity diagnostics', function (): void {
        $requestId = (string) Str::uuid();
        $credential = (string) Str::uuid();
        $repository = "https://alice:{$credential}@example.test/acme/site.git";

        $response = $this
            ->withHeader('X-Orbit-Request-Id', $requestId)
            ->postJson('/api/v1/projects', [
                'slug' => 'acme',
                'type' => 'laravel-app',
                'repository_url' => $repository,
                'apps' => fixture_apps('public', 'laravel-app'),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');
        $activity = Activity::query()->where('request_id', $requestId)->sole();

        expect($response->getContent())
            ->not->toContain($credential, $repository)->and(print_r($activity->toArray(), return: true))
            ->not->toContain($credential, $repository);
    });

    it('keeps raw remote output out of repository errors and activity diagnostics', function (): void {
        $requestId = (string) Str::uuid();
        $diagnostic = (string) Str::uuid();
        $processes = new class($diagnostic) implements ProcessRunner
        {
            public function __construct(
                private readonly string $diagnostic,
            ) {}

            public function run(ProcessInvocation $invocation): CommandResult
            {
                return new CommandResult(
                    exitCode: 128,
                    stdout: "remote stdout {$this->diagnostic}",
                    stderr: "remote stderr {$this->diagnostic}",
                    durationMs: 1,
                    truncated: false,
                );
            }
        };
        app()->instance(
            RepositoryDefaultBranchResolver::class,
            new NativeRepositoryDefaultBranchResolver($processes, app(RepositoryReadAccess::class)),
        );

        $response = $this
            ->withHeader('X-Orbit-Request-Id', $requestId)
            ->postJson('/api/v1/projects', [
                'slug' => 'acme',
                'type' => 'laravel-app',
                'repository_url' => 'https://example.test/acme/site.git',
                'apps' => fixture_apps('public', 'laravel-app'),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'project.default_branch_unavailable');
        $activity = Activity::query()->where('request_id', $requestId)->sole();

        expect($response->getContent())
            ->not->toContain($diagnostic)->and(print_r($activity->toArray(), return: true))
            ->not->toContain($diagnostic);
    });
});

describe('app list access', function (): void {
    it('shows all rows to fleet authority, accessible apps to a direct consumer, and denies a no-edge consumer', function (): void {
        $gateway = $this->operator;
        $accessibleNode = Node::query()->create([
            'name' => 'accessible-node',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.20',
            'wireguard_ip' => '10.44.0.20',
        ]);
        $inaccessibleNode = Node::query()->create([
            'name' => 'inaccessible-node',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.21',
            'wireguard_ip' => '10.44.0.21',
        ]);
        $directConsumer = Node::query()->create([
            'name' => 'direct-consumer',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.22',
            'wireguard_ip' => '10.44.0.22',
        ]);
        $gatewayAccessConsumer = Node::query()->create([
            'name' => 'gateway-access-consumer',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.23',
            'wireguard_ip' => '10.44.0.23',
        ]);
        $noEdgeConsumer = Node::query()->create([
            'name' => 'no-edge-consumer',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.24',
            'wireguard_ip' => '10.44.0.24',
        ]);
        $directConsumer->accessibleNodes()->attach($accessibleNode);
        $gatewayAccessConsumer->accessibleNodes()->attach($gateway);
        $unplaced = Project::query()->create([
            'name' => 'Unplaced',
            'slug' => 'unplaced',
            'repository_url' => 'https://example.test/unplaced.git',
            'apps' => fixture_apps(null),
        ]);
        $accessible = Project::query()->create([
            'name' => 'Accessible',
            'slug' => 'accessible',
            'repository_url' => 'https://example.test/accessible.git',
            'apps' => fixture_apps(null),
        ]);
        $inaccessible = Project::query()->create([
            'name' => 'Inaccessible',
            'slug' => 'inaccessible',
            'repository_url' => 'https://example.test/inaccessible.git',
            'apps' => fixture_apps(null),
        ]);
        $multiplyPlaced = Project::query()->create([
            'name' => 'Multiply placed',
            'slug' => 'multiply-placed',
            'repository_url' => 'https://example.test/multiply-placed.git',
            'apps' => fixture_apps(null),
        ]);
        Instance::query()->create([
            'project_id' => $accessible->id,
            'node_id' => $accessibleNode->id,
            'name' => 'main',
            'checkout_path' => '/srv/accessible',
            'status' => InstanceState::Active,
        ]);
        Instance::query()->create([
            'project_id' => $inaccessible->id,
            'node_id' => $inaccessibleNode->id,
            'name' => 'main',
            'checkout_path' => '/srv/inaccessible',
            'status' => InstanceState::Active,
        ]);
        Instance::query()->create([
            'project_id' => $multiplyPlaced->id,
            'node_id' => $accessibleNode->id,
            'name' => 'first',
            'checkout_path' => '/srv/multiply-placed/first',
            'status' => InstanceState::Active,
        ]);
        Instance::query()->create([
            'project_id' => $multiplyPlaced->id,
            'node_id' => $accessibleNode->id,
            'name' => 'second',
            'checkout_path' => '/srv/multiply-placed/second',
            'status' => InstanceState::Active,
        ]);

        $this
            ->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
            ->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$accessible->id, $inaccessible->id, $multiplyPlaced->id, $unplaced->id]);

        $this
            ->withServerVariables(['REMOTE_ADDR' => $gatewayAccessConsumer->wireguard_ip])
            ->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$accessible->id, $inaccessible->id, $multiplyPlaced->id, $unplaced->id]);

        $this
            ->withServerVariables(['REMOTE_ADDR' => $directConsumer->wireguard_ip])
            ->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$accessible->id, $multiplyPlaced->id]);

        $this
            ->withServerVariables(['REMOTE_ADDR' => $noEdgeConsumer->wireguard_ip])
            ->getJson('/api/v1/projects')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'node_access.required');
    });

    it('uses only Instance placement for a direct consumer', function (): void {
        $accessibleNode = Node::query()->create([
            'name' => 'accessible-node',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.20',
            'wireguard_ip' => '10.44.0.20',
        ]);
        $inaccessibleNode = Node::query()->create([
            'name' => 'inaccessible-node',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.21',
            'wireguard_ip' => '10.44.0.21',
        ]);
        $consumer = Node::query()->create([
            'name' => 'direct-consumer',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.22',
            'wireguard_ip' => '10.44.0.22',
        ]);
        $consumer->accessibleNodes()->attach($accessibleNode);
        Project::query()->create([
            'name' => 'Unplaced',
            'slug' => 'unplaced-direct',
            'repository_url' => 'https://example.test/unplaced-direct.git',
            'apps' => fixture_apps(null),
        ]);
        $mixedHidden = Project::query()->create([
            'name' => 'Mixed hidden',
            'slug' => 'mixed-hidden',
            'repository_url' => 'https://example.test/mixed-hidden.git',
            'apps' => fixture_apps(null),
        ]);
        $mixedVisible = Project::query()->create([
            'name' => 'Mixed visible',
            'slug' => 'mixed-visible',
            'repository_url' => 'https://example.test/mixed-visible.git',
            'apps' => fixture_apps(null),
        ]);
        Instance::query()->create([
            'project_id' => $mixedHidden->id,
            'node_id' => $inaccessibleNode->id,
            'name' => 'current',
            'checkout_path' => '/srv/current/mixed-hidden',
            'status' => InstanceState::Active,
        ]);
        Instance::query()->create([
            'project_id' => $mixedVisible->id,
            'node_id' => $accessibleNode->id,
            'name' => 'current',
            'checkout_path' => '/srv/current/mixed-visible',
            'status' => InstanceState::Active,
        ]);

        $this
            ->withServerVariables(['REMOTE_ADDR' => $consumer->wireguard_ip])
            ->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$mixedVisible->id]);
    });
});
