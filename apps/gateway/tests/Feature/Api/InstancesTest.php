<?php

declare(strict_types=1);

use App\Actions\Instances\CreateInstanceAction;
use App\Actions\Routes\ConvergeRouteAction;
use App\Actions\Routes\RemoveRouteAction;
use App\Actions\Routes\SetRouteTargetAction;
use App\Actions\Routes\UpdateRouteAction;
use App\Data\Instances\CreateInstanceData;
use App\Data\Instances\InstanceData;
use App\Data\Routes\UpdateRouteData;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Clusters\ClusterState;
use App\Domain\Instances\DevelopmentInstanceConfigurator;
use App\Domain\Instances\DevelopmentInstanceProvisioner;
use App\Domain\Instances\DevelopmentInstanceSourceLifecycle;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\DevelopmentSourceProfile;
use App\Domain\Instances\DevelopmentSourceResolution;
use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentReader;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\Environment\InstanceEnvironmentWriteResult;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Instances\InstanceDestinationGuard;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionInstanceSourceLifecycle;
use App\Domain\Instances\ProductionReleaseLayout;
use App\Domain\Instances\ProductionRouteProjector;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceFinalizer;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceRemoval;
use App\Domain\Instances\Removal\InstanceRemovalProjector;
use App\Domain\Instances\Removal\InstanceSourceInventory;
use App\Domain\Instances\Removal\InstanceSourceRevalidationExpectation;
use App\Domain\Instances\Removal\InstanceSourceRevalidationState;
use App\Domain\Instances\Transfer\InstanceTransferStatus;
use App\Domain\Instances\Transfer\InstanceTransferStep;
use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Metrics\ExporterDegradationRepository;
use App\Domain\Metrics\MetricsCadvisorLifecycle;
use App\Domain\Metrics\MetricsExporterLifecycle;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Metrics\MetricsReconcileDegradationRepository;
use App\Domain\Metrics\MetricsRuntimeLifecycle;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Projects\ProjectLifecycleRunner;
use App\Domain\Projects\ProjectType;
use App\Domain\Routes\RouteDomainProjector;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Instances\NativeInstanceRemovalProjector;
use App\Infrastructure\Metrics\NativeMetricsFleetReconciler;
use App\Infrastructure\Metrics\NativeServiceMetricsLifecycle;
use App\Infrastructure\Metrics\ServiceMetricsNode;
use App\Infrastructure\Metrics\ServiceMetricsProjection;
use App\Infrastructure\Metrics\ServiceMetricsRuntime;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Activity;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\InstanceRemoval;
use App\Models\InstanceRemovalMember;
use App\Models\InstanceRename;
use App\Models\InstanceTransfer;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use App\Models\ProjectUpdate;
use App\Models\Route;
use App\Models\RouteTarget;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Instances\CreateInstanceRequest;
use Orbit\Sdk\Requests\Instances\ListInstancesRequest;
use Orbit\Sdk\Requests\Instances\ShowInstanceRequest;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\LifecycleSshExecutor;
use Tests\TestCase;

beforeEach(function (): void {
    $this->destination = new class implements InstanceDestinationGuard
    {
        public bool $occupied = false;

        public function assertUnoccupied(Node $node, StoragePath $destination): void
        {
            if ($this->occupied) {
                throw new ResourceOperationException(
                    'instance.migration_conflict',
                    'Instance destination is occupied by unmanaged data.',
                    409,
                );
            }
        }
    };
    app()->instance(InstanceDestinationGuard::class, $this->destination);
    app()->instance(RoleBaselineConverger::class, new class implements RoleBaselineConverger
    {
        public function converge(Node $node, NodeRole $assignment): void {}

        public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}

        public function removeUnreachable(Node $node, NodeRole $assignment): void {}
    });
    $this->configuration = new class implements DevelopmentInstanceConfigurator
    {
        public int $inspections = 0;

        public int $configurations = 0;

        /** @var list<string> */
        public array $urls = [];

        public ?string $phpVersion = '8.5';

        public bool $laravel = false;

        public function inspect(Instance $instance, ?string $app = null): DevelopmentSourceProfile
        {
            $this->inspections++;

            return new DevelopmentSourceProfile($this->phpVersion, $this->laravel);
        }

        public function configureLaravelUrl(Instance $instance, string $url, ?string $app = null): void
        {
            $this->configurations++;
            $this->urls[] = $url;
        }
    };
    app()->instance(DevelopmentInstanceConfigurator::class, $this->configuration);
    $this->projection = new class implements DevelopmentRouteProjector
    {
        public int $convergences = 0;

        public function converge(Instance $instance, Route $route): void
        {
            $this->convergences++;
        }
    };
    app()->instance(DevelopmentRouteProjector::class, $this->projection);
    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    });
    $this->source = new class implements DevelopmentInstanceSourceLifecycle
    {
        /** @var list<string> */
        public array $calls = [];

        /** @var list<bool> */
        public array $prepareExisting = [];

        public ?string $fail = null;

        public string $failureCode = 'instance.source_interrupted';

        public DevelopmentSourceResolution $resolution;

        public function __construct()
        {
            $this->resolution = new DevelopmentSourceResolution('dev', str_repeat('a', 40));
        }

        public function prepare(Instance $instance, bool $allowExisting): void
        {
            $this->prepareExisting[] = $allowExisting;
            $this->record('prepare', $instance);
        }

        public function inspectPrepared(Instance $instance): void
        {
            $this->record('inspect-prepared', $instance);
        }

        public function resolve(Instance $instance): DevelopmentSourceResolution
        {
            $this->record('resolve', $instance);

            return $this->resolution;
        }

        public function inspectResolved(Instance $instance): DevelopmentSourceResolution
        {
            $this->record('inspect-resolved', $instance);

            return $this->resolution;
        }

        private function record(string $operation, Instance $instance): void
        {
            $this->calls[] = "{$operation}:{$instance->status->value}";

            if ($this->fail === $operation) {
                throw new ResourceOperationException($this->failureCode, 'Source operation interrupted.');
            }
        }
    };
    app()->instance(DevelopmentInstanceSourceLifecycle::class, $this->source);
    $this->productionSource = new class implements ProductionInstanceSourceLifecycle
    {
        /** @var list<string> */
        public array $calls = [];

        public bool $laravel = false;

        public ?string $phpVersion = '8.5';

        /** @var list<string> */
        public array $inspected = [];

        public function prepareUser(Instance $instance): void
        {
            $this->calls[] = 'user';
        }

        public function prepareSource(Instance $instance, bool $allowExisting): void
        {
            $this->calls[] = 'source:'.($allowExisting ? 'existing' : 'new');
        }

        public function resolve(Instance $instance): DevelopmentSourceResolution
        {
            $this->calls[] = 'resolve';

            return new DevelopmentSourceResolution(
                $instance->branch_override ?? $instance->project->default_branch,
                str_repeat('b', 40),
            );
        }

        public function inspectProfile(Instance $instance): DevelopmentSourceProfile
        {
            $this->calls[] = 'profile';
            $this->inspected[] = $instance->checkout_path;

            return new DevelopmentSourceProfile($this->phpVersion, $this->laravel);
        }

        public function prepareCaddyAccess(Instance $instance): void
        {
            $this->calls[] = 'access';
        }
    };
    app()->instance(ProductionInstanceSourceLifecycle::class, $this->productionSource);
    app()->instance(ProductionReleaseLayout::class, new class implements ProductionReleaseLayout
    {
        public function validateCurrent(Instance $instance): void {}

        public function clearCurrent(Instance $instance): void {}
    });
    $this->productionProjection = new class implements ProductionRouteProjector
    {
        /** @var list<string> */
        public array $calls = [];

        public function prepareRuntime(Instance $instance, Route $route): void
        {
            $this->calls[] = 'runtime';
        }

        public function prepareCertificate(Instance $instance, Route $route): void
        {
            $this->calls[] = 'certificate';
        }

        public function prepareFirewall(Instance $instance): void
        {
            $this->calls[] = 'firewall';
        }

        public function publish(Instance $instance, Route $route): void
        {
            $this->calls[] = 'route';
        }
    };
    app()->instance(ProductionRouteProjector::class, $this->productionProjection);
    $this->removalSource = new class implements DevelopmentInstanceSourceFinalizer, DevelopmentInstanceSourceRemoval
    {
        /** @var list<string> */
        public array $calls = [];

        public ?string $fail = null;

        /** @var array<int, list<string>> */
        public array $linkedPaths = [];

        /** @var list<string>|null */
        public ?array $livePaths = null;

        /** @var array<int, InstanceSourceRevalidationState> */
        public array $states = [];

        public ?int $failPrepareFor = null;

        /** @var array<int, string> */
        public array $inspectionFailures = [];

        public function inspect(
            Instance $instance,
            bool $force,
            bool $inspectContent = true,
        ): InstanceSourceInventory {
            $this->record('inspect', $instance->id);

            if (isset($this->inspectionFailures[$instance->id])) {
                throw new RuntimeConvergenceException(
                    'app-instance-source-removal-inspect',
                    $this->inspectionFailures[$instance->id],
                    "Instance [{$instance->name}] source origin does not match the Project repository.",
                );
            }

            $paths = $this->linkedPaths[$instance->id] ?? $this->livePaths ?? [$instance->checkout_path];
            $payload = [
                'instance_id' => $instance->id,
                'layout' => $instance->source_layout,
                'repository_identity' => $instance->project->repository_identity,
                'checkout_path' => $instance->checkout_path,
                'root' => dirname(dirname($instance->checkout_path)),
                'branch' => $instance->branch,
                'starting_commit' => $instance->starting_commit,
                'common_repository_path' => $instance->source_layout === 'checkout'
                    ? $instance->checkout_path
                    : dirname(dirname($instance->checkout_path)).'/acme/default',
                'source_identity' => "test:{$instance->id}",
                'linked_worktree_paths' => $paths,
            ];

            return new InstanceSourceInventory(
                instanceId: $instance->id,
                layout: $instance->source_layout,
                repositoryIdentity: $instance->project->repository_identity,
                checkoutPath: $instance->checkout_path,
                root: dirname(dirname($instance->checkout_path)),
                branch: $instance->branch,
                startingCommit: $instance->starting_commit ?? '',
                commonRepositoryPath: $payload['common_repository_path'],
                sourceIdentity: "test:{$instance->id}",
                linkedWorktreePaths: $paths,
                digest: hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            );
        }

        public function remove(
            Instance $instance,
            InstanceSourceInventory $inventory,
            bool $force,
        ): void {
            throw new LogicException('The durable coordinator does not call legacy source removal.');
        }

        public function prepare(
            InstanceRemovalMember $member,
            ?InstanceSourceRevalidationExpectation $expectation = null,
        ): void {
            $this->record('prepare', $member->instance_id);

            if ($this->failPrepareFor === $member->instance_id) {
                throw new ResourceOperationException(
                    'instance.source_interrupted',
                    'Source preparation interrupted.',
                    502,
                );
            }
        }

        public function revalidate(
            InstanceRemovalMember $member,
            ?InstanceSourceRevalidationExpectation $expectation = null,
        ): InstanceSourceRevalidationState {
            $this->record('revalidate', $member->instance_id);

            $state = $this->states[$member->instance_id] ?? InstanceSourceRevalidationState::Present;

            if ($state === InstanceSourceRevalidationState::Present && $this->livePaths !== null) {
                $required = $expectation?->requiredLinkedWorktreePaths ?? $member->linked_worktree_paths;
                $permitted = $expectation?->permittedLinkedWorktreePaths ?? $member->linked_worktree_paths;

                if (array_diff($required, $this->livePaths) !== [] || array_diff($this->livePaths, $permitted) !== []) {
                    throw new ResourceOperationException(
                        'instance.removal_conflict',
                        'The linked-worktree inventory changed after removal acceptance.',
                        409,
                    );
                }
            }

            return $state;
        }

        public function inspectRecorded(
            InstanceRemovalMember $member,
            InstanceSourceRevalidationState $state,
            ?InstanceSourceRevalidationExpectation $expectation = null,
        ): InstanceSourceInventory {
            $instance = Instance::query()->with('project')->findOrFail($member->instance_id);

            return $this->inspect($instance, (bool) $member->removal()->firstOrFail()->force);
        }

        public function finalize(
            InstanceRemovalMember $member,
            ?InstanceSourceRevalidationExpectation $expectation = null,
        ): string {
            $this->record('finalize', $member->instance_id);
            $this->states[$member->instance_id] = InstanceSourceRevalidationState::Completed;

            if ($this->livePaths !== null) {
                $this->livePaths = array_values(array_diff($this->livePaths, [(string) $member->checkout_path]));
            }

            return hash('sha256', "receipt\0{$member->source_digest}");
        }

        private function record(string $operation, int $id): void
        {
            $this->calls[] = "{$operation}:{$id}";

            if ($this->fail === $operation) {
                if ($operation === 'revalidate') {
                    throw new ResourceOperationException(
                        'instance.removal_conflict',
                        'Source identity changed after removal acceptance.',
                        409,
                    );
                }

                throw new ResourceOperationException(
                    'instance.source_interrupted',
                    'Source operation interrupted.',
                    502,
                );
            }
        }
    };
    app()->instance(DevelopmentInstanceSourceRemoval::class, $this->removalSource);
    app()->instance(DevelopmentInstanceSourceFinalizer::class, $this->removalSource);
    $this->removalProjector = new class implements InstanceRemovalProjector
    {
        /** @var list<string> */
        public array $calls = [];

        public ?string $fail = null;

        public function clearRouteTarget(InstanceRemovalMember $member): string
        {
            $this->record('route', $member);
            $route = Route::query()->find($member->route_id);

            if (! $route instanceof Route) {
                return 'deleted';
            }

            $route->targets()->where('instance_id', $member->instance_id)->delete();

            if ($route->targets()->exists()) {
                $route
                    ->targets()
                    ->orderBy('position')
                    ->get()
                    ->each(
                        static fn (RouteTarget $target, int $position) => $target->update(['position' => $position]),
                    );

                return 'retained';
            }

            $route->delete();

            return 'deleted';
        }

        public function cleanupRuntime(InstanceRemovalMember $member): void
        {
            $this->record('runtime', $member);
        }

        private function record(string $operation, InstanceRemovalMember $member): void
        {
            $this->calls[] = "{$operation}:{$member->instance_id}";

            if ($this->fail === $operation) {
                throw new ResourceOperationException(
                    'instance.runtime_interrupted',
                    'Removal projection interrupted.',
                    502,
                );
            }
        }
    };
    app()->instance(InstanceRemovalProjector::class, $this->removalProjector);

    $this->node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => 'test',
        'public_ssh_host' => '192.0.2.10',
        'wireguard_ip' => '10.44.0.3',
        'user' => 'orbit',
        'settings' => ['apps' => ['path' => '/srv/orbit/apps']],
    ]);
    $this->node
        ->roles()
        ->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);
    $operator = Node::query()->create([
        'name' => 'operator',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $this->markAsGateway($operator);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    $this->orbitApp = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://github.com/acme/site.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
});

describe('development Instance rename', function (): void {
    beforeEach(function (): void {
        $this->renameTransport = new class implements SshExecutor
        {
            public int $exitCode = 0;

            public int $calls = 0;

            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                $this->calls++;

                $branch = $command->arguments[array_key_last($command->arguments)];
                $output = base64_encode('https://github.com/acme/site.git')."\n".base64_encode($branch)."\n";

                return new CommandResult($this->exitCode, $output, '', 1, false);
            }
        };
        app()->instance(DevelopmentSshExecutor::class, new DevelopmentSshExecutor(
            $this->renameTransport,
            new class implements SshKeyProvider
            {
                public function privateKeyPath(): string
                {
                    return '/tmp/test-key';
                }

                public function publicKey(): string
                {
                    return 'ssh-ed25519 test';
                }
            },
            new class implements KnownHostsStore
            {
                public function path(): string
                {
                    return '/tmp/test-hosts';
                }

                public function put(string $host, int $port, HostKey $key): void {}
            },
        ));
        $this->routeProjection = Mockery::mock(RouteDomainProjector::class)->shouldIgnoreMissing();
        app()->instance(RouteDomainProjector::class, $this->routeProjection);
        $this->postJson('/api/v1/instances', ['project_id' => $this->orbitApp->id, 'node_id' => $this->node->id, 'name' => 'dev'])->assertCreated();
        $this->renameInstance = Instance::query()->sole();
    });

    it('freezes per-app route rename branch presence and value before failed preparation', function (): void {
        $payload = ['branch' => 't3code/login', 'domain' => 'login.acme.test'];
        $this->routeProjection->shouldReceive('prepareWorkloadCertificate')->once()->andThrow(new ResourceOperationException('route.domain_change_failed', 'Preparation failed.', 502));
        $url = '/api/v1/instances/'.$this->renameInstance->id.'/rename';
        $this->postJson($url, $payload)->assertStatus(502);
        $journal = InstanceRename::query()->sole();
        expect($journal->app)->toBe('web')->and($journal->domain)->toBe('login.acme.test')
            ->and($journal->branch_supplied)->toBeTrue()->and($journal->branch)->toBe('t3code/login')->and($journal->phase)->toBe('requested');
        foreach ([['domain' => $payload['domain']], ['branch' => 'different', 'domain' => $payload['domain']], ['branch' => $payload['branch']]] as $changed) {
            $this->postJson($url, $changed)->assertConflict()->assertJsonPath('error.code', 'route.domain_change_conflict');
        }
        $this->deleteJson('/api/v1/instances/'.$this->renameInstance->id)->assertConflict()->assertJsonPath('error.code', 'instance.lifecycle_busy');
        app()->instance(RouteDomainProjector::class, Mockery::mock(RouteDomainProjector::class)->shouldIgnoreMissing());
        $this->postJson($url, $payload)->assertOk();
        expect($journal->refresh()->phase)->toBe('complete')->and($this->renameInstance->refresh()->branch)->toBe('t3code/login');
    });

    it('keeps interrupted per-app route rename ownership across slug and generic Route mutations', function (bool $afterCleanup): void {
        $instance = $this->renameInstance;
        $instance->recordAppRuntime('web', ['laravel' => true]);
        InstanceEnvironmentValue::query()->create(['instance_id' => $instance->id, 'app' => 'web', 'env_key' => 'APP_URL', 'env_value' => 'https://'.$instance->authoritativeRoute('web')->domain]);
        $payload = ['branch' => 't3code/login', 'domain' => 'owned-rename.acme.test'];
        $url = '/api/v1/instances/'.$instance->id.'/rename';
        $event = 'eloquent.updated: '.InstanceRename::class;
        if ($afterCleanup) {
            Event::listen($event, static function (InstanceRename $journal): void {
                if ($journal->phase === 'complete') {
                    throw new ResourceOperationException('route.domain_change_failed', 'Interrupted completion.', 502);
                }
            });
        } else {
            $this->routeProjection->shouldReceive('prepareWorkloadCertificate')->once()->andThrow(new ResourceOperationException('route.domain_change_failed', 'Interrupted preparation.', 502));
        }
        try {
            $this->postJson($url, $payload)->assertStatus(502);
        } finally {
            if ($afterCleanup) {
                Event::forget($event);
            }
        }
        app()->instance(RouteDomainProjector::class, Mockery::mock(RouteDomainProjector::class)->shouldIgnoreMissing());
        app()->instance(DevelopmentRouteProjector::class, Mockery::mock(DevelopmentRouteProjector::class)->shouldIgnoreMissing());
        expect(InstanceRename::query()->sole()->phase)->toBe($afterCleanup ? 'domain_converged' : 'requested');
        $before = Route::query()->orderBy('id')->get()->map->getAttributes()->all();
        $urls = $this->configuration->urls;
        $environment = InstanceEnvironmentValue::query()->get()->map->getAttributes()->all();
        $this->patchJson('/api/v1/projects/'.$instance->project_id, ['slug' => 'competing-slug'])->assertConflict()->assertJsonPath('error.code', 'instance.lifecycle_busy');
        $current = $instance->refresh()->authoritativeRoute('web');
        foreach ([
            fn () => app(ConvergeRouteAction::class)->execute($current, 'competing.acme.test', allowGenerated: true),
            fn () => app(UpdateRouteAction::class)->execute($current, new UpdateRouteData(true, 'competing.acme.test', false, null), allowGenerated: true),
            fn () => app(RemoveRouteAction::class)->execute($current),
            fn () => app(SetRouteTargetAction::class)->execute($current, $instance->id),
            fn () => app(ConvergeRouteAction::class)->execute($current, 'competing.acme.test', allowGenerated: true, renameOwner: InstanceRename::query()->sole()),
        ] as $competing) {
            expect($competing)->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.lifecycle_busy'));
        }
        expect($instance->project->fresh()->slug)->toBe('acme')
            ->and(Route::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($before)
            ->and(InstanceEnvironmentValue::query()->get()->map->getAttributes()->all())->toBe($environment)
            ->and($this->configuration->urls)->toBe($urls)
            ->and(ProjectUpdate::query()->count())->toBe(0);
        app()->instance(RouteDomainProjector::class, Mockery::mock(RouteDomainProjector::class)->shouldIgnoreMissing());
        $this->postJson($url, $payload)->assertOk();
        expect($instance->refresh()->authoritativeRoute('web')->domain)->toBe($payload['domain'])
            ->and(InstanceEnvironmentValue::query()->where('instance_id', $instance->id)->where('app', 'web')->where('env_key', 'APP_URL')->sole()->env_value)->toBe('https://'.$payload['domain'])
            ->and($this->configuration->urls[array_key_last($this->configuration->urls)])->toBe('https://'.$payload['domain'])
            ->and(InstanceRename::query()->sole()->phase)->toBe('complete');
    })->with(['preparation rolled back' => false, 'domain converged' => true]);

    it('commits the per-app route rename branch and completion receipt atomically', function (): void {
        $event = 'eloquent.updated: '.InstanceRename::class;
        Event::listen($event, static function (InstanceRename $journal): void {
            if ($journal->phase === 'complete') {
                throw new ResourceOperationException('route.domain_change_failed', 'Completion transaction interrupted.', 502);
            }
        });
        $url = '/api/v1/instances/'.$this->renameInstance->id.'/rename';
        $payload = ['branch' => 't3code/login', 'domain' => 'login.acme.test'];
        try {
            $this->postJson($url, $payload)->assertStatus(502);
            expect($this->renameInstance->refresh()->branch)->toBe('dev')
                ->and(InstanceRename::query()->sole()->phase)->toBe('domain_converged')
                ->and(Route::query()->sole()->domain)->toBe($payload['domain']);
        } finally {
            Event::forget($event);
        }
        $routeId = Route::query()->sole()->id;
        $this->postJson($url, ['domain' => $payload['domain']])->assertConflict()->assertJsonPath('error.code', 'route.domain_change_conflict');
        $this->postJson($url, $payload)->assertOk();
        expect($this->renameInstance->refresh()->branch)->toBe('t3code/login')
            ->and(InstanceRename::query()->sole()->phase)->toBe('complete')->and(Route::query()->sole()->id)->toBe($routeId);
    });

    it('returns the completed per-app route rename after a lost response without inspecting or preparing again', function (): void {
        $event = 'eloquent.retrieved: '.Instance::class;
        Event::listen($event, static function (): void {
            if (InstanceRename::query()->where('phase', 'complete')->exists()) {
                throw new ResourceOperationException('route.domain_change_failed', 'Lost completion response.', 502);
            }
        });
        $url = '/api/v1/instances/'.$this->renameInstance->id.'/rename';
        $payload = ['branch' => 't3code/login', 'domain' => 'login.acme.test'];
        try {
            $this->postJson($url, $payload)->assertStatus(502);
        } finally {
            Event::forget($event);
        }
        expect(InstanceRename::query()->sole()->phase)->toBe('complete')->and($this->renameInstance->refresh()->branch)->toBe('t3code/login');
        $routeId = Route::query()->sole()->id;
        $calls = $this->renameTransport->calls;
        $this->renameTransport->exitCode = 42;
        $this->postJson($url, $payload)->assertOk();
        expect($this->renameTransport->calls)->toBe($calls)->and(Route::query()->sole()->id)->toBe($routeId);
    });

    it('records branch-only, domain-only and combined renames and makes retries no-ops', function (array $payload): void {
        $id = $this->renameInstance->id;
        $this->renameInstance->update(['source_is_laravel' => true]);
        $this->configuration->urls = [];
        $before = $this->renameInstance->only(['id', 'name', 'checkout_path', 'starting_commit']);
        $response = $this->postJson("/api/v1/instances/{$id}/rename", $payload)->assertOk();
        $this->renameInstance->refresh();
        expect($this->renameInstance->only(array_keys($before)))->toBe($before)
            ->and($this->renameInstance->branch)->toBe($payload['branch'] ?? 'dev')
            ->and($this->renameInstance->branch_override)->toBe($payload['branch'] ?? null)
            ->and(Route::query()->sole()->domain)->toBe($payload['domain'] ?? 'web.dev.acme.test')
            ->and(Route::query()->sole()->provenance)->toBe(RouteProvenance::Generated);
        if (isset($payload['domain'])) {
            expect($this->configuration->urls)->toBe(['https://'.$payload['domain'], 'https://'.$payload['domain']])
                ->and(InstanceEnvironmentValue::query()->where('instance_id', $id)->where('env_key', 'APP_URL')->sole()->env_value)->toBe('https://'.$payload['domain']);
        }
        $routeId = Route::query()->sole()->id;
        $this->postJson("/api/v1/instances/{$id}/rename", $payload)->assertOk()->assertJsonPath('data.id', $response->json('data.id'));
        expect(Route::query()->sole()->id)->toBe($routeId);
        $this->deleteJson("/api/v1/instances/{$id}")->assertOk();
    })->with([
        'branch' => [['branch' => 't3code/login']],
        'domain' => [['domain' => 'login.acme.test']],
        'both' => [['branch' => 't3code/login', 'domain' => 'login.acme.test']],
    ]);

    it('refuses wrong or detached HEAD and remote lifecycle contention before changing either field', function (int $exit, string $code): void {
        $this->renameTransport->exitCode = $exit;
        $before = $this->renameInstance->getAttributes();
        $this->postJson('/api/v1/instances/'.$this->renameInstance->id.'/rename', ['branch' => 't3code/login', 'domain' => 'login.acme.test'])
            ->assertConflict()->assertJsonPath('error.code', $code);
        expect($this->renameInstance->refresh()->getAttributes())->toBe($before)
            ->and(Route::query()->sole()->domain)->toBe('web.dev.acme.test');
    })->with([[42, 'instance.branch_not_checked_out'], [75, 'instance.lifecycle_busy']]);

    it('refuses a domain conflict before recording the checked-out branch', function (): void {
        Route::query()->create(['project_id' => $this->orbitApp->id, 'node_id' => $this->node->id, 'domain' => 'occupied.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
        $this->postJson('/api/v1/instances/'.$this->renameInstance->id.'/rename', ['branch' => 't3code/login', 'domain' => 'occupied.test'])
            ->assertConflict()->assertJsonPath('error.code', 'route.domain_conflict');
        expect($this->renameInstance->refresh()->branch)->toBe('dev');
    });

    it('does not record the branch when Route convergence fails and records it on a successful retry', function (): void {
        $payload = ['branch' => 't3code/login', 'domain' => 'login.acme.test'];
        $this->routeProjection->shouldReceive('prepareWorkloadCertificate')->once()->andThrow(new ResourceOperationException('route.domain_change_failed', 'Injected projection failure.', 502));
        $this->postJson('/api/v1/instances/'.$this->renameInstance->id.'/rename', $payload)->assertStatus(502);
        expect($this->renameInstance->refresh()->branch)->toBe('dev');
        app()->instance(RouteDomainProjector::class, Mockery::mock(RouteDomainProjector::class)->shouldIgnoreMissing());
        $this->postJson('/api/v1/instances/'.$this->renameInstance->id.'/rename', $payload)->assertOk();
        expect($this->renameInstance->refresh()->branch)->toBe('t3code/login');
    });

    it('validates presence, types and branch names', function (array $payload): void {
        $this->postJson('/api/v1/instances/'.$this->renameInstance->id.'/rename', $payload)
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        expect($this->renameTransport->calls)->toBe(0);
    })->with([[[]], [['branch' => null]], [['domain' => '']], [['branch' => 7]], [['branch' => '-invalid']]]);

    it('maps environment-owner contention to lifecycle busy', function (): void {
        app()->instance(InstanceEnvironmentOperationLock::class, new class implements InstanceEnvironmentOperationLock
        {
            public function run(array $instanceIds, Closure $operation): mixed
            {
                throw new ResourceOperationException('env.operation_busy', 'Owner is busy.', 409);
            }
        });
        $this->postJson('/api/v1/instances/'.$this->renameInstance->id.'/rename', ['branch' => 't3code/login'])
            ->assertConflict()->assertJsonPath('error.code', 'instance.lifecycle_busy');
    });

    it('refuses reserved domains before recording the branch', function (): void {
        $this->postJson('/api/v1/instances/'.$this->renameInstance->id.'/rename', ['branch' => 't3code/login', 'domain' => 'gateway.orbit'])
            ->assertConflict()->assertJsonPath('error.code', 'route.domain_conflict');
        expect($this->renameInstance->refresh()->branch)->toBe('dev');
    });

    it('keeps the existing override when recording the current branch again', function (): void {
        $this->postJson('/api/v1/instances/'.$this->renameInstance->id.'/rename', ['branch' => 'dev'])->assertOk();
        expect($this->renameInstance->refresh()->branch_override)->toBeNull();
    });

    it('recovers forward after Route cutover without recording the branch early', function (): void {
        $payload = ['branch' => 't3code/login', 'domain' => 'login.acme.test'];
        $this->routeProjection->shouldReceive('cleanup')->once()->andThrow(new ResourceOperationException('route.domain_change_failed', 'Injected cleanup failure.', 502));
        $this->postJson('/api/v1/instances/'.$this->renameInstance->id.'/rename', $payload)->assertStatus(502);
        expect($this->renameInstance->refresh()->branch)->toBe('dev')->and(Route::query()->count())->toBe(2);
        foreach ([['domain' => $payload['domain']], ['domain' => $payload['domain'], 'branch' => 'different'], ['branch' => $payload['branch']]] as $changed) {
            $this->postJson('/api/v1/instances/'.$this->renameInstance->id.'/rename', $changed)->assertConflict()->assertJsonPath('error.code', 'route.domain_change_conflict');
        }
        $this->postJson('/api/v1/instances/'.$this->renameInstance->id.'/rename', ['domain' => 'other.acme.test'])->assertConflict()->assertJsonPath('error.code', 'route.domain_change_conflict');
        app()->instance(RouteDomainProjector::class, Mockery::mock(RouteDomainProjector::class)->shouldIgnoreMissing());
        $this->postJson('/api/v1/instances/'.$this->renameInstance->id.'/rename', $payload)->assertOk();
        expect($this->renameInstance->refresh()->branch)->toBe('t3code/login')->and(Route::query()->sole()->domain)->toBe('login.acme.test');
    });

    it('refuses the original domain while a failed replacement is retained without recording branch or environment', function (bool $afterCutover): void {
        $id = $this->renameInstance->id;
        $this->renameInstance->update(['source_is_laravel' => true]);
        $value = InstanceEnvironmentValue::query()->create(['instance_id' => $id, 'env_key' => 'APP_URL', 'env_value' => 'https://dev.acme.test']);
        if ($afterCutover) {
            $this->routeProjection->shouldReceive('cleanup')->once()->andThrow(new ResourceOperationException('route.domain_change_failed', 'Retained cleanup failure.', 502));
        } else {
            $this->routeProjection->shouldReceive('publishDns')->once()->andThrow(new ResourceOperationException('route.domain_change_failed', 'Failure before cutover.', 502));
            $this->routeProjection->shouldReceive('rollbackCaddy')->once()->andThrow(new ResourceOperationException('route.domain_change_failed', 'Retained rollback failure.', 502));
        }
        $this->postJson("/api/v1/instances/{$id}/rename", ['branch' => 't3code/login', 'domain' => 'login.acme.test'])->assertStatus(502);
        expect(Route::query()->count())->toBe(2);
        $beforeRoutes = Route::query()->orderBy('id')->get()->map->getAttributes()->all();
        $beforeValue = $value->refresh()->getAttributes();
        $original = Route::query()->whereNull('replaces_route_id')->sole();
        // Generic preflight has no rename owner authorization; the matching rename below validates its own request identity.
        expect(fn () => app(ConvergeRouteAction::class)->assertConvergible($original, 'web.dev.acme.test', allowGenerated: true))
            ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('instance.lifecycle_busy'));
        $this->configuration->urls = [];
        $this->postJson("/api/v1/instances/{$id}/rename", ['branch' => 't3code/login', 'domain' => 'web.dev.acme.test'])
            ->assertConflict()->assertJsonPath('error.code', 'route.domain_change_conflict');
        expect($this->renameInstance->refresh()->branch)->toBe('dev')
            ->and($this->renameInstance->branch_override)->toBeNull()
            ->and($value->refresh()->getAttributes())->toBe($beforeValue)
            ->and($this->configuration->urls)->toBe([])
            ->and(Route::query()->orderBy('id')->get()->map->getAttributes()->all())->toBe($beforeRoutes);
    })->with(['before cutover' => false, 'after cutover' => true]);

    it('refuses an Instance with an unfinished removal', function (): void {
        $id = $this->renameInstance->id;
        $this->removalSource->fail = 'prepare';
        $this->deleteJson("/api/v1/instances/{$id}")->assertStatus(502);
        $this->postJson("/api/v1/instances/{$id}/rename", ['branch' => 't3code/login'])->assertConflict()->assertJsonPath('error.code', 'instance.lifecycle_busy');
    });

    it('refuses an inactive checkout', function (): void {
        $this->renameInstance->update(['status' => InstanceState::SourceResolved]);
        $this->postJson('/api/v1/instances/'.$this->renameInstance->id.'/rename', ['branch' => 't3code/login'])
            ->assertConflict()->assertJsonPath('error.code', 'instance.rename_inactive');
        expect($this->renameTransport->calls)->toBe(0);
    });

    it('refuses production and linked-worktree Instances', function (string $layout): void {
        if ($layout === 'production') {
            $node = create_app_prod_node('prod');
            [$instance] = seed_active_production_app_instance($this->orbitApp, $node, 'prod');
        } else {
            $instance = $this->renameInstance;
            $instance->update(['source_layout' => 'worktree']);
        }
        $this->postJson('/api/v1/instances/'.$instance->id.'/rename', ['branch' => 't3code/login'])
            ->assertConflict()->assertJsonPath('error.code', 'instance.rename_unsupported');
        expect($this->renameTransport->calls)->toBe(0);
    })->with(['production', 'worktree']);

    it('requires an own Route only for domain changes', function (): void {
        $route = Route::query()->sole();
        $this->renameInstance->project->update(['type' => ProjectType::Monorepo]);
        $route->targets()->delete();
        $route->delete();
        $id = $this->renameInstance->id;
        $this->postJson("/api/v1/instances/{$id}/rename", ['domain' => 'login.acme.test'])->assertConflict()->assertJsonPath('error.code', 'instance.route_required');
        $this->postJson("/api/v1/instances/{$id}/rename", ['branch' => 't3code/login'])->assertOk();
    });
});

/** @return array{Instance, Route} */
function retain_legacy_source_profile_checkpoint(string $checkpoint): array
{
    $instance = Instance::query()->sole();
    $route = Route::query()->sole();
    $route->update(['status' => RouteStatus::Pending]);
    $instance->update([
        'status' => InstanceState::SourceResolved,
        'source_is_laravel' => null,
        'provisioning_step' => $checkpoint,
        'failed_step' => null,
        'error_code' => null,
    ]);

    return [$instance->refresh(), $route->refresh()];
}

function create_app_prod_node(string $name, ?string $tld = 'test'): Node
{
    $addressSuffix = Node::query()->count() + 30;
    $nodeTld = $tld === 'test' ? "{$name}.test" : $tld;
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'tld' => $nodeTld,
        'public_ssh_host' => "192.0.2.{$addressSuffix}",
        'wireguard_ip' => "10.44.1.{$addressSuffix}",
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);

    return $node->refresh();
}

/** @return array{Instance, Route} */
function seed_active_production_app_instance(
    Project $project,
    Node $node,
    string $name,
    ?string $domain = null,
    ?string $root = null,
    ?string $branchOverride = null,
    bool $flatHome = false,
): array {
    $user = "orbit-app-{$project->id}";
    $home = "/home/{$user}";
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $name,
        'environment' => 'production',
        'source_layout' => InstanceSourceLayout::Checkout->value,
        'checkout_path' => $flatHome ? $home : "{$home}/releases/initial",
        'production_user' => $user,
        'production_home' => $home,
        'root' => $root,
        'branch' => $branchOverride ?? $project->default_branch,
        'branch_override' => $branchOverride,
        'starting_commit' => str_repeat('b', 40),
        'selected_php_version' => '8.5',
        'source_is_laravel' => false,
        'production_php_service' => "orbit-{$user}-php8.5-fpm.service",
        'production_php_pool' => "orbit-{$user}",
        'production_php_socket' => "/run/php/{$user}.sock",
        'provisioning_step' => 'active',
        'status' => InstanceState::Active,
    ]);
    $resolvedHostname = $domain ?? "{$name}.{$project->slug}.{$node->tld}";
    $generated = $domain === null;
    $route = Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'generation_basis_node_id' => $generated ? $node->id : null,
        'domain' => $resolvedHostname,
        'provenance' => $generated ? RouteProvenance::Generated : RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create([
        'instance_id' => $instance->id,
        'position' => 0,
    ]);
    $route->update(['status' => RouteStatus::Active]);

    return [$instance->refresh(), $route->refresh()];
}

it('bounds Instance response relationship queries for one and several visible rows', function (): void {
    $secondVisibleNode = Node::query()->create([
        'name' => 'second-visible-node',
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
    $consumer->accessibleNodes()->attach([$this->node->id, $secondVisibleNode->id]);

    $first = Instance::query()->create([
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'first',
        'checkout_path' => '/srv/orbit/apps/acme/first',
    ]);
    $firstRoute = Route::query()->create([
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'generation_basis_node_id' => $this->node->id,
        'domain' => 'first.acme.test',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
    ]);
    $firstRoute->targets()->create(['instance_id' => $first->id, 'position' => 0]);
    $firstRoute->update(['status' => RouteStatus::Active]);
    $first->update(['status' => InstanceState::Active]);

    /** @var list<string> $relationshipQueries */
    $relationshipQueries = [];
    DB::listen(static function (QueryExecuted $query) use (&$relationshipQueries): void {
        $sql = str_replace(['"', '`'], '', mb_strtolower($query->sql));
        $relationship = match (true) {
            str_contains($sql, ' from projects ') => 'apps',
            str_contains($sql, ' from routes ') => 'routes',
            str_contains($sql, ' from route_targets ') => 'targets',
            default => null,
        };

        if ($relationship !== null) {
            $relationshipQueries[] = $relationship;
        }
    });

    $this
        ->withServerVariables(['REMOTE_ADDR' => $consumer->wireguard_ip])
        ->getJson('/api/v1/instances')
        ->assertOk()
        ->assertJsonPath('data.*.id', [$first->id])
        ->assertJsonPath('data.0.effective_root', 'public')
        ->assertJsonPath('data.0.route.target.instance_id', $first->id);
    $oneRowQueryCounts = array_count_values($relationshipQueries);

    $second = Instance::query()->create([
        'project_id' => $this->orbitApp->id,
        'node_id' => $secondVisibleNode->id,
        'name' => 'second',
        'checkout_path' => '/srv/orbit/apps/acme/second',
    ]);
    $secondRoute = Route::query()->create([
        'project_id' => $this->orbitApp->id,
        'node_id' => $secondVisibleNode->id,
        'generation_basis_node_id' => $secondVisibleNode->id,
        'domain' => 'second.acme.test',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
    ]);
    $secondRoute->targets()->create(['instance_id' => $second->id, 'position' => 0]);
    $secondRoute->update(['status' => RouteStatus::Active]);
    $second->update(['status' => InstanceState::Active]);
    $unroutedApp = Project::query()->create([
        'name' => 'Unrouted',
        'slug' => 'unrouted',
        'repository_url' => 'https://example.test/unrouted.git',
        'root' => 'web',
    ]);
    $unrouted = Instance::query()->create([
        'project_id' => $unroutedApp->id,
        'node_id' => $this->node->id,
        'name' => 'reserved',
        'checkout_path' => '/srv/orbit/apps/unrouted/reserved',
    ]);
    $inaccessibleApp = Project::query()->create([
        'name' => 'Inaccessible',
        'slug' => 'inaccessible',
        'repository_url' => 'https://example.test/inaccessible.git',
        'root' => 'public',
    ]);
    $inaccessible = Instance::query()->create([
        'project_id' => $inaccessibleApp->id,
        'node_id' => $inaccessibleNode->id,
        'name' => 'hidden',
        'checkout_path' => '/srv/orbit/apps/inaccessible/hidden',
    ]);
    $inaccessibleRoute = Route::query()->create([
        'project_id' => $inaccessibleApp->id,
        'node_id' => $inaccessibleNode->id,
        'generation_basis_node_id' => $inaccessibleNode->id,
        'domain' => 'hidden.inaccessible.test',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
    ]);
    $inaccessibleRoute->targets()->create(['instance_id' => $inaccessible->id, 'position' => 0]);
    $inaccessibleRoute->update(['status' => RouteStatus::Active]);
    $inaccessible->update(['status' => InstanceState::Active]);
    $relationshipQueries = [];

    $this
        ->withServerVariables(['REMOTE_ADDR' => $consumer->wireguard_ip])
        ->getJson('/api/v1/instances')
        ->assertOk()
        ->assertJsonPath('data.*.id', [$first->id, $unrouted->id, $second->id])
        ->assertJsonPath('data.*.project_id', [$this->orbitApp->id, $unroutedApp->id, $this->orbitApp->id])
        ->assertJsonPath('data.1.effective_root', 'web')
        ->assertJsonPath('data.1.route', null)
        ->assertJsonPath('data.0.route.target.instance_id', $first->id)
        ->assertJsonPath('data.2.route.target.instance_id', $second->id);

    expect($oneRowQueryCounts)
        ->toBe(['apps' => 1, 'routes' => 1, 'targets' => 1])
        ->and(array_count_values($relationshipQueries))
        ->toBe($oneRowQueryCounts);
});

it('loads missing response relations for a single Instance DTO caller', function (): void {
    $instance = Instance::query()->create([
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'single',
        'checkout_path' => '/srv/orbit/apps/acme/single',
    ]);
    $route = Route::query()->create([
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'generation_basis_node_id' => $this->node->id,
        'domain' => 'single.acme.test',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => RouteStatus::Active]);
    $instance->update(['status' => InstanceState::Active]);
    $instance = Instance::query()->findOrFail($instance->id);
    $instance->preventsLazyLoading = true;

    expect($instance->relationLoaded('project'))
        ->toBeFalse()
        ->and($instance->relationLoaded('routes'))
        ->toBeFalse();

    $data = InstanceData::fromModel($instance);
    $loadedRoute = $instance->routes->first();

    expect($data->effectiveRoot)
        ->toBe('public')
        ->and($data->route?->target?->instanceId)
        ->toBe($instance->id)
        ->and($instance->relationLoaded('project'))
        ->toBeTrue()
        ->and($instance->relationLoaded('routes'))
        ->toBeTrue()
        ->and($loadedRoute)
        ->toBeInstanceOf(Route::class)
        ->and($loadedRoute?->relationLoaded('targets'))
        ->toBeTrue();
});

it('creates an active checkout Instance on a standalone Node with inherited root', function (): void {
    $requestId = (string) Str::uuid();
    $response = $this->postJson(
        '/api/v1/instances',
        [
            'project_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'dev',
        ],
        ['X-Orbit-Request-Id' => $requestId],
    );

    $response
        ->assertCreated()
        ->assertJsonMissingPath('data.cluster_id')
        ->assertJsonPath('data.source_layout', 'checkout')
        ->assertJsonPath('data.checkout_path', '/srv/orbit/apps/acme/dev')
        ->assertJsonPath('data.root', null)
        ->assertJsonPath('data.effective_root', 'public')
        ->assertJsonPath('data.selected_branch', 'dev')
        ->assertJsonPath('data.branch_override', null)
        ->assertJsonMissingPath('data.migration_required')
        ->assertJsonMissingPath('data.branch')
        ->assertJsonPath('data.starting_commit', str_repeat('a', 40))
        ->assertJsonPath('data.status', 'active');
    record_fixture($response, 'instances/instance-create/created', CreateInstanceRequest::class, 'POST /api/v1/instances');

    expect(Instance::query()->count())
        ->toBe(1)
        ->and($this->source->calls)
        ->toBe([
            'prepare:reserved',
            'inspect-prepared:checkout_prepared',
            'resolve:checkout_prepared',
            'inspect-prepared:source_resolved',
            'inspect-resolved:source_resolved',
        ])
        ->and($this->source->prepareExisting)
        ->toBe([false])
        ->and(Instance::query()->sole()->source_layout)
        ->toBe(InstanceSourceLayout::Checkout->value)
        ->and(Instance::query()->sole()->only(['selected_php_version', 'source_is_laravel']))
        ->toBe(['selected_php_version' => '8.5', 'source_is_laravel' => false])
        ->and(Activity::query()->where('request_id', $requestId)->sole()->command)
        ->toBe('instance:create')
        ->and(Activity::query()->where('request_id', $requestId)->sole()->subject_type)
        ->toBe('instance')
        ->and(Activity::query()->where('request_id', $requestId)->sole()->properties?->get('source_layout'))
        ->toBe('checkout')
        ->and(Activity::query()->where('request_id', $requestId)->sole()->properties?->get('branch_override'))
        ->toBeNull()
        ->and(Schema::hasColumn('instances', 'source_layout'))
        ->toBeTrue()
        ->and(Schema::hasColumn('instances', 'cluster_id'))
        ->toBeFalse()
        ->and(Route::query()->sole()->getAttributes())
        ->toMatchArray([
            'project_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'cluster_id' => null,
            'generation_basis_node_id' => $this->node->id,
            'domain' => 'web.dev.acme.test',
            'provenance' => 'generated',
            'publication' => 'private',
            'status' => 'active',
            'failed_step' => null,
            'error_code' => null,
        ])
        ->and(Route::query()->sole()->targets()->sole()->instance_id)
        ->toBe(Instance::query()->sole()->id);
});

it('creates an Instance from a parsed JSON body without Content-Type using normalized IDs', function (): void {
    $this->source->resolution = new DevelopmentSourceResolution('no-content-type', str_repeat('a', 40));

    $response = $this->call(
        'POST',
        '/api/v1/instances',
        server: ['HTTP_ACCEPT' => 'application/json'],
        content: json_encode([
            'project_id' => (string) $this->orbitApp->id,
            'node_id' => (string) $this->node->id,
            'name' => 'no-content-type',
        ], JSON_THROW_ON_ERROR),
    )->assertCreated();

    expect($response->json('data.project.id'))->toBe($this->orbitApp->id)
        ->and(Instance::query()->sole()->node_id)->toBe($this->node->id);
});

it('creates an Instance with a repository-root Project root for each package type', function (): void {
    foreach ([ProjectType::LaravelPackage, ProjectType::NodePackage] as $index => $type) {
        $project = Project::query()->create([
            'name' => $type->value,
            'slug' => $type->value,
            'type' => $type,
            'repository_url' => 'https://github.com/acme/'.$type->value.'.git',
            'default_branch' => 'main',
            'root' => '.',
        ]);
        $name = 'package-'.$index;
        $this->source->resolution = new DevelopmentSourceResolution($name, str_repeat('a', 40));

        $response = $this->postJson('/api/v1/instances', [
            'project_id' => $project->id,
            'node_id' => $this->node->id,
            'name' => $name,
            'root' => '.',
        ])->assertCreated()
            ->assertJsonPath('data.root', '.')
            ->assertJsonPath('data.effective_root', '.')
            ->assertJsonPath('data.status', 'active');

        expect($response->json('data.project.type'))->toBe($type->value)
            ->and(Route::query()->where('project_id', $project->id)->exists())->toBeFalse();
    }
});

it('records the instances of one App among several', function (): void {
    Project::query()->create(['name' => 'Bravo docs', 'slug' => 'bravo-docs', 'repository_url' => 'git@github.com:bravo/docs.git', 'default_branch' => 'main', 'root' => 'public']);
    $shop = Project::query()->create(['name' => 'Charlie shop', 'slug' => 'charlie-shop', 'repository_url' => 'git@github.com:charlie/shop.git', 'default_branch' => 'release', 'root' => 'web/public']);
    foreach (['dev', 'staging', 'feature-checkout'] as $name) {
        // The fake source resolves to the branch the placement name selects.
        $this->source->resolution = new DevelopmentSourceResolution($name, str_repeat('a', 40));
        $this->postJson('/api/v1/instances', ['project_id' => $shop->id, 'node_id' => $this->node->id, 'name' => $name])->assertCreated();
    }
    $this->source->resolution = new DevelopmentSourceResolution('dev', str_repeat('a', 40));
    $this->postJson('/api/v1/instances', ['project_id' => $this->orbitApp->id, 'node_id' => $this->node->id, 'name' => 'dev'])->assertCreated();

    record_fixture($this->getJson('/api/v1/instances')->assertOk()->assertJsonCount(4, 'data'), 'instances/instance-list/charlie-shop', ListInstancesRequest::class, 'GET /api/v1/instances');
    record_fixture($this->getJson('/api/v1/instances/1')->assertOk()->assertJsonPath('data.name', 'dev'), 'instances/instance-show/charlie-shop-dev', ShowInstanceRequest::class, 'GET /api/v1/instances/{instance}');
});

it('records the list and show responses of an active checkout Instance', function (): void {
    $this->postJson('/api/v1/instances', [
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();
    $instance = Instance::query()->sole();

    record_fixture($this->getJson('/api/v1/instances')->assertOk()->assertJsonCount(1, 'data'), 'instances/instance-list/default', ListInstancesRequest::class, 'GET /api/v1/instances');
    record_fixture($this->getJson("/api/v1/instances/{$instance->id}")->assertOk(), 'instances/instance-show/default', ShowInstanceRequest::class, 'GET /api/v1/instances/{instance}');
});

it('refuses new production placement with a candidate-required error before mutation', function (): void {
    $node = create_app_prod_node('app-prod');

    $refusal = $this
        ->postJson('/api/v1/instances', [
            'project_id' => $this->orbitApp->id,
            'node_id' => $node->id,
            'name' => 'release-name',
            'root' => 'public',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.candidate_required')
        ->assertJsonPath(
            'error.message',
            'New production Instances require a candidate. Use instance:clone.',
        );
    record_fixture($refusal, 'instances/instance-create/candidate-required', CreateInstanceRequest::class, 'POST /api/v1/instances');

    expect(Instance::query()->count())
        ->toBe(0)
        ->and(Route::query()->count())
        ->toBe(0)
        ->and($this->productionSource->calls)
        ->toBe([])
        ->and($this->productionProjection->calls)
        ->toBe([]);
});

it('refuses a repeat for an existing production Instance with candidate required', function (): void {
    $node = create_app_prod_node('app-prod');
    $payload = [
        'project_id' => $this->orbitApp->id,
        'node_id' => $node->id,
        'name' => 'stable',
        'branch' => 'release',
        'domain' => 'www.example.test',
    ];
    [$instance] = seed_active_production_app_instance(
        $this->orbitApp,
        $node,
        'stable',
        domain: 'www.example.test',
        branchOverride: 'release',
    );
    $before = $instance->getAttributes();
    $routeBefore = Route::query()->sole()->getAttributes();

    $this
        ->postJson('/api/v1/instances', $payload)
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.candidate_required');

    expect($instance->refresh()->getAttributes())
        ->toBe($before)
        ->and(Route::query()->sole()->getAttributes())
        ->toBe($routeBefore)
        ->and($this->productionSource->calls)
        ->toBe([])
        ->and($this->productionProjection->calls)
        ->toBe([])
        ->and(Instance::query()->count())
        ->toBe(1)
        ->and(Route::query()->count())
        ->toBe(1);
});

it('refuses new production placement when the standalone Node has no TLD', function (): void {
    $node = create_app_prod_node('explicit-host-prod', null);

    $this
        ->postJson('/api/v1/instances', [
            'project_id' => $this->orbitApp->id,
            'node_id' => $node->id,
            'name' => 'explicit-host',
            'domain' => 'www.example.test',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.candidate_required');

    expect(Instance::query()->count())
        ->toBe(0)
        ->and(Route::query()->count())
        ->toBe(0)
        ->and($this->productionSource->calls)
        ->toBe([]);
});

it('refuses new production placement before records or remote work', function (): void {
    $clustered = create_app_prod_node('clustered-prod');
    $cluster = Cluster::query()->create(['name' => 'production', 'state' => ClusterState::Active]);
    $clustered->update(['cluster_id' => $cluster->id]);

    $this
        ->postJson('/api/v1/instances', [
            'project_id' => $this->orbitApp->id,
            'node_id' => $clustered->id,
            'name' => 'clustered',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.candidate_required');

    $withoutTld = create_app_prod_node('no-tld-prod', null);
    $this
        ->postJson('/api/v1/instances', [
            'project_id' => $this->orbitApp->id,
            'node_id' => $withoutTld->id,
            'name' => 'no-hostname',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.candidate_required');

    expect(Instance::query()->count())
        ->toBe(0)
        ->and(Route::query()->count())
        ->toBe(0)
        ->and($this->productionSource->calls)
        ->toBe([])
        ->and($this->productionProjection->calls)
        ->toBe([]);
});

it('refuses Laravel production creation before reserving an inactive Instance', function (): void {
    $node = create_app_prod_node('laravel-prod');
    $this->productionSource->laravel = true;

    $this
        ->postJson('/api/v1/instances', [
            'project_id' => $this->orbitApp->id,
            'node_id' => $node->id,
            'name' => 'laravel',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.candidate_required');

    expect(Instance::query()->count())
        ->toBe(0)
        ->and(Route::query()->count())
        ->toBe(0)
        ->and($this->productionSource->calls)
        ->toBe([])
        ->and($this->productionProjection->calls)
        ->toBe([]);
});

it('keeps production identity stable across slug changes and refuses another direct production create', function (): void {
    $firstNode = create_app_prod_node('first-prod');
    $secondNode = create_app_prod_node('second-prod');
    [$first] = seed_active_production_app_instance($this->orbitApp, $firstNode, 'first');
    $identity = $first->only(['production_user', 'production_home', 'checkout_path']);
    $this->orbitApp->update(['slug' => 'renamed']);

    $this
        ->getJson("/api/v1/instances/{$first->id}")
        ->assertOk()
        ->assertJsonPath('data.production_user', $identity['production_user'])
        ->assertJsonPath('data.production_home', $identity['production_home']);
    $this
        ->postJson('/api/v1/instances', [
            'project_id' => $this->orbitApp->id,
            'node_id' => $firstNode->id,
            'name' => 'second',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.candidate_required');
    $this
        ->postJson('/api/v1/instances', [
            'project_id' => $this->orbitApp->id,
            'node_id' => $secondNode->id,
            'name' => 'second',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.candidate_required');

    expect(Instance::query()->count())
        ->toBe(1)
        ->and(Instance::query()->findOrFail($first->id)->only(array_keys($identity)))
        ->toBe($identity);
});

it('removes an existing production Instance through retained-content removal', function (): void {
    $node = create_app_prod_node('removal-prod');
    [$instance] = seed_active_production_app_instance($this->orbitApp, $node, 'production');
    $home = $instance->production_home;

    $this
        ->deleteJson("/api/v1/instances/{$instance->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    expect($home)
        ->toBe("/home/orbit-app-{$this->orbitApp->id}")
        ->and(Instance::query()->count())
        ->toBe(0)
        ->and(Route::query()->count())
        ->toBe(0)
        ->and($this->removalSource->calls)
        ->toBe([])
        ->and($this->removalProjector->calls)
        ->toBe(["route:{$instance->id}", "runtime:{$instance->id}"]);
});

it('shows and removes an existing production Instance through inactive Cluster Node scope', function (): void {
    $node = create_app_prod_node('inactive-removal-prod');
    $cluster = Cluster::query()->create([
        'name' => 'inactive-production-removal',
        'state' => ClusterState::Inactive,
    ]);
    $node->update(['cluster_id' => $cluster->id]);
    [$instance] = seed_active_production_app_instance($this->orbitApp, $node, 'production');

    $this
        ->getJson("/api/v1/instances/{$instance->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.route.node_id', $node->id)
        ->assertJsonPath('data.route.cluster_id', null);

    $this
        ->deleteJson("/api/v1/instances/{$instance->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    expect(Instance::query()->count())
        ->toBe(0)
        ->and(Route::query()->count())
        ->toBe(0);
});

it('retries existing production removal without recreating its deleted Route', function (): void {
    $node = create_app_prod_node('removal-retry-prod');
    [$instance] = seed_active_production_app_instance($this->orbitApp, $node, 'production-retry');
    $this->removalProjector->fail = 'runtime';

    $this
        ->deleteJson("/api/v1/instances/{$instance->id}")
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'instance.runtime_interrupted');
    expect(Route::query()->count())->toBe(0);

    $this->removalProjector->fail = null;
    $this
        ->deleteJson("/api/v1/instances/{$instance->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    expect(Route::query()->count())
        ->toBe(0)
        ->and(Instance::query()->count())
        ->toBe(0);
});

it('fails closed for legacy incomplete profile evidence on an ordinary API retry', function (
    string $checkpoint,
): void {
    $payload = [
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ];
    $this->postJson('/api/v1/instances', $payload)->assertCreated();
    [$instance] = retain_legacy_source_profile_checkpoint($checkpoint);
    $before = $instance->only([
        'id',
        'project_id',
        'node_id',
        'checkout_path',
        'branch',
        'starting_commit',
        'selected_php_version',
        'source_is_laravel',
        'provisioning_step',
    ]);
    $this->configuration->inspections = 0;
    $this->configuration->configurations = 0;
    $this->projection->convergences = 0;

    $this
        ->postJson('/api/v1/instances', $payload)
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'app-dev.source_evidence_changed');

    expect(Instance::query()->whereKey($instance->id)->exists())
        ->toBeFalse()
        ->and($this->configuration->inspections)
        ->toBe(0)
        ->and($this->configuration->configurations)
        ->toBe(0)
        ->and($this->projection->convergences)
        ->toBe(0);
})->with(['php-selected', 'url-configured']);

it('keeps explicit branch selection separate from default identity and Route identity', function (): void {
    $this->source->resolution = new DevelopmentSourceResolution('release', str_repeat('b', 40));

    $response = $this->postJson('/api/v1/instances', [
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'default',
        'branch' => 'release',
    ]);

    $response
        ->assertCreated()
        ->assertJsonPath('data.name', 'default')
        ->assertJsonPath('data.checkout_path', '/srv/orbit/apps/acme/default')
        ->assertJsonPath('data.selected_branch', 'release')
        ->assertJsonPath('data.branch_override', 'release')
        ->assertJsonPath('data.domain', 'web.acme.test');
});

it('retains explicit override intent when it equals the Project default branch', function (): void {
    $this->source->resolution = new DevelopmentSourceResolution('main', str_repeat('b', 40));

    $this
        ->postJson('/api/v1/instances', [
            'project_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'default',
            'branch' => 'main',
        ])
        ->assertCreated()
        ->assertJsonPath('data.selected_branch', 'main')
        ->assertJsonPath('data.branch_override', 'main');
});

it('rejects invalid branch input before persistence or source work', function (): void {
    $this
        ->postJson('/api/v1/instances', [
            'project_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'default',
            'branch' => '../release',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.details.branch.0', 'The branch is not a valid Git branch name.');

    expect(Instance::query()->count())
        ->toBe(0)
        ->and(Route::query()->count())
        ->toBe(0)
        ->and($this->source->calls)
        ->toBe([]);
});

it('cleans up a branch resolution failure before activation', function (): void {
    $this->source->fail = 'resolve';
    $this->source->failureCode = 'instance.branch_resolution_failed';

    $this
        ->postJson('/api/v1/instances', [
            'project_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'default',
            'branch' => 'missing',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'instance.branch_resolution_failed');

    expect(Instance::query()->count())->toBe(0)
        ->and(Route::query()->count())->toBe(0)
        ->and($this->removalSource->calls)->toContain('finalize:1');
    $this->source->fail = null;
    $this->source->resolution = new DevelopmentSourceResolution('t3code/retry', str_repeat('b', 40));
    $this->postJson('/api/v1/instances', ['project_id' => $this->orbitApp->id, 'node_id' => $this->node->id, 'name' => 'default', 'branch' => 't3code/retry'])->assertCreated();
});

it('retains the original create failure and recovery command when cleanup is incomplete', function (): void {
    $this->source->fail = 'resolve';
    $this->source->failureCode = 'instance.branch_resolution_failed';
    $this->removalSource->fail = 'prepare';
    $this->postJson('/api/v1/instances', ['project_id' => $this->orbitApp->id, 'node_id' => $this->node->id, 'name' => 'stuck', 'branch' => 't3code/12345678'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'instance.branch_resolution_failed')->assertJsonPath('error.details.cleanup', 'incomplete');
    $instance = Instance::query()->sole();
    expect($instance->status)->toBe(InstanceState::Removing);
    $this->removalSource->fail = null;
    $this->deleteJson('/api/v1/instances/'.$instance->id, ['force' => true])->assertOk();
    expect(Instance::query()->count())->toBe(0)->and(Route::query()->count())->toBe(0);
});

it('removes interrupted pre-activation checkouts with no failure record without teardown', function (string $state, string $routeStatus, bool $force): void {
    $this->postJson('/api/v1/instances', ['project_id' => $this->orbitApp->id, 'node_id' => $this->node->id, 'name' => 'dev'])->assertCreated();
    $instance = Instance::query()->sole();
    Route::query()->sole()->update([
        'status' => $routeStatus,
        'sites_published' => false,
        'failed_step' => $routeStatus === 'failed' ? 'source-resolve' : null,
        'error_code' => $routeStatus === 'failed' ? 'instance.branch_resolution_failed' : null,
    ]);
    $instance->update(['status' => $state, 'failed_step' => null, 'error_code' => null, 'starting_commit' => $state === 'source_resolved' ? $instance->starting_commit : null, 'branch' => $state === 'source_resolved' ? $instance->branch : null]);
    expect($instance->source_prepare_id)->not->toBeNull();
    ProjectLifecycleStep::query()->create(['project_id' => $this->orbitApp->id, 'phase' => 'teardown', 'name' => 'must-not-run', 'command' => 'exit 1', 'timeout_seconds' => 30, 'position' => 0]);
    $transport = new LifecycleSshExecutor;
    app()->instance(ProjectLifecycleRunner::class, $transport->runner());
    $this->deleteJson('/api/v1/instances/'.$instance->id, ['force' => $force])->assertOk();
    expect(Instance::query()->count())->toBe(0)->and(Route::query()->count())->toBe(0)->and($transport->inputs)->toBe([]);
})->with(['reserved', 'checkout_prepared', 'source_resolved'])->with(['pending', 'failed'])->with([false, true]);

it('rejects added removed or changed branch override on creation retry before mutation', function (
    ?string $original,
    ?string $retry,
): void {
    $this->source->resolution = new DevelopmentSourceResolution($original ?? 'dev', str_repeat('b', 40));
    $payload = [
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ];

    if ($original !== null) {
        $payload['branch'] = $original;
    }

    $this->postJson('/api/v1/instances', $payload)->assertCreated();
    $before = Instance::query()->sole()->getAttributes();
    $this->source->calls = [];

    if ($retry === null) {
        unset($payload['branch']);
    } else {
        $payload['branch'] = $retry;
    }

    $this
        ->postJson('/api/v1/instances', $payload)
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.placement_conflict');

    expect(Instance::query()->sole()->getAttributes())
        ->toBe($before)
        ->and($this->source->calls)
        ->toBe([]);
})->with([
    'changed' => ['release', 'hotfix'],
    'removed' => ['release', null],
    'added' => [null, 'release'],
]);

it('creates explicit Routes during provisioning and preserves exact retry identity', function (): void {
    $this->source->resolution = new DevelopmentSourceResolution('main', str_repeat('a', 40));
    $payload = [
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'default',
        'domain' => 'Preview.Example.Test',
    ];

    $created = $this->postJson('/api/v1/instances', $payload)->assertCreated();
    $route = Route::query()->sole();

    expect($route->getAttributes())
        ->toMatchArray([
            'domain' => 'preview.example.test',
            'provenance' => 'explicit',
            'generation_basis_node_id' => null,
            'status' => 'active',
            'failed_step' => null,
            'error_code' => null,
        ]);

    $this
        ->postJson('/api/v1/instances', $payload)
        ->assertOk()
        ->assertJsonPath('data.id', $created->json('data.id'));

    expect(Route::query()->count())->toBe(1);
});

it('refuses unavailable generated naming before source mutation and completes on retry', function (): void {
    $this->node->update(['tld' => null]);
    $payload = [
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ];

    $this
        ->postJson('/api/v1/instances', $payload)
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.tld_required');

    expect(Instance::query()->count())->toBe(0)
        ->and($this->source->calls)->toBe([])
        ->and(Route::query()->count())->toBe(0);

    $this->node->update(['tld' => 'test']);
    $this->postJson('/api/v1/instances', $payload)->assertCreated();

    expect(Route::query()->sole()->domain)->toBe('web.dev.acme.test');
});

it('uses the active Cluster TLD before the Node TLD while Cluster membership selects scope', function (): void {
    $this->source->resolution = new DevelopmentSourceResolution('main', str_repeat('a', 40));
    $cluster = Cluster::query()->create([
        'name' => 'routing',
        'state' => ClusterState::Active,
        'tld' => 'cluster.test',
    ]);
    $this->node->update(['cluster_id' => $cluster->id]);
    $this->node
        ->roles()
        ->create([
            'cluster_id' => $cluster->id,
            'role' => RoleName::Router,
            'status' => LifecycleStatus::Active,
        ]);

    $this->postJson('/api/v1/instances', [
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'default',
    ])->assertCreated();

    expect(Route::query()->sole()->domain)
        ->toBe('web.acme.cluster.test')
        ->and(Route::query()->sole()->cluster_id)
        ->toBe($cluster->id);

    Instance::query()->sole()->update(['status' => InstanceState::SourceResolved]);
    Route::query()->sole()->update(['status' => 'pending']);
    Route::query()->sole()->delete();
    Instance::query()->sole()->delete();
    $this->node->update(['tld' => null]);
    $this->source->resolution = new DevelopmentSourceResolution('feature', str_repeat('b', 40));

    $this->postJson('/api/v1/instances', [
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'feature',
    ])->assertCreated();

    expect(Route::query()->sole()->domain)
        ->toBe('web.feature.acme.cluster.test')
        ->and(Route::query()->sole()->cluster_id)
        ->toBe($cluster->id);
});

it('creates equivalent source on Nodes in every optional Cluster state', function (string $placement): void {
    if ($placement !== 'standalone') {
        $cluster = Cluster::query()->create([
            'name' => $placement,
            'state' => $placement === 'inactive' ? ClusterState::Inactive : ClusterState::Active,
            'tld' => $placement === 'active-with-tld' ? 'orbit' : null,
        ]);
        $this->node->update(['cluster_id' => $cluster->id]);

        if ($cluster->state === ClusterState::Active) {
            $this->node
                ->roles()
                ->create([
                    'cluster_id' => $cluster->id,
                    'role' => RoleName::Router,
                    'status' => LifecycleStatus::Active,
                ]);
        }
    }

    $this
        ->postJson('/api/v1/instances', [
            'project_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'dev',
        ])
        ->assertCreated()
        ->assertJsonMissingPath('data.cluster_id')
        ->assertJsonPath('data.source_layout', 'checkout')
        ->assertJsonPath('data.status', 'active');

    expect(Instance::query()->sole()->getAttributes())->not->toHaveKey('cluster_id');
})->with(['standalone', 'inactive', 'active-without-tld', 'active-with-tld']);

it('reconciles Cluster activation for an active Instance Route without moving placement', function (): void {
    $created = $this->postJson('/api/v1/instances', [
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();
    $before = Instance::query()->findOrFail($created->json('data.id'))->getAttributes();
    $this->source->calls = [];
    $cluster = Cluster::query()->create(['name' => 'routing', 'state' => ClusterState::Inactive]);
    $firstRouter = Node::query()->create([
        'cluster_id' => $cluster->id,
        'name' => 'router-one',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.20',
        'wireguard_ip' => '10.44.0.20',
    ]);
    $secondRouter = Node::query()->create([
        'cluster_id' => $cluster->id,
        'name' => 'router-two',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.21',
        'wireguard_ip' => '10.44.0.21',
    ]);
    app()->instance(RouteDomainProjector::class, Mockery::mock(RouteDomainProjector::class)->shouldIgnoreMissing());
    app()->instance(
        DevelopmentProjectionOperationLock::class,
        new class implements DevelopmentProjectionOperationLock
        {
            public function run(Closure $operation): mixed
            {
                return $operation();
            }
        },
    );

    $this->putJson("/api/v1/clusters/{$cluster->id}/nodes/{$this->node->id}")->assertOk();
    $this->putJson("/api/v1/clusters/{$cluster->id}/router/{$firstRouter->id}")->assertOk();
    $this->patchJson("/api/v1/clusters/{$cluster->id}", ['tld' => 'orbit'])->assertOk();
    $this
        ->patchJson("/api/v1/clusters/{$cluster->id}", ['state' => 'active'])
        ->assertOk()
        ->assertJsonPath('data.state', 'active');

    expect(Instance::query()->findOrFail($created->json('data.id'))->getAttributes())
        ->toBe($before)
        ->and($cluster->refresh()->state)
        ->toBe(ClusterState::Active)
        ->and(Route::query()->sole()->only(['status', 'node_id', 'cluster_id', 'domain']))
        ->toBe([
            'status' => RouteStatus::Active,
            'node_id' => null,
            'cluster_id' => $cluster->id,
            'domain' => 'web.dev.acme.orbit',
        ])
        ->and($firstRouter->roles()->where('role', RoleName::Router)->sole()->status)
        ->toBe(LifecycleStatus::Active)
        ->and($secondRouter->roles()->where('role', RoleName::Router)->exists())
        ->toBeFalse()
        ->and($this->source->calls)
        ->toBeEmpty();
});

it('renames a Cluster and accepts unchanged placement input despite an unrelated failed checkout', function (): void {
    $this->removalSource->fail = 'inspect';
    $this->source->fail = 'resolve';
    $this
        ->postJson('/api/v1/instances', [
            'project_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'failed',
        ])
        ->assertUnprocessable();
    $instanceBefore = Instance::query()->sole()->getAttributes();
    $routeBefore = Route::query()->sole()->getAttributes();
    $targetBefore = RouteTarget::query()->sole()->getAttributes();
    $cluster = Cluster::query()->create([
        'name' => 'routing',
        'state' => ClusterState::Inactive,
        'tld' => 'cluster',
    ]);

    $this
        ->patchJson("/api/v1/clusters/{$cluster->id}", ['name' => 'renamed'])
        ->assertOk()
        ->assertJsonPath('data.name', 'renamed');
    $this
        ->patchJson("/api/v1/clusters/{$cluster->id}", [
            'state' => 'inactive',
            'tld' => 'cluster',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'renamed');

    expect(Instance::query()->sole()->getAttributes())
        ->toBe($instanceBefore)
        ->and(Route::query()->sole()->getAttributes())
        ->toBe($routeBefore)
        ->and(RouteTarget::query()->sole()->getAttributes())
        ->toBe($targetBefore);
});

it('transports a root override and returns it as the effective root', function (): void {
    $this
        ->postJson('/api/v1/instances', [
            'project_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'dev',
            'root' => 'site/public',
        ])
        ->assertCreated()
        ->assertJsonPath('data.root', 'site/public')
        ->assertJsonPath('data.effective_root', 'site/public');
});

it('fails before mutation when a legacy App has incomplete source defaults', function (): void {
    $this->orbitApp->update(['default_branch' => null, 'root' => null]);

    $this
        ->postJson('/api/v1/instances', [
            'project_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'dev',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'project.source_defaults_incomplete');

    expect(Instance::query()->count())
        ->toBe(0)
        ->and($this->source->calls)
        ->toBeEmpty();
});

it('persists each durable state when cleanup is blocked and resumes the next transition', function (
    string $failure,
    InstanceState $durableState,
): void {
    $this->removalSource->fail = 'inspect';
    $payload = [
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ];
    $this->source->fail = $failure;

    $this->postJson('/api/v1/instances', $payload)->assertUnprocessable();
    expect(Instance::query()->sole()->status)->toBe($durableState);
    $this->source->fail = null;

    $this
        ->postJson('/api/v1/instances', $payload)
        ->assertOk()
        ->assertJsonPath('data.status', 'active');
    expect(Instance::query()->count())->toBe(1);

    if ($failure === 'prepare') {
        expect($this->source->prepareExisting)->toBe([false, true]);
    }
})->with([
    'reserved' => ['prepare', InstanceState::Reserved],
    'checkout prepared' => ['resolve', InstanceState::CheckoutPrepared],
    'source resolved' => ['inspect-resolved', InstanceState::SourceResolved],
]);

it('keeps a failed attempt from overwriting a successful retry after lease release', function (): void {
    $this->removalSource->fail = 'inspect';
    $data = new CreateInstanceData(
        projectId: $this->orbitApp->id,
        nodeId: $this->node->id,
        name: 'dev',
        root: null,
        domain: null,
        branch: null,
    );
    $native = app(DevelopmentInstanceProvisioner::class);
    $provisioner = new class($native) implements DevelopmentInstanceProvisioner
    {
        public int $completions = 0;

        public function __construct(
            private readonly DevelopmentInstanceProvisioner $native,
        ) {}

        public function reserve(Instance $instance, ?string $domain): void
        {
            $this->native->reserve($instance, $domain);
        }

        public function complete(
            Instance $instance,
            ?string $domain,
            bool $setupPending = false,
        ): Instance {
            $this->completions++;

            if ($this->completions === 1) {
                throw new ResourceOperationException('instance.first_attempt_failed', 'The first attempt failed.');
            }

            return $this->native->complete($instance, $domain, setupPending: $setupPending);
        }
    };
    app()->instance(DevelopmentInstanceProvisioner::class, $provisioner);
    $lock = new class($data) implements AppDevSourceOperationLock
    {
        public int $leases = 0;

        private bool $held = false;

        public function __construct(
            private readonly CreateInstanceData $data,
        ) {}

        public function synchronized(int $nodeId, Closure $operation): mixed
        {
            if ($this->held) {
                return $operation();
            }
            $this->held = true;
            $this->leases++;

            try {
                return $operation();
            } catch (Throwable $exception) {
                $this->held = false;
                if ($this->leases === 1) {
                    app(CreateInstanceAction::class)->execute($this->data);
                }

                throw $exception;
            } finally {
                $this->held = false;
            }
        }
    };
    app()->instance(AppDevSourceOperationLock::class, $lock);

    expect(fn () => app(CreateInstanceAction::class)->execute($data))
        ->toThrow(ResourceOperationException::class, 'cleanup is incomplete');

    expect($lock->leases)
        ->toBe(2)
        ->and($provisioner->completions)
        ->toBe(2)
        ->and(Instance::query()->sole()->only(['status', 'failed_step', 'error_code']))
        ->toBe([
            'status' => InstanceState::Active,
            'failed_step' => null,
            'error_code' => null,
        ])
        ->and(Route::query()->sole()->only(['status', 'failed_step', 'error_code']))
        ->toBe([
            'status' => RouteStatus::Active,
            'failed_step' => null,
            'error_code' => null,
        ]);
});

it('persists unexpected provisioning failures when guarded cleanup cannot complete', function (): void {
    $this->removalSource->fail = 'inspect';
    $native = app(DevelopmentInstanceProvisioner::class);
    app()->instance(DevelopmentInstanceProvisioner::class, new class($native) implements DevelopmentInstanceProvisioner
    {
        public function __construct(
            private readonly DevelopmentInstanceProvisioner $native,
        ) {}

        public function reserve(Instance $instance, ?string $domain): void
        {
            $this->native->reserve($instance, $domain);
        }

        public function complete(
            Instance $instance,
            ?string $domain,
            bool $setupPending = false,
        ): Instance {
            throw new LogicException('Unexpected provisioning failure.');
        }
    });

    expect(fn () => app(CreateInstanceAction::class)->execute(new CreateInstanceData(
        projectId: $this->orbitApp->id,
        nodeId: $this->node->id,
        name: 'dev',
        root: null,
        domain: null,
        branch: null,
    )))
        ->toThrow(ResourceOperationException::class, 'cleanup is incomplete');

    expect(Instance::query()->sole()->only(['status', 'failed_step', 'error_code']))
        ->toBe([
            'status' => InstanceState::SourceResolved,
            'failed_step' => 'provisioning',
            'error_code' => 'instance.provisioning_failed',
        ])
        ->and(Route::query()->sole()->only(['status', 'failed_step', 'error_code']))
        ->toBe([
            'status' => RouteStatus::Failed,
            'failed_step' => 'provisioning',
            'error_code' => 'instance.provisioning_failed',
        ]);
});

it('keeps reserved recovery state when both source acquisition and cleanup are unavailable', function (): void {
    $provisioner = new class implements DevelopmentInstanceProvisioner
    {
        public int $reservations = 0;

        public function reserve(Instance $instance, ?string $domain): void
        {
            $this->reservations++;
        }

        public function complete(
            Instance $instance,
            ?string $domain,
            bool $setupPending = false,
        ): Instance {
            return $instance;
        }
    };
    app()->instance(DevelopmentInstanceProvisioner::class, $provisioner);
    app()->instance(AppDevSourceOperationLock::class, new class implements AppDevSourceOperationLock
    {
        public function synchronized(int $nodeId, Closure $operation): mixed
        {
            throw new RuntimeException('Lease acquisition failed.');
        }
    });

    expect(fn () => app(CreateInstanceAction::class)->execute(new CreateInstanceData(
        projectId: $this->orbitApp->id,
        nodeId: $this->node->id,
        name: 'dev',
        root: null,
        domain: null,
        branch: null,
    )))
        ->toThrow(fn (ResourceOperationException $e) => expect($e->details['cleanup'])->toBe('incomplete'));

    expect($provisioner->reservations)
        ->toBe(0)
        ->and(Route::query()->count())
        ->toBe(0)
        ->and(Instance::query()->sole()->only(['status', 'failed_step', 'error_code']))
        ->toBe([
            'status' => InstanceState::Reserved,
            'failed_step' => null,
            'error_code' => null,
        ]);
});

it('persists reservation conflicts before releasing the lease when cleanup cannot complete', function (): void {
    $this->removalSource->fail = 'inspect';
    $lock = new class implements AppDevSourceOperationLock
    {
        public bool $held = false;

        public bool $failurePersistedWhileHeld = false;

        public function synchronized(int $nodeId, Closure $operation): mixed
        {
            $this->held = true;

            try {
                return $operation();
            } catch (Throwable $exception) {
                $instance = Instance::query()->sole();
                $this->failurePersistedWhileHeld =
                    $instance->failed_step === 'source-prepare' && $instance->error_code === 'route.hostname_taken';

                throw $exception;
            } finally {
                $this->held = false;
            }
        }
    };
    $provisioner = new class($lock) implements DevelopmentInstanceProvisioner
    {
        public bool $reservedWhileHeld = false;

        public function __construct(
            private readonly AppDevSourceOperationLock $lock,
        ) {}

        public function reserve(Instance $instance, ?string $domain): void
        {
            $this->reservedWhileHeld = $this->lock->held;

            throw new ResourceOperationException('route.hostname_taken', 'The hostname is unavailable.', 409);
        }

        public function complete(
            Instance $instance,
            ?string $domain,
            bool $setupPending = false,
        ): Instance {
            return $instance;
        }
    };
    app()->instance(AppDevSourceOperationLock::class, $lock);
    app()->instance(DevelopmentInstanceProvisioner::class, $provisioner);

    expect(fn () => app(CreateInstanceAction::class)->execute(new CreateInstanceData(
        projectId: $this->orbitApp->id,
        nodeId: $this->node->id,
        name: 'dev',
        root: null,
        domain: 'dev.example.test',
        branch: null,
    )))
        ->toThrow(ResourceOperationException::class, 'cleanup is incomplete');

    expect($provisioner->reservedWhileHeld)
        ->toBeTrue()
        ->and($lock->failurePersistedWhileHeld)
        ->toBeTrue()
        ->and($lock->held)
        ->toBeFalse()
        ->and(Instance::query()->sole()->only(['status', 'failed_step', 'error_code']))
        ->toBe([
            'status' => InstanceState::Reserved,
            'failed_step' => 'source-prepare',
            'error_code' => 'route.hostname_taken',
        ]);
});

it('rejects a retry on another Node before source work or state mutation', function (): void {
    $this->postJson('/api/v1/instances', [
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();
    $otherNode = Node::query()->create([
        'name' => 'other-app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'wireguard_ip' => '10.44.0.4',
        'user' => 'orbit',
    ]);
    $otherNode->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $before = Instance::query()->sole()->getAttributes();
    $this->source->calls = [];

    $this
        ->postJson('/api/v1/instances', [
            'project_id' => $this->orbitApp->id,
            'node_id' => $otherNode->id,
            'name' => 'dev',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.placement_conflict');

    expect(Instance::query()->sole()->getAttributes())
        ->toBe($before)
        ->and($this->source->calls)
        ->toBeEmpty();
});

it('rejects inactive Node role and unsupported platform placement before mutation', function (string $invalid): void {
    if ($invalid === 'node') {
        $this->node->update(['status' => LifecycleStatus::Failed]);
    } elseif ($invalid === 'role') {
        $this->node->roles()->delete();
    } else {
        $this->node->update(['platform' => 'darwin']);
    }

    $this
        ->postJson('/api/v1/instances', [
            'project_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'dev',
        ])
        ->assertUnprocessable();

    expect(Instance::query()->count())
        ->toBe(0)
        ->and($this->source->calls)
        ->toBeEmpty();
})->with(['node', 'role', 'platform']);

it('keeps the first checkout immutable when a later Instance uses a changed apps root', function (): void {
    $first = $this->postJson('/api/v1/instances', [
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();
    $this->node->update(['settings' => ['apps' => ['path' => '/mnt/orbit/apps']]]);
    $this->source->resolution = new DevelopmentSourceResolution('feature', str_repeat('b', 40));

    $second = $this->postJson('/api/v1/instances', [
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'feature',
    ])->assertCreated();

    expect($first->json('data.checkout_path'))
        ->toBe('/srv/orbit/apps/acme/dev')
        ->and($second->json('data.checkout_path'))
        ->toBe('/mnt/orbit/apps/acme/feature')
        ->and(Instance::query()->findOrFail($first->json('data.id'))->checkout_path)
        ->toBe('/srv/orbit/apps/acme/dev');
});

it('rejects immutable root and source-layout conflicts on retry', function (string $conflict): void {
    $payload = [
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ];
    $this->postJson('/api/v1/instances', $payload)->assertCreated();
    $this->source->calls = [];

    if ($conflict === 'root') {
        $payload['root'] = 'other/public';
    } else {
        Instance::query()->sole()->update(['source_layout' => 'worktree']);
    }
    $before = Instance::query()->sole()->getAttributes();

    $this
        ->postJson('/api/v1/instances', $payload)
        ->assertConflict();

    expect(Instance::query()->sole()->getAttributes())->toBe($before);

    expect($this->source->calls)->toBeEmpty();
})->with(['root', 'source layout']);

it('refuses a default path occupied by another managed Instance before mutation', function (): void {
    Instance::query()->create([
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'occupied',
        'checkout_path' => '/srv/orbit/apps/acme/default',
        'root' => 'public',
        'status' => InstanceState::Active,
    ]);

    $this
        ->postJson('/api/v1/instances', [
            'project_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'default',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.default_path_occupied');

    expect(Instance::query()->count())
        ->toBe(1)
        ->and(Instance::query()->sole()->name)
        ->toBe('occupied')
        ->and($this->source->calls)
        ->toBe([]);
});

it('refuses a default path occupied by an unmanaged directory before mutation', function (): void {
    $this->destination->occupied = true;

    $this
        ->postJson('/api/v1/instances', [
            'project_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'default',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.default_path_occupied')
        ->assertJsonPath('error.message', 'Instance destination is occupied by unmanaged data.');

    expect(Instance::query()->count())
        ->toBe(0)
        ->and($this->source->calls)
        ->toBe([]);
});

it('treats active creation evidence as terminal when development HEAD advances', function (): void {
    $payload = [
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ];
    $created = $this->postJson('/api/v1/instances', $payload)->assertCreated();
    $before = Instance::query()->sole()->getAttributes();
    $this->source->calls = [];
    $this->source->resolution = new DevelopmentSourceResolution('dev', str_repeat('b', 40));

    $this
        ->postJson('/api/v1/instances', $payload)
        ->assertOk()
        ->assertJsonPath('data.id', $created->json('data.id'))
        ->assertJsonPath('data.starting_commit', str_repeat('a', 40));

    expect(Instance::query()->sole()->getAttributes())
        ->toBe($before)
        ->and($this->source->calls)
        ->toBe(['inspect-prepared:active']);
});

it('leaves an active Instance row unchanged when a creation retry is refused with route.retry_conflict', function (): void {
    $payload = [
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ];
    $this->postJson('/api/v1/instances', $payload)->assertCreated();
    $before = Instance::query()->sole()->getAttributes();
    $routeBefore = Route::query()->sole()->getAttributes();
    $this->travelTo(now()->addMinute());

    $this
        ->postJson('/api/v1/instances', [...$payload, 'domain' => 'x.orbit'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'route.retry_conflict');

    expect(Instance::query()->sole()->getAttributes())
        ->toBe($before)
        ->and($before['failed_step'])
        ->toBeNull()
        ->and($before['error_code'])
        ->toBeNull()
        ->and(Route::query()->sole()->getAttributes())
        ->toBe($routeBefore);
});

it('rejects repository execution and unsupported transport keys', function (): void {
    $this
        ->postJson('/api/v1/instances', [
            'project_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'dev',
            'repository_url' => 'https://github.com/acme/other.git',
            'cluster_id' => 1,
            'source_layout' => 'checkout',
            'checkout_path' => '/tmp/acme',
            'command' => 'id',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect(Instance::query()->count())
        ->toBe(0)
        ->and($this->source->calls)
        ->toBeEmpty();
});

it('completes Instance removal while another Node service metrics snapshot fails', function (): void {
    // The creation request resolves singletons used by the native removal projector.
    app()->instance(SshExecutor::class, new AppDevFakeSshExecutor);
    $processes = Mockery::mock(ProcessRunner::class);
    $processes->shouldReceive('run')->twice()->andReturn(new CommandResult(0, '', '', 1, false));
    app()->instance(ProcessRunner::class, $processes);

    $created = $this->postJson('/api/v1/instances', [
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();
    $id = $created->json('data.id');
    $this->node->update(['ssh_host_fingerprint' => 'SHA256:metrics-proof']);
    $metrics = activate_metrics_role();
    $metrics->update(['ssh_host_fingerprint' => 'SHA256:metrics-proof']);
    $failed = create_app_prod_node('failed-service-metrics');
    $failed->update(['ssh_host_fingerprint' => 'SHA256:metrics-proof']);
    $snapshots = [];
    $converged = [];
    $services = Mockery::mock(ServiceMetricsRuntime::class);
    $services->shouldReceive('snapshot')->andReturnUsing(function (ServiceMetricsNode $target) use ($failed, &$snapshots): string {
        $snapshots[] = $target->node->id;
        if ($target->node->is($failed)) {
            throw new ResourceOperationException('metrics.service_convergence_failed', 'Service metrics command failed.', 502);
        }

        return '{}';
    });
    $services->shouldReceive('converge')->andReturnUsing(function (ServiceMetricsNode $target) use (&$converged): void {
        $converged[] = $target->node->id;
    });
    $exporters = Mockery::mock(MetricsExporterLifecycle::class);
    $cadvisors = Mockery::mock(MetricsCadvisorLifecycle::class);
    $publication = Mockery::mock(MetricsRuntimeLifecycle::class);
    $exporters->shouldReceive('converge')->twice();
    $cadvisors->shouldReceive('converge')->twice();
    $publication->shouldReceive('converge')->twice();
    $fleet = new NativeMetricsFleetReconciler($exporters, $cadvisors, $publication, new NativeServiceMetricsLifecycle(
        app(ServiceMetricsProjection::class), $services, app(ExporterDegradationRepository::class),
    ));
    app()->instance(MetricsFleetReconciler::class, $fleet);
    app()->instance(InstanceRemovalProjector::class, app(NativeInstanceRemovalProjector::class));

    $this->deleteJson("/api/v1/instances/{$id}")->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.completed', 1)
        ->assertJsonPath('data.remaining', 0);

    $this->assertDatabaseMissing('instances', ['id' => $id]);
    expect(Route::query()->count())->toBe(0);
    expect(InstanceRemoval::query()->sole()->status->value)->toBe('completed');
    expect($snapshots)->toContain($failed->id);
    expect($converged)->toContain($this->node->id, $metrics->id)->not->toContain($failed->id);
    expect(app(ExporterDegradationRepository::class)->get($failed->id))->toBe(ExporterDegradationReason::ReconcileFailed);
    expect(app(ExporterDegradationRepository::class)->step($failed->id))->toBe('snapshot');
    expect(app(MetricsReconcileDegradationRepository::class)->errorCode($failed->id))->toBe('metrics.service_convergence_failed');
});

it('removes an active Instance through every durable checkpoint', function (bool $force): void {
    $created = $this->postJson('/api/v1/instances', [
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();
    $this->source->calls = [];

    $this
        ->deleteJson("/api/v1/instances/{$created->json('data.id')}", [
            'force' => $force,
        ])
        ->assertOk()
        ->assertJsonPath('data.id', $created->json('data.id'))
        ->assertJsonPath('data.name', 'dev')
        ->assertJsonPath('data.force', $force)
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.current_step', null)
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.completed', 1)
        ->assertJsonPath('data.remaining', 0)
        ->assertJsonPath('data.failed_step', null)
        ->assertJsonPath('data.error_code', null);

    $activity = Activity::query()->where('command', 'instance:destroy')->sole();
    expect(Instance::query()->count())
        ->toBe(0)
        ->and(RouteTarget::query()->count())
        ->toBe(0)
        ->and(Route::query()->count())
        ->toBe(0)
        ->and($this->removalSource->calls)
        ->toBe(array_merge(
            ["inspect:{$created->json('data.id')}"],
            $force ? [] : ["inspect:{$created->json('data.id')}"],
            [
                "prepare:{$created->json('data.id')}",
                "revalidate:{$created->json('data.id')}",
                "finalize:{$created->json('data.id')}",
            ],
        ))
        ->and($this->source->calls)
        ->toBeEmpty()
        ->and($activity->subject_type)
        ->toBe('instance')
        ->and($activity->subject_id)
        ->toBe($created->json('data.id'))
        ->and($activity->target_node_id)
        ->toBe($this->node->id)
        ->and($activity->properties?->get('removal'))
        ->toMatchArray([
            'id' => $created->json('data.id'),
            'name' => 'dev',
            'force' => $force,
            'status' => 'completed',
            'current_step' => null,
            'total' => 1,
            'completed' => 1,
            'remaining' => 0,
            'failed_step' => null,
            'error_code' => null,
        ])
        ->not->toHaveKeys(['checkout_path', 'source_digest', 'finalization_receipt']);
})->with([false, true]);

it('answers the refused source identity check with 409 in normal and forced removal', function (bool $force): void {
    $created = $this->postJson('/api/v1/instances', [
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();
    $this->removalSource->inspectionFailures[$created->json('data.id')] = 'instance.source_origin_mismatch';

    $this
        ->deleteJson("/api/v1/instances/{$created->json('data.id')}", ['force' => $force])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.source_origin_mismatch')
        ->assertJsonPath('error.message', 'Instance [dev] source origin does not match the Project repository.');
    expect(InstanceRemovalMember::query()->count())
        ->toBe(0)
        ->and(Instance::query()->count())
        ->toBe(1)
        ->and(Route::query()->count())
        ->toBe(1);
})->with([
    'normal removal' => false,
    'force' => true,
]);

it('refuses normal checkout cascade with force guidance and reports forced bounded totals', function (): void {
    [$checkout, $first, $second, $paths] = orb182_api_removal_graph($this);

    $this
        ->deleteJson("/api/v1/instances/{$checkout->id}")
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.remove_refused')
        ->assertJsonPath('error.message', 'The checkout has registered linked worktrees; retry with --force.');
    expect(Instance::query()->count())->toBe(3)->and(Route::query()->count())->toBe(3);

    $this
        ->deleteJson("/api/v1/instances/{$checkout->id}", ['force' => true])
        ->assertOk()
        ->assertJsonPath('data.id', $checkout->id)
        ->assertJsonPath('data.force', true)
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.total', 3)
        ->assertJsonPath('data.completed', 3)
        ->assertJsonPath('data.remaining', 0);
    $members = InstanceRemovalMember::query()->orderBy('position')->get();
    expect($members->pluck('instance_id')->all())
        ->toBe([$first->id, $second->id, $checkout->id])
        ->and($members->every(fn (InstanceRemovalMember $member): bool => $member->linked_worktree_paths === $paths))
        ->toBeTrue()
        ->and(Instance::query()->count())
        ->toBe(0)
        ->and(Route::query()->count())
        ->toBe(0);
});

it('refuses unregistered checkout inventory in normal and forced modes without mutation', function (bool $force): void {
    [$checkout, , , $paths] = orb182_api_removal_graph($this);
    $paths[] = '/srv/orbit/apps/acme/unregistered';
    sort($paths, SORT_STRING);
    $this->removalSource->livePaths = $paths;
    $this->removalSource->linkedPaths[$checkout->id] = $paths;

    $this
        ->deleteJson("/api/v1/instances/{$checkout->id}", ['force' => $force])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.remove_refused')
        ->assertJsonPath(
            'error.message',
            'Every linked worktree must be a registered Instance before removal.',
        );
    expect(InstanceRemovalMember::query()->count())
        ->toBe(0)
        ->and(Instance::query()->count())
        ->toBe(3)
        ->and(Route::query()->count())
        ->toBe(3);
})->with([false, true]);

it('reports retained fixed-set progress and refuses a new source before retry advances', function (): void {
    [$checkout, $first, $second, $paths] = orb182_api_removal_graph($this);
    $this->removalSource->failPrepareFor = $second->id;

    $this
        ->deleteJson("/api/v1/instances/{$checkout->id}", ['force' => true])
        ->assertStatus(502)
        ->assertJsonPath('error.details.removal.total', 3)
        ->assertJsonPath('error.details.removal.completed', 1)
        ->assertJsonPath('error.details.removal.remaining', 2)
        ->assertJsonPath('error.details.removal.current_step', 'source_preparation');
    $operation = $checkout->refresh()->removalMember?->removal;
    $secondMember = $operation?->members()->where('instance_id', $second->id)->sole();
    $paths[] = '/srv/orbit/apps/acme/new-worktree';
    sort($paths, SORT_STRING);
    $this->removalSource->livePaths = $paths;
    $this->removalSource->failPrepareFor = null;

    $this
        ->deleteJson("/api/v1/instances/{$checkout->id}", ['force' => true])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.removal_conflict')
        ->assertJsonPath('error.details.removal.total', 3)
        ->assertJsonPath('error.details.removal.completed', 1)
        ->assertJsonPath('error.details.removal.remaining', 2);
    expect($operation?->members()->count())
        ->toBe(3)
        ->and($secondMember?->refresh()->route_cleared_at)
        ->toBeNull()
        ->and(
            Route::query()
                ->whereHas('targets', fn ($query) => $query->where(
                    'instance_id',
                    $second->id,
                ))
                ->exists(),
        )
        ->toBeTrue();
});

it('removes one target from a public clustered production Route and reports retained progress', function (): void {
    $cluster = Cluster::query()->create([
        'name' => 'production-removal',
        'state' => ClusterState::Active,
    ]);
    $instances = collect(['one', 'two'])->map(function (string $name) use ($cluster): Instance {
        $suffix = $name === 'one' ? '91' : '92';
        $node = Node::query()->create([
            'cluster_id' => $cluster->id,
            'name' => "app-prod-{$name}",
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => "192.0.2.{$suffix}",
            'wireguard_ip' => "10.44.0.{$suffix}",
        ]);
        $node->roles()->create([
            'role' => RoleName::AppProd,
            'status' => LifecycleStatus::Active,
        ]);

        return Instance::query()->create([
            'project_id' => $this->orbitApp->id,
            'node_id' => $node->id,
            'name' => $name,
            'environment' => 'production',
            'checkout_path' => "/var/www/acme/{$name}",
            'branch' => 'main',
            'starting_commit' => str_repeat('a', 40),
            'status' => InstanceState::SourceResolved,
        ]);
    });
    $route = Route::query()->create([
        'project_id' => $this->orbitApp->id,
        'cluster_id' => $cluster->id,
        'domain' => 'production.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Public,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instances[0]->id, 'position' => 0]);
    $route->targets()->create(['instance_id' => $instances[1]->id, 'position' => 1]);
    $route->update(['status' => RouteStatus::Active]);
    $instances->each(static fn (Instance $instance) => $instance->update([
        'status' => InstanceState::Active,
    ]));

    $this
        ->deleteJson("/api/v1/instances/{$instances[0]->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $instances[0]->id)
        ->assertJsonPath('data.name', 'one')
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.completed', 1)
        ->assertJsonPath('data.remaining', 0);

    expect(Instance::query()->pluck('id')->all())
        ->toBe([$instances[1]->id])
        ->and($route->refresh()->status)
        ->toBe(RouteStatus::Active)
        ->and($route->targets()->sole()->instance_id)
        ->toBe($instances[1]->id)
        ->and($route->targets()->sole()->position)
        ->toBe(0)
        ->and(InstanceRemovalMember::query()->sole()->route_outcome)
        ->toBe('retained')
        ->and($this->removalSource->calls)
        ->toBeEmpty();
});

it('keeps preflight refusals free of Route source and lifecycle mutation', function (): void {
    $created = $this->postJson('/api/v1/instances', [
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();
    $route = Route::query()->sole();
    $routeBefore = $route->toArray();
    $this->removalSource->fail = 'inspect';

    $response = $this->deleteJson("/api/v1/instances/{$created->json('data.id')}");

    $response
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'instance.source_interrupted')
        ->assertJsonPath('error.details', []);
    expect(Instance::query()->count())
        ->toBe(1)
        ->and(Instance::query()->sole()->status)
        ->toBe(InstanceState::Active)
        ->and(RouteTarget::query()->count())
        ->toBe(1)
        ->and($route->refresh()->toArray())
        ->toBe($routeBefore);
});

it('retains completed transfer history without leaving an instance reference', function (): void {
    $created = $this->postJson('/api/v1/instances', [
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();
    $instanceId = $created->json('data.id');

    InstanceTransfer::query()->create([
        'instance_id' => $instanceId,
        'source_node_id' => $this->node->id,
        'destination_node_id' => $this->node->id,
        'destination_name' => 'dev',
        'destination_path' => '/srv/orbit/apps/dev',
        'destination_domain' => 'dev.example.test',
        'source_layout' => 'checkout',
        'source_path' => '/srv/orbit/apps/dev',
        'source_route_id' => 1,
        'status' => InstanceTransferStatus::Completed,
        'current_step' => InstanceTransferStep::Completed,
        'completed_at' => now(),
    ]);

    $this->deleteJson("/api/v1/instances/{$instanceId}")->assertOk();

    expect(Instance::query()->whereKey($instanceId)->exists())->toBeFalse()
        ->and(InstanceTransfer::query()->sole()->instance_id)->toBeNull();
});

it('refuses removal while transfer history is incomplete', function (): void {
    $created = $this->postJson('/api/v1/instances', [
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();
    $instanceId = $created->json('data.id');

    InstanceTransfer::query()->create([
        'instance_id' => $instanceId,
        'source_node_id' => $this->node->id,
        'destination_node_id' => $this->node->id,
        'destination_name' => 'dev',
        'destination_path' => '/srv/orbit/apps/dev',
        'destination_domain' => 'dev.example.test',
        'source_layout' => 'checkout',
        'source_path' => '/srv/orbit/apps/dev',
        'source_route_id' => 1,
        'status' => InstanceTransferStatus::Failed,
        'current_step' => InstanceTransferStep::SourcePaused,
        'failed_step' => InstanceTransferStep::SourcePaused,
        'error_code' => 'instance.transfer_failed',
    ]);

    $this->deleteJson("/api/v1/instances/{$instanceId}")
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.transfer_incomplete');

    expect(Instance::query()->whereKey($instanceId)->firstOrFail()->status)
        ->toBe(InstanceState::Active);
});

it('retains bounded failed progress and resumes without recreating a deleted Route', function (): void {
    $created = $this->postJson('/api/v1/instances', [
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();
    $id = $created->json('data.id');
    $this->removalProjector->fail = 'runtime';

    $this
        ->deleteJson("/api/v1/instances/{$id}")
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'instance.runtime_interrupted')
        ->assertJsonPath('error.details.removal.id', $id)
        ->assertJsonPath('error.details.removal.force', false)
        ->assertJsonPath('error.details.removal.status', 'failed')
        ->assertJsonPath('error.details.removal.current_step', 'runtime_cleanup')
        ->assertJsonPath('error.details.removal.total', 1)
        ->assertJsonPath('error.details.removal.completed', 0)
        ->assertJsonPath('error.details.removal.remaining', 1)
        ->assertJsonPath('error.details.removal.failed_step', 'runtime_cleanup')
        ->assertJsonPath('error.details.removal.error_code', 'instance.runtime_interrupted');

    expect(Instance::query()->count())
        ->toBe(1)
        ->and(Instance::query()->sole()->status)
        ->toBe(InstanceState::Removing)
        ->and(Route::query()->count())
        ->toBe(0);

    $this
        ->getJson("/api/v1/instances/{$id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'removing')
        ->assertJsonPath('data.removal.failed_step', 'runtime_cleanup');

    $failedActivity = Activity::query()->where('command', 'instance:destroy')->latest('id')->firstOrFail();
    expect($failedActivity->status)
        ->toBe('failed')
        ->and($failedActivity->error_code)
        ->toBe('instance.runtime_interrupted')
        ->and($failedActivity->subject_type)
        ->toBe('instance')
        ->and($failedActivity->subject_id)
        ->toBe($id)
        ->and($failedActivity->target_node_id)
        ->toBe($this->node->id)
        ->and($failedActivity->properties?->get('removal'))
        ->toMatchArray([
            'id' => $id,
            'force' => false,
            'status' => 'failed',
            'current_step' => 'runtime_cleanup',
            'total' => 1,
            'completed' => 0,
            'remaining' => 1,
            'failed_step' => 'runtime_cleanup',
            'error_code' => 'instance.runtime_interrupted',
        ]);

    $this
        ->postJson('/api/v1/instances', [
            'project_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'dev',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.removal_conflict');

    $this->removalProjector->fail = null;
    $this
        ->deleteJson("/api/v1/instances/{$id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.completed', 1);

    expect(Route::query()->count())->toBe(0);
});

it('atomically completes final row deletion or preserves the public retry target', function (): void {
    $created = $this->postJson('/api/v1/instances', [
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();
    $id = $created->json('data.id');
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER orb124_fail_final_completion
        BEFORE UPDATE OF status ON instance_removals
        WHEN NEW.status = 'completed'
        BEGIN
            SELECT RAISE(ABORT, 'Injected final completion failure.');
        END
        SQL);

    try {
        $this
            ->deleteJson("/api/v1/instances/{$id}")
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'instance.removal_incomplete')
            ->assertJsonPath('error.details.removal.id', $id)
            ->assertJsonPath('error.details.removal.status', 'failed')
            ->assertJsonPath('error.details.removal.current_step', 'row_deletion')
            ->assertJsonPath('error.details.removal.completed', 0)
            ->assertJsonPath('error.details.removal.remaining', 1)
            ->assertJsonPath('error.details.removal.failed_step', 'row_deletion');
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS orb124_fail_final_completion');
    }

    $member = InstanceRemovalMember::query()->sole();
    expect(Instance::query()->whereKey($id)->sole()->status)
        ->toBe(InstanceState::Removing)
        ->and($member->row_deleted_at)
        ->toBeNull()
        ->and($member->removal()->firstOrFail()->status->value)
        ->toBe('failed')
        ->and(Route::query()->count())
        ->toBe(0)
        ->and($this->removalProjector->calls)
        ->toBe(["route:{$id}", "runtime:{$id}"]);

    $this
        ->deleteJson("/api/v1/instances/{$id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.completed', 1)
        ->assertJsonPath('data.remaining', 0);

    expect(Instance::query()->whereKey($id)->exists())
        ->toBeFalse()
        ->and($member->refresh()->row_deleted_at)
        ->not
        ->toBeNull()
        ->and($member->removal()->firstOrFail()->status->value)
        ->toBe('completed')
        ->and(Route::query()->count())
        ->toBe(0)
        ->and($this->removalProjector->calls)
        ->toBe(["route:{$id}", "runtime:{$id}"]);
});

it('returns current bounded progress when retry source revalidation is refused', function (): void {
    $created = $this->postJson('/api/v1/instances', [
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();
    $id = $created->json('data.id');
    $this->removalProjector->fail = 'route';

    $this
        ->deleteJson("/api/v1/instances/{$id}")
        ->assertStatus(502)
        ->assertJsonPath('error.details.removal.current_step', 'route_target_clear')
        ->assertJsonPath('error.details.removal.error_code', 'instance.runtime_interrupted');

    $route = Route::query()->sole();
    $this
        ->putJson("/api/v1/routes/{$route->id}/target", ['instance_id' => $id])
        ->assertOk()
        ->assertJsonPath('data.target.instance_id', $id);

    $this->removalProjector->fail = null;
    $this->removalSource->fail = 'revalidate';

    $this
        ->deleteJson("/api/v1/instances/{$id}")
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.removal_conflict')
        ->assertJsonPath('error.details.removal.id', $id)
        ->assertJsonPath('error.details.removal.status', 'failed')
        ->assertJsonPath('error.details.removal.current_step', 'route_target_clear')
        ->assertJsonPath('error.details.removal.completed', 0)
        ->assertJsonPath('error.details.removal.remaining', 1)
        ->assertJsonPath('error.details.removal.failed_step', 'route_target_clear')
        ->assertJsonPath('error.details.removal.error_code', 'instance.removal_conflict');

    $operation = Instance::query()->findOrFail($id)->removalMember?->removal;
    $activity = Activity::query()->where('command', 'instance:destroy')->latest('id')->firstOrFail();
    expect(Instance::query()->findOrFail($id)->status)
        ->toBe(InstanceState::Removing)
        ->and($operation?->current_step?->value)
        ->toBe('route_target_clear')
        ->and($operation?->error_code)
        ->toBe('instance.removal_conflict')
        ->and($activity->properties?->get('removal'))
        ->toMatchArray([
            'id' => $id,
            'status' => 'failed',
            'current_step' => 'route_target_clear',
            'failed_step' => 'route_target_clear',
            'error_code' => 'instance.removal_conflict',
        ]);
});

it('rejects a non-empty JSON array from the removal transport', function (): void {
    $created = $this->postJson('/api/v1/instances', [
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();
    $this->source->calls = [];

    $this
        ->call(
            'DELETE',
            "/api/v1/instances/{$created->json('data.id')}",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            content: '[false]',
        )
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect(Instance::query()->count())
        ->toBe(1)
        ->and($this->source->calls)
        ->toBeEmpty();
});

it('rejects the removed compatibility key', function (): void {
    $created = $this->postJson('/api/v1/instances', [
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'dev',
    ])->assertCreated();

    $this
        ->deleteJson("/api/v1/instances/{$created->json('data.id')}", [
            'discard'.'_source' => true,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect(Instance::query()->sole()->status)->toBe(InstanceState::Active);
});

it('updates the production deployment branch without changing deploy steps', function (): void {
    [$caller, , , $instance] = deployment_api_fixture();
    store_deploy_steps($instance, [
        ['name' => 'migrate', 'phase' => 'before_activation', 'command' => 'migrate', 'timeout_seconds' => 30],
    ]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->patchJson("/api/v1/instances/{$instance->id}", ['branch' => 'release/next'])
        ->assertOk()
        ->assertJsonPath('data.deploy_steps.0.name', 'migrate');

    expect($instance->fresh()->deployment_branch)->toBe('release/next')
        ->and(normalized_deploy_steps($instance->fresh()))->toHaveCount(1);

    $instance->node->roles()->delete();
    $instance->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->patchJson("/api/v1/instances/{$instance->id}", ['branch' => 'other'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'deployment_config.unavailable');
});

/**
 * @return array{Instance, Instance, Instance, list<string>}
 */
function orb182_api_removal_graph(TestCase $test): array
{
    $instances = [];

    foreach (['default', 'worktree-a', 'worktree-b'] as $position => $name) {
        $instance = Instance::query()->create([
            'project_id' => $test->orbitApp->id,
            'node_id' => $test->node->id,
            'name' => $name,
            'environment' => 'development',
            'source_layout' => $position === 0
                ? InstanceSourceLayout::Checkout->value
                : InstanceSourceLayout::Worktree->value,
            'checkout_path' => "/srv/orbit/apps/acme/{$name}",
            'branch' => $name,
            'starting_commit' => str_repeat('a', 40),
            'status' => InstanceState::SourceResolved,
        ]);
        $route = Route::query()->create([
            'project_id' => $test->orbitApp->id,
            'node_id' => $test->node->id,
            'generation_basis_node_id' => $test->node->id,
            'domain' => "{$name}.acme.test",
            'provenance' => RouteProvenance::Generated,
            'publication' => RoutePublication::Private,
            'status' => RouteStatus::Pending,
        ]);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        $route->update(['status' => RouteStatus::Active]);
        $instance->update(['status' => InstanceState::Active]);
        $instances[] = $instance->load(['project', 'node', 'routes.targets']);
    }

    [$checkout, $first, $second] = $instances;
    $paths = [$checkout->checkout_path, $first->checkout_path, $second->checkout_path];
    sort($paths, SORT_STRING);
    $test->removalSource->livePaths = $paths;

    foreach ([$checkout, $first, $second] as $instance) {
        $test->removalSource->linkedPaths[$instance->id] = $paths;
    }

    return [$checkout, $first, $second, $paths];
}

final class RecoveredSourceProfileEnvironmentAccess implements InstanceEnvironmentReader, InstanceEnvironmentWriter, InstanceOperationPreflight
{
    public function assertEnvironmentReadable(
        InstanceEnvironmentContext $context,
    ): void {}

    public function assertEnvironmentWritable(
        InstanceEnvironmentContext $context,
        int $requiredCapacityBytes,
    ): void {}

    public function read(InstanceEnvironmentContext $context): string
    {
        return "RECOVERED=yes\n";
    }

    public function write(
        InstanceEnvironmentContext $context,
        #[SensitiveParameter]
        string $contents,
    ): InstanceEnvironmentWriteResult {
        return InstanceEnvironmentWriteResult::changed();
    }
}

it('creates or resumes a serving named monorepo default and runs setup once', function (bool $resume): void {
    $this->orbitApp->update(['type' => ProjectType::Monorepo, 'slug' => 'orbit', 'apps' => [['name' => 'web', 'path' => '.', 'web_root' => 'public', 'type' => 'monorepo']]]);
    $this->configuration->phpVersion = null;
    $this->configuration->laravel = false;
    ProjectLifecycleStep::query()->create([
        'project_id' => $this->orbitApp->id,
        'phase' => 'setup',
        'name' => 'gateway-dependencies',
        'command' => 'composer install --working-dir=apps/gateway',
        'timeout_seconds' => 30,
        'position' => 0,
    ]);
    $transport = new LifecycleSshExecutor;
    app()->instance(ProjectLifecycleRunner::class, $transport->runner());

    if ($resume) {
        Instance::query()->create([
            'project_id' => $this->orbitApp->id,
            'node_id' => $this->node->id,
            'name' => 'default',
            'checkout_path' => '/srv/orbit/apps/orbit/default',
            'source_layout' => InstanceSourceLayout::Checkout,
            'branch_override' => 'dev',
            'branch' => 'dev',
            'starting_commit' => str_repeat('a', 40),
            'status' => InstanceState::SourceResolved,
            'failed_step' => 'source-classification',
            'error_code' => 'app-dev.source_metadata_unsafe',
        ]);
    }

    $input = ['project_id' => $this->orbitApp->id, 'node_id' => $this->node->id, 'name' => 'default', 'branch' => 'dev'];
    $response = $this->postJson('/api/v1/instances', $input);
    $resume ? $response->assertOk() : $response->assertCreated();

    $instance = Instance::query()->sole();
    expect($instance->status)->toBe(InstanceState::Active)
        ->and($instance->failed_step)->toBeNull()
        ->and($instance->error_code)->toBeNull()
        ->and($instance->selected_php_version)->toBeNull()
        ->and($instance->source_is_laravel)->toBeFalse()
        ->and($instance->routes()->count())->toBe(1)
        ->and($this->projection->convergences)->toBe(1)
        ->and(array_column($transport->inputs, 'command'))->toBe(['composer install --working-dir=apps/gateway']);

    if ($resume) {
        expect($this->source->calls)->toBe(['inspect-prepared:source_resolved', 'inspect-resolved:source_resolved']);
    }

    $this->postJson('/api/v1/instances', $input)->assertOk();
    expect($transport->inputs)->toHaveCount(1)
        ->and(Instance::query()->sole()->id)->toBe($instance->id);

    $this->postJson('/api/v1/instances/'.$instance->id.'/setup')->assertOk();
    expect($transport->inputs)->toHaveCount(2);
})->with(['new checkout' => false, 'stuck source classification' => true]);

it('runs setup once on create and skips it for an already active instance', function (): void {
    ProjectLifecycleStep::query()->create(['project_id' => $this->orbitApp->id, 'phase' => 'setup', 'name' => 'install', 'command' => 'install', 'timeout_seconds' => 30, 'position' => 0]);
    $transport = new LifecycleSshExecutor;
    app()->instance(ProjectLifecycleRunner::class, $transport->runner());
    $input = ['project_id' => $this->orbitApp->id, 'node_id' => $this->node->id, 'name' => 'setup', 'branch' => 'dev'];
    $this->postJson('/api/v1/instances', $input)->assertCreated();
    $this->postJson('/api/v1/instances', $input)->assertOk();
    expect($transport->inputs)->toHaveCount(1);
});

it('busy setup retry points to instance:setup instead of treating the Instance as complete', function (): void {
    ProjectLifecycleStep::query()->create(['project_id' => $this->orbitApp->id, 'phase' => 'setup', 'name' => 'install', 'command' => 'install', 'timeout_seconds' => 30, 'position' => 0]);
    ProjectLifecycleStep::query()->create(['project_id' => $this->orbitApp->id, 'phase' => 'teardown', 'name' => 'cleanup', 'command' => 'cleanup', 'timeout_seconds' => 30, 'position' => 0]);
    $transport = new LifecycleSshExecutor(result: static fn (): int => 75);
    app()->instance(ProjectLifecycleRunner::class, $transport->runner());

    $this->postJson('/api/v1/instances', ['project_id' => $this->orbitApp->id, 'node_id' => $this->node->id, 'name' => 'busy', 'branch' => 'dev'])
        ->assertConflict()->assertJsonPath('error.code', 'instance.lifecycle_busy')
        ->assertJsonPath('error.details.outcome', 'busy');

    $instance = Instance::query()->where('name', 'busy')->sole();
    expect($instance->failed_step)->toBe('setup')
        ->and($instance->error_code)->toBe('instance.lifecycle_busy')
        ->and(Route::query()->count())->toBe(1)
        ->and(array_column($transport->inputs, 'command'))->toBe(['install']);

    $this->postJson('/api/v1/instances', ['project_id' => $this->orbitApp->id, 'node_id' => $this->node->id, 'name' => 'busy', 'branch' => 'dev'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'instance.setup_step_failed')
        ->assertJsonPath('error.message', 'Setup is incomplete. Run instance:setup before using this Instance.');

    expect(array_column($transport->inputs, 'command'))->toBe(['install']);
});

it('keeps setup pending when create is interrupted after activation before the busy result is saved', function (): void {
    ProjectLifecycleStep::query()->create(['project_id' => $this->orbitApp->id, 'phase' => 'setup', 'name' => 'install', 'command' => 'install', 'timeout_seconds' => 30, 'position' => 0]);
    $native = app(DevelopmentInstanceProvisioner::class);
    app()->instance(DevelopmentInstanceProvisioner::class, new class($native) implements DevelopmentInstanceProvisioner
    {
        public function __construct(private readonly DevelopmentInstanceProvisioner $native) {}

        public function reserve(Instance $instance, ?string $domain): void
        {
            $this->native->reserve($instance, $domain);
        }

        public function complete(
            Instance $instance,
            ?string $domain,
            bool $setupPending = false,
        ): Instance {
            $this->native->complete($instance, $domain, setupPending: $setupPending);

            throw new RuntimeException('Simulated interruption before setup result persistence.');
        }
    });
    $transport = new LifecycleSshExecutor;
    app()->instance(ProjectLifecycleRunner::class, $transport->runner());
    $data = new CreateInstanceData(
        projectId: $this->orbitApp->id,
        nodeId: $this->node->id,
        name: 'interrupted-setup',
        root: null,
        domain: null,
        branch: 'dev',
    );

    expect(fn () => app(CreateInstanceAction::class)->execute($data))
        ->toThrow(RuntimeException::class, 'Simulated interruption before setup result persistence.');

    $instance = Instance::query()->where('name', 'interrupted-setup')->sole();
    expect($instance->status)->toBe(InstanceState::Active)
        ->and($instance->failed_step)->toBe('setup')
        ->and($instance->error_code)->toBeNull();

    $this->postJson('/api/v1/instances', [
        'project_id' => $this->orbitApp->id,
        'node_id' => $this->node->id,
        'name' => 'interrupted-setup',
        'branch' => 'dev',
    ])->assertConflict()
        ->assertJsonPath('error.code', 'instance.setup_step_failed')
        ->assertJsonPath('error.message', 'Setup is incomplete. Run instance:setup before using this Instance.');

    expect($transport->inputs)->toBeEmpty();
});

it('keeps the Instance when rollback teardown encounters a busy lifecycle lock', function (): void {
    foreach (['setup', 'teardown'] as $position => $phase) {
        ProjectLifecycleStep::query()->create(['project_id' => $this->orbitApp->id, 'phase' => $phase, 'name' => $phase, 'command' => $phase, 'timeout_seconds' => 30, 'position' => $position]);
    }
    $transport = new LifecycleSshExecutor(result: static fn (array $input): int => $input['command'] === 'setup' ? 1 : 75);
    app()->instance(ProjectLifecycleRunner::class, $transport->runner());

    $this->postJson('/api/v1/instances', ['project_id' => $this->orbitApp->id, 'node_id' => $this->node->id, 'name' => 'busy-rollback', 'branch' => 'dev'])
        ->assertConflict()->assertJsonPath('error.code', 'instance.lifecycle_busy')
        ->assertJsonPath('error.details.outcome', 'busy');

    $instance = Instance::query()->where('name', 'busy-rollback')->sole();
    expect($instance->failed_step)->toBe('setup')
        ->and(Route::query()->count())->toBe(1)
        ->and(InstanceRemoval::query()->count())->toBe(0)
        ->and(array_column($transport->inputs, 'command'))->toBe(['setup', 'teardown']);
});

it('tears down and removes a newly created instance after confirmed setup failure', function (int $teardownExit): void {
    $this->postJson('/api/v1/instances', ['project_id' => $this->orbitApp->id, 'node_id' => $this->node->id, 'name' => 'preserve', 'branch' => 'dev'])->assertCreated();
    $preservedId = Instance::query()->sole()->id;

    foreach (['setup', 'teardown'] as $phase) {
        ProjectLifecycleStep::query()->create(['project_id' => $this->orbitApp->id, 'phase' => $phase, 'name' => $phase, 'command' => $phase, 'timeout_seconds' => 30, 'position' => 0]);
    }
    $transport = new LifecycleSshExecutor(result: static fn (array $input): int => $input['command'] === 'setup' ? 1 : $teardownExit);
    app()->instance(ProjectLifecycleRunner::class, $transport->runner());
    $this->postJson('/api/v1/instances', ['project_id' => $this->orbitApp->id, 'node_id' => $this->node->id, 'name' => 'failed-setup', 'branch' => 'dev'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'instance.setup_step_failed');
    expect(array_column($transport->inputs, 'command'))->toBe(['setup', 'teardown'])
        ->and(Instance::query()->sole()->id)->toBe($preservedId)
        ->and(Route::query()->count())->toBe(1);
})->with([0, 1]);

it('stops setup early enough in a create that the rollback still fits the request deadline', function (): void {
    foreach (['setup', 'teardown'] as $phase) {
        ProjectLifecycleStep::query()->create(['project_id' => $this->orbitApp->id, 'phase' => $phase, 'name' => $phase, 'command' => $phase, 'timeout_seconds' => 540, 'position' => 0]);
    }
    $now = 0.0;
    $deadline = new CommandDeadline(static function () use (&$now): float {
        return $now;
    });
    $deadline->start(570.0, CommandDeadline::CleanupReserveSeconds);
    app()->instance(CommandDeadline::class, $deadline);
    $now = 300.0;
    // Both lists run until their timeout, as a remote `sleep` would.
    $transport = new LifecycleSshExecutor(result: static function (array $input) use (&$now): int {
        $now += $input['timeout'];

        return $input['command'] === 'setup' ? 124 : 0;
    });
    app()->instance(ProjectLifecycleRunner::class, $transport->runner($deadline));

    $this->postJson('/api/v1/instances', ['project_id' => $this->orbitApp->id, 'node_id' => $this->node->id, 'name' => 'deadline-setup', 'branch' => 'dev'])
        ->assertStatus(504)
        ->assertJsonPath('error.code', 'command.deadline_exceeded')
        ->assertJsonPath('error.details.step', 'setup')
        ->assertJsonPath('error.details.outcome', 'deadline')
        ->assertJsonPath('error.message', "Setup step [setup] was stopped by the request deadline after 95 seconds, before its own 540-second timeout. Lower the list's step timeouts so the whole list fits one request. The Instance was removed.");

    // Setup stopped 150 seconds early (60 for teardown, 90 for removal) plus the 20-second cleanup reserve.
    // Teardown then ran within its share, and the removal still had its 90 seconds.
    expect(array_column($transport->inputs, 'command'))->toBe(['setup', 'teardown'])
        ->and(array_column($transport->inputs, 'timeout'))->toBe([95, 80])
        ->and(570.0 - $now)->toBeGreaterThanOrEqual(CreateInstanceAction::RollbackRemovalSeconds)
        ->and(Instance::query()->count())->toBe(0);
});

it('names the forced destroy that finishes a create rollback whose removal did not complete', function (): void {
    foreach (['setup', 'teardown'] as $phase) {
        ProjectLifecycleStep::query()->create(['project_id' => $this->orbitApp->id, 'phase' => $phase, 'name' => $phase, 'command' => $phase, 'timeout_seconds' => 30, 'position' => 0]);
    }
    $transport = new LifecycleSshExecutor(result: static fn (array $input): int => $input['command'] === 'setup' ? 1 : 0);
    app()->instance(ProjectLifecycleRunner::class, $transport->runner());
    // The Instance this create makes is the next id; its source removal is interrupted.
    $this->removalSource->failPrepareFor = (int) Instance::query()->max('id') + 1;

    $response = $this->postJson('/api/v1/instances', ['project_id' => $this->orbitApp->id, 'node_id' => $this->node->id, 'name' => 'stuck-rollback', 'branch' => 'dev'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'instance.setup_step_failed')
        ->assertJsonPath('error.details.cleanup', 'incomplete');
    $instance = Instance::query()->where('name', 'stuck-rollback')->sole();

    expect($instance->id)->toBe($this->removalSource->failPrepareFor)
        ->and($response->json('error.message'))->toBe(
            "Setup failed and cleanup is incomplete. Inspect the Instance, then finish the removal with `orbit instance:destroy {$instance->id} --force`.",
        );

    // A plain destroy refuses the forced removal the rollback started; the named command finishes it.
    $this->removalSource->failPrepareFor = null;
    $this->deleteJson("/api/v1/instances/{$instance->id}")->assertConflict()->assertJsonPath('error.code', 'instance.removal_conflict');
    $this->deleteJson("/api/v1/instances/{$instance->id}", ['force' => true])->assertOk();

    expect(Instance::query()->whereKey($instance->id)->exists())->toBeFalse();
});

it('retains the checkout when setup execution cannot be confirmed', function (): void {
    ProjectLifecycleStep::query()->create(['project_id' => $this->orbitApp->id, 'phase' => 'setup', 'name' => 'install', 'command' => 'install', 'timeout_seconds' => 30, 'position' => 0]);
    $transport = new LifecycleSshExecutor(result: static fn (): int => 255);
    app()->instance(ProjectLifecycleRunner::class, $transport->runner());
    $this->postJson('/api/v1/instances', ['project_id' => $this->orbitApp->id, 'node_id' => $this->node->id, 'name' => 'unconfirmed', 'branch' => 'dev'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'instance.setup_step_failed');
    expect(Instance::query()->count())->toBe(1)
        ->and(Route::query()->count())->toBe(1)
        ->and($transport->inputs)->toHaveCount(1);
    $this->postJson('/api/v1/instances', ['project_id' => $this->orbitApp->id, 'node_id' => $this->node->id, 'name' => 'unconfirmed', 'branch' => 'dev'])
        ->assertConflict()->assertJsonPath('error.code', 'instance.setup_step_failed');
    $transport->result = static fn (): int => 0;
    $instance = Instance::query()->sole();
    $this->postJson('/api/v1/instances/'.$instance->id.'/setup')->assertOk();
    expect($instance->refresh()->failed_step)->toBeNull();

});
