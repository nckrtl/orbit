<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Actions\Apps\CreateAppAction;
use App\Data\AppInstances\AppInstanceData;
use App\Data\AppInstances\RegisterAppInstanceData;
use App\Data\Apps\CreateAppData;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppInstances\AppInstanceDestinationGuard;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DevelopmentAppInstanceConfigurator;
use App\Domain\AppInstances\DevelopmentAppInstanceProvisioner;
use App\Domain\AppInstances\Registration\RegistrationSourceFacts;
use App\Domain\AppInstances\Registration\RegistrationSourceManager;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Projects\DevelopmentNodeExclusion;
use App\Domain\Projects\ProjectTypeClassifier;
use App\Domain\Routes\RouteDomain;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Domain\SourceControl\ProjectRoot;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class RegisterAppInstanceAction
{
    public function __construct(
        private RegistrationSourceManager $sources,
        private CreateAppAction $createApp,
        private ManagedUserAccountResolver $accounts,
        private StorageRootResolver $storageRoots,
        private NodeSettingsNormalizer $nodeSettings,
        private ManagedCheckoutOverlap $checkoutOverlap,
        private AppInstanceDestinationGuard $destinationGuard,
        private AppDevSourceOperationLock $sourceLock,
        private DevelopmentProjectionOperationLock $projectionLock,
        private DevelopmentAppInstanceProvisioner $provisioner,
        private DevelopmentAppInstanceConfigurator $configuration,
        private ?RecordEventBroadcaster $broadcaster = null,
        private ?RunInstanceSetupAction $setup = null,
    ) {}

    /** @return array{app: OrbitApp, primary: AppInstance, instances: list<AppInstance>, created: bool} */
    public function execute(Node $caller, RegisterAppInstanceData $data): array
    {
        $result = $this->performRegistration($caller, $data);

        if ($result['created']) {
            ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
                RecordEventType::InstanceCreated,
                $result['primary']->id,
                AppInstanceData::fromModel($result['primary'])->toArray(),
            );
        }

        if ($data->runSetup && ! $result['primary']->placedOnAppProd()) {
            ($this->setup ?? app(RunInstanceSetupAction::class))->execute($result['primary']);
        }

        return $result;
    }

    /** @return array{app: OrbitApp, primary: AppInstance, instances: list<AppInstance>, created: bool} */
    private function performRegistration(Node $caller, RegisterAppInstanceData $data): array
    {
        $this->assertPlacement($caller);

        if ($data->domain !== null) {
            RouteDomain::validate($data->domain);
        }

        return $this->sourceLock->synchronized($caller->id, function () use ($caller, $data): array {
            $retainedMember = $this->retainedMember($caller, $data);

            if (
                $retainedMember instanceof AppInstance
                && ! $retainedMember->registration_primary
            ) {
                throw $this->conflict(
                    'instance.registration_conflict',
                    'Registration retry input conflicts with retained evidence.',
                );
            }

            $retainedPrimary = $retainedMember;
            $facts = $retainedPrimary instanceof AppInstance
                ? $this->retainedFacts($retainedPrimary, $data)
                : $this->sources->inspect(
                    $caller,
                    $data->sourcePath,
                    $data->includeWorktrees,
                );

            if (! $retainedPrimary instanceof AppInstance) {
                $this->assertSourcesNotRetained($caller, $facts);
            }

            $primaryFacts = $retainedPrimary instanceof AppInstance
                ? $facts[0]
                : $this->primaryFacts($facts, $data->sourcePath);
            [$app, $appCreated] = $this->resolveApp($primaryFacts, $data);
            $this->preflightSources($caller, $app, $facts, $retainedPrimary, $data->sourcePath);
            [$members, $instances] = $this->projectionLock->run(function () use (
                $caller,
                $app,
                $facts,
                $primaryFacts,
                $data,
                $appCreated,
            ): array {
                $members = $this->reserveMembers($caller, $app, $facts, $primaryFacts, $data);

                try {
                    if ($this->needsRelocation($members)) {
                        $this->sources->relocateSet(array_map(
                            static fn (array $member): array => [
                                'appInstance' => $member['appInstance'],
                                'facts' => $member['facts'],
                            ],
                            $members,
                        ));
                    }
                    $instances = [];

                    foreach ($members as $member) {
                        $instances[] = $this->completeMember(
                            $member['appInstance'],
                            $member['facts'],
                            $member['routeDomain'],
                        );
                    }
                } catch (Throwable $exception) {
                    foreach ($members as $member) {
                        $this->recordFailure($member['appInstance'], $exception);
                    }

                    throw new ResourceOperationException(
                        errorCode: 'instance.registration_incomplete',
                        message: $appCreated
                            ? "App [{$app->slug}] was retained; AppInstance registration is incomplete and can be retried."
                            : 'AppInstance registration is incomplete and can be retried.',
                        status: 502,
                        previous: $exception,
                    );
                }

                return [$members, $instances];
            });

            $primary = collect($instances)->first(
                static fn (AppInstance $instance): bool => (
                    $instance->registration_original_path === $primaryFacts->path
                ),
            );
            assert($primary instanceof AppInstance);

            return [
                'app' => $app->refresh(),
                'primary' => $primary,
                'instances' => $instances,
                'created' => $appCreated,
            ];
        });
    }

    private function retainedMember(Node $node, RegisterAppInstanceData $data): ?AppInstance
    {
        $matches = AppInstance::query()
            ->where('node_id', $node->id)
            ->whereNotNull('registration_request_id')
            ->where(static function ($query) use ($data): void {
                $query
                    ->where('registration_original_path', $data->sourcePath)
                    ->orWhere('checkout_path', $data->sourcePath);
            })
            ->get();

        if ($matches->count() > 1) {
            throw $this->conflict(
                'instance.registration_evidence_invalid',
                'Several retained registrations identify the requested source path.',
            );
        }

        $member = $matches->first();

        if (! $member instanceof AppInstance) {
            return null;
        }

        if (! $member->registration_primary) {
            throw $this->conflict(
                'instance.registration_conflict',
                'Registration retry input conflicts with retained evidence.',
            );
        }

        $this->assertRetainedEntryPath($member, $data->sourcePath);

        return $member;
    }

    private function assertRetainedEntryPath(AppInstance $instance, string $submittedPath): void
    {
        if ($submittedPath === $instance->registration_original_path) {
            return;
        }

        $destination = $instance->checkout_path;
        $state = $instance->registration_relocation_state;

        if (
            $submittedPath !== $destination
            || ! in_array(
                $state,
                ['relocating', 'destination_verified', 'original_cleanup', 'relocated'],
                strict: true,
            )
        ) {
            throw $this->conflict(
                'instance.registration_conflict',
                'Registration retry input conflicts with retained relocation evidence.',
            );
        }

        $expectedAuthority = $state === 'relocating'
            ? $instance->registration_original_path
            : $destination;

        if ($instance->registration_authoritative_path !== $expectedAuthority) {
            throw $this->conflict(
                'instance.registration_evidence_invalid',
                'Retained registration evidence identifies an invalid authoritative source path.',
            );
        }
    }

    /** @param list<RegistrationSourceFacts> $facts */
    private function assertSourcesNotRetained(Node $node, array $facts): void
    {
        $retained = AppInstance::query()
            ->where('node_id', $node->id)
            ->whereNotNull('registration_request_id')
            ->where(static function ($query) use ($facts): void {
                $paths = array_map(
                    static fn (RegistrationSourceFacts $fact): string => $fact->path,
                    $facts,
                );
                $query
                    ->whereIn('registration_original_path', $paths)
                    ->orWhereIn('checkout_path', $paths);
            })
            ->exists();

        if ($retained) {
            throw $this->conflict(
                'instance.registration_conflict',
                'Registration retry input conflicts with retained evidence.',
            );
        }
    }

    /** @param list<RegistrationSourceFacts> $facts */
    private function preflightSources(
        Node $node,
        OrbitApp $app,
        array $facts,
        ?AppInstance $retainedPrimary,
        string $submittedPath,
    ): void {
        foreach ($facts as $fact) {
            $instance = AppInstance::query()
                ->where('node_id', $node->id)
                ->where('registration_original_path', $fact->path)
                ->first();
            $path = $this->authoritativeSourcePath($instance, $fact);

            if ($retainedPrimary instanceof AppInstance) {
                if ($instance instanceof AppInstance && $instance->registration_relocation_state === 'relocating') {
                    $path = $this->relocatingSourcePath(
                        $node,
                        $instance,
                        $fact,
                        $instance->is($retainedPrimary) && $submittedPath !== $instance->registration_original_path
                            ? $submittedPath
                            : null,
                    );
                } else {
                    $this->sources->validateRetained($node, $fact, $path);
                }
            }

            $candidate = new AppInstance([
                'app_id' => $app->id,
                'node_id' => $node->id,
                'checkout_path' => $path,
            ]);
            $candidate->setRelation('app', $app);
            $candidate->setRelation('node', $node);
            $this->configuration->inspect($candidate);
        }
    }

    private function authoritativeSourcePath(
        ?AppInstance $instance,
        RegistrationSourceFacts $facts,
    ): string {
        if (! $instance instanceof AppInstance) {
            return $facts->path;
        }

        $destinationIsAuthoritative = in_array(
            $instance->registration_relocation_state,
            ['destination_verified', 'original_cleanup', 'relocated'],
            strict: true,
        );
        $expected = $destinationIsAuthoritative
            ? $instance->checkout_path
            : $facts->path;

        if ($instance->registration_authoritative_path !== $expected) {
            throw $this->conflict(
                'instance.registration_evidence_invalid',
                'Retained registration evidence identifies an invalid authoritative source path.',
            );
        }

        return $expected;
    }

    private function relocatingSourcePath(
        Node $node,
        AppInstance $instance,
        RegistrationSourceFacts $facts,
        ?string $submittedPath,
    ): string {
        if ($submittedPath !== null) {
            $this->sources->validateRelocationRecovery($node, $facts, $submittedPath);

            return $submittedPath;
        }

        try {
            $this->sources->validateRelocationRecovery($node, $facts, $facts->path);

            return $facts->path;
        } catch (Throwable) {
            try {
                $destination = $instance->checkout_path;
                $this->sources->validateRelocationRecovery($node, $facts, $destination);

                return $destination;
            } catch (Throwable $destinationFailure) {
                throw new ResourceOperationException(
                    'instance.registration_conflict',
                    'Neither retained relocation path matches the verified source state.',
                    409,
                    previous: $destinationFailure,
                );
            }
        }
    }

    /** @param list<RegistrationSourceFacts> $facts */
    private function primaryFacts(array $facts, string $requestedPath): RegistrationSourceFacts
    {
        foreach ($facts as $fact) {
            if ($fact->path === $requestedPath || str_starts_with($requestedPath, $fact->path.'/')) {
                return $fact;
            }
        }

        return $facts[0];
    }

    /**
     * @return list<RegistrationSourceFacts>
     */
    private function retainedFacts(AppInstance $primary, RegisterAppInstanceData $data): array
    {
        if (
            $primary->registration_request_id === null
            || $primary->registration_include_worktrees !== $data->includeWorktrees
        ) {
            throw $this->conflict(
                'instance.registration_conflict',
                'Registration retry input conflicts with retained evidence.',
            );
        }

        $instances = AppInstance::query()
            ->where('registration_request_id', $primary->registration_request_id)
            ->orderByDesc('registration_primary')
            ->orderBy('id')
            ->get();

        $expectedPathValues = $data->includeWorktrees
            ? $primary->registration_worktree_paths
            : [$primary->registration_original_path];

        if (! is_array($expectedPathValues)) {
            throw $this->conflict(
                'instance.registration_evidence_invalid',
                'Retained registration evidence does not contain the complete requested source set.',
            );
        }
        $expectedPaths = [];
        foreach ($expectedPathValues as $path) {
            if (! is_string($path)) {
                throw $this->conflict(
                    'instance.registration_evidence_invalid',
                    'Retained registration evidence does not contain the complete requested source set.',
                );
            }
            $expectedPaths[] = $path;
        }

        $retainedPaths = [];

        foreach ($instances as $instance) {
            if (! is_string($instance->registration_original_path)) {
                throw $this->conflict(
                    'instance.registration_evidence_invalid',
                    'Retained registration evidence does not contain the complete requested source set.',
                );
            }

            $retainedPaths[] = $instance->registration_original_path;
        }

        if (
            count($instances) !== count($expectedPaths)
            || count($retainedPaths) !== count(array_unique($retainedPaths))
            || $this->sortedPaths($retainedPaths) !== $this->sortedPaths($expectedPaths)
            || $instances->where('registration_primary', true)->count() !== 1
            || $instances->contains(
                static fn (AppInstance $instance): bool => (
                    $instance->registration_include_worktrees !== $data->includeWorktrees
                ),
            )
        ) {
            throw $this->conflict(
                'instance.registration_evidence_invalid',
                'Retained registration evidence does not contain the complete requested source set.',
            );
        }

        $facts = [];

        foreach ($instances as $instance) {
            $facts[] = $this->retainedFact($instance);
        }

        return $facts;
    }

    private function retainedFact(AppInstance $instance): RegistrationSourceFacts
    {
        $layout = AppInstanceSourceLayout::tryFrom($instance->source_layout);
        $worktreePaths = $instance->getAttribute('registration_worktree_paths');

        if (
            ! $layout instanceof AppInstanceSourceLayout
            || $instance->registration_original_path === null
            || $instance->registration_repository_url === null
            || $instance->registration_repository_identity === null
            || $instance->registration_source_digest === null
            || $instance->registration_inferred_slug === null
            || $instance->registration_common_repository_path === null
            || $instance->starting_commit === null
            || ! is_array($worktreePaths)
            || array_filter($worktreePaths, static fn (mixed $path): bool => ! is_string($path)) !== []
        ) {
            throw $this->conflict(
                'instance.registration_evidence_invalid',
                'Retained registration evidence is incomplete.',
            );
        }

        return new RegistrationSourceFacts(
            path: $instance->registration_original_path,
            layout: $layout,
            repositoryUrl: $instance->registration_repository_url,
            repositoryIdentity: $instance->registration_repository_identity,
            branch: $instance->branch,
            detached: $instance->registration_detached,
            commit: $instance->starting_commit,
            defaultBranch: $instance->registration_default_branch,
            inferredSlug: $instance->registration_inferred_slug,
            inferredRoot: $instance->registration_inferred_root,
            commonRepositoryPath: $instance->registration_common_repository_path,
            worktreePaths: array_values(array_filter($worktreePaths, is_string(...))),
            sourceDigest: $instance->registration_source_digest,
        );
    }

    /** @return array{OrbitApp, bool} */
    private function resolveApp(RegistrationSourceFacts $facts, RegisterAppInstanceData $data): array
    {
        $byRepository = OrbitApp::query()
            ->where('repository_identity', $facts->repositoryIdentity)
            ->get();

        if ($byRepository->count() > 1) {
            throw $this->conflict(
                'app.repository_identity_conflict',
                'Several Apps own the requested repository identity.',
            );
        }

        $explicit = $data->appId === null ? null : OrbitApp::query()->findOrFail($data->appId);
        $resolved = $byRepository->first();

        if ($explicit instanceof OrbitApp && $explicit->repository_identity !== $facts->repositoryIdentity) {
            throw $this->conflict(
                'app.repository_identity_conflict',
                'The selected App owns a different repository identity.',
            );
        }

        if ($explicit instanceof OrbitApp && $resolved instanceof OrbitApp && ! $explicit->is($resolved)) {
            throw $this->conflict('app.repository_identity_conflict', 'The repository is owned by a different App.');
        }

        $app = $explicit ?? $resolved;

        if ($app instanceof OrbitApp) {
            $this->assertExistingAppInput($app, $data);
            $root = $data->root ?? $app->root;

            if (! is_string($root) || ! ProjectRoot::isValid($root, $app->type)) {
                throw new ResourceOperationException('app.root_invalid', 'The Project root is invalid.', 422);
            }

            return [$app, false];
        }

        $slug = $data->appSlug ?? $facts->inferredSlug;
        $defaultBranch = $data->defaultBranch ?? $facts->defaultBranch;
        $root = $data->root ?? $facts->inferredRoot;

        if ($slug !== $facts->inferredSlug) {
            throw $this->conflict(
                'app.slug_conflict',
                'The confirmed Project slug conflicts with verified repository evidence.',
            );
        }

        if ($facts->defaultBranch !== null && $defaultBranch !== $facts->defaultBranch) {
            throw $this->conflict(
                'app.default_branch_conflict',
                'The confirmed default branch conflicts with verified repository evidence.',
            );
        }

        if ($facts->inferredRoot !== null && $root !== $facts->inferredRoot) {
            throw $this->conflict('app.root_conflict', 'The confirmed root conflicts with verified Laravel evidence.');
        }

        if ($defaultBranch === null || $root === null) {
            throw new ResourceOperationException(
                'instance.registration_values_unresolved',
                'App default branch and root must be confirmed before registration.',
                422,
            );
        }

        $type = new ProjectTypeClassifier()->classify([
            'slug' => $slug,
            'repository_identity' => GitRepositoryIdentity::derive($facts->repositoryUrl),
            'root' => $root,
            'has_production_php' => false,
        ]);
        if (! ProjectRoot::isValid($root, $type)) {
            throw new ResourceOperationException('app.root_invalid', 'The Project root is invalid.', 422);
        }

        $result = $this->createApp->execute(new CreateAppData(
            name: $data->appName ?? $slug,
            slug: $slug,
            type: $type,
            repositoryUrl: $facts->repositoryUrl,
            defaultBranch: GitBranchName::validate($defaultBranch),
            root: ProjectRoot::validate($root, $type),
        ));

        return [$result['app'], $result['created']];
    }

    private function assertExistingAppInput(OrbitApp $app, RegisterAppInstanceData $data): void
    {
        if (
            $data->appSlug !== null
            && $data->appSlug !== $app->slug
            || $data->appName !== null
            && $data->appName !== $app->name
            || $data->defaultBranch !== null
            && $data->defaultBranch !== $app->default_branch
        ) {
            throw $this->conflict('app.identity_conflict', 'Confirmed App values conflict with the existing App.');
        }
    }

    /**
     * @param  list<RegistrationSourceFacts>  $facts
     * @return list<array{appInstance: AppInstance, facts: RegistrationSourceFacts, routeDomain: string|null}>
     */
    private function reserveMembers(
        Node $node,
        OrbitApp $app,
        array $facts,
        RegistrationSourceFacts $primary,
        RegisterAppInstanceData $data,
    ): array {
        $account = $this->accounts->resolve($node);
        $roots = $this->storageRoots->resolveApps(
            $this->nodeSettings->fromStored($node->settings),
            $account,
        );
        $rootOverride = $data->root !== null && $data->root !== $app->root ? $data->root : null;
        $retainedRequestId = AppInstance::query()
            ->where('node_id', $node->id)
            ->where('registration_original_path', $primary->path)
            ->value('registration_request_id');
        $requestId = is_string($retainedRequestId) ? $retainedRequestId : (string) Str::uuid();
        $proposals = [];
        $names = [];
        $destinations = [];

        foreach ($facts as $fact) {
            $instance = AppInstance::query()
                ->where('node_id', $node->id)
                ->where('registration_original_path', $fact->path)
                ->first();

            $explicitName = $fact === $primary ? $data->instanceName : null;
            $name = $instance instanceof AppInstance && $instance->registration_request_id !== null
                ? $this->retainedInstanceName($instance, $explicitName)
                : $this->instanceName($app, $fact, $explicitName);
            $destination = $roots->append($app->slug, $name);

            if (isset($names[$name]) || isset($destinations[$destination->value])) {
                throw $this->conflict(
                    'instance.identity_conflict',
                    'The requested source set contains conflicting AppInstance identities or placements.',
                );
            }

            $names[$name] = true;
            $destinations[$destination->value] = true;
            $sourcePath = $instance instanceof AppInstance
            && in_array(
                $instance->registration_relocation_state,
                ['destination_verified', 'original_cleanup', 'relocated'],
                strict: true,
            )
                ? $instance->checkout_path
                : $fact->path;
            $this->checkoutOverlap->assertAvailable(
                $node->id,
                StoragePath::parse($sourcePath),
                'instance.source_conflict',
                ignoreAppInstanceId: $instance?->id,
            );

            if (! $instance instanceof AppInstance) {
                $this->checkoutOverlap->assertAvailable($node->id, $destination, 'instance.path_taken');
                if ($fact->path !== $destination->value) {
                    $this->destinationGuard->assertUnoccupied($node, $destination);
                }
            } else {
                $this->assertRetryRequest(
                    $instance,
                    $fact === $primary,
                    $data->includeWorktrees,
                );
                $this->assertRetry(
                    $instance,
                    $app,
                    $fact,
                    $destination,
                    $data->root,
                );
            }

            $routeIntent = $this->registrationRouteIntent(
                $instance,
                $fact === $primary ? $data->domain : null,
            );

            $proposals[] = [
                'facts' => $fact,
                'name' => $name,
                'destination' => $destination,
                'instance' => $instance,
                'primary' => $fact === $primary,
                'routeDomain' => $routeIntent['domain'],
                'routeProvenance' => $routeIntent['provenance'],
                'backfillRouteIntent' => $routeIntent['backfill'],
            ];
        }

        $reserved = DB::transaction(function () use ($proposals, $app, $node, $rootOverride, $requestId, $data): array {
            $reserved = [];

            foreach ($proposals as $proposal) {
                $fact = $proposal['facts'];
                $instance = $proposal['instance'];

                if ($instance instanceof AppInstance) {
                    if ($proposal['backfillRouteIntent']) {
                        $instance->update([
                            'registration_route_domain' => $proposal['routeDomain'],
                            'registration_route_provenance' => $proposal['routeProvenance'],
                        ]);
                    }
                    $instance->name = $proposal['name'];
                    $instance->checkout_path = $proposal['destination']->value;
                    $reserved[] = [
                        'appInstance' => $instance,
                        'facts' => $fact,
                        'routeDomain' => $proposal['routeDomain'],
                    ];

                    continue;
                }

                app(DevelopmentNodeExclusion::class)->assertAvailable($app, $node);
                $instance = AppInstance::query()->create([
                    'app_id' => $app->id,
                    'node_id' => $node->id,
                    'name' => $proposal['name'],
                    'source_layout' => $fact->layout,
                    'checkout_path' => $proposal['destination']->value,
                    'root' => $rootOverride,
                    'branch' => $fact->branch,
                    'starting_commit' => $fact->commit,
                    ...$this->registrationEvidence(
                        $fact,
                        requestId: $requestId,
                        primary: $proposal['primary'],
                        includeWorktrees: $data->includeWorktrees,
                        routeIntent: [
                            'domain' => $proposal['routeDomain'],
                            'provenance' => $proposal['routeProvenance'],
                        ],
                    ),
                    'status' => AppInstanceState::Reserved,
                ]);
                $reserved[] = [
                    'appInstance' => $instance,
                    'facts' => $fact,
                    'routeDomain' => $proposal['routeDomain'],
                ];
            }

            return $reserved;
        });

        return $reserved;
    }

    /**
     * @param  list<array{appInstance: AppInstance, facts: RegistrationSourceFacts, routeDomain: string|null}>  $members
     */
    private function needsRelocation(array $members): bool
    {
        foreach ($members as $member) {
            $instance = $member['appInstance'];

            if (
                $instance->registration_relocation_state !== 'relocated'
                || $instance->registration_authoritative_path !== $instance->checkout_path
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function sortedPaths(array $paths): array
    {
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * @param  array{domain: string|null, provenance: string}  $routeIntent
     * @return array<string, mixed>
     */
    private function registrationEvidence(
        RegistrationSourceFacts $facts,
        string $requestId,
        bool $primary,
        bool $includeWorktrees,
        array $routeIntent,
    ): array {
        return [
            'registration_original_path' => $facts->path,
            'registration_request_id' => $requestId,
            'registration_primary' => $primary,
            'registration_include_worktrees' => $includeWorktrees,
            'registration_repository_url' => $facts->repositoryUrl,
            'registration_repository_identity' => $facts->repositoryIdentity,
            'registration_source_digest' => $facts->sourceDigest,
            'registration_detached' => $facts->detached,
            'registration_default_branch' => $facts->defaultBranch,
            'registration_inferred_slug' => $facts->inferredSlug,
            'registration_inferred_root' => $facts->inferredRoot,
            'registration_common_repository_path' => $facts->commonRepositoryPath,
            'registration_worktree_paths' => $facts->worktreePaths,
            'registration_relocation_state' => 'reserved',
            'registration_authoritative_path' => $facts->path,
            'registration_route_domain' => $routeIntent['domain'],
            'registration_route_provenance' => $routeIntent['provenance'],
        ];
    }

    private function instanceName(OrbitApp $app, RegistrationSourceFacts $facts, ?string $explicit): string
    {
        $derived = basename($facts->path);

        if (
            $facts->layout->value === 'checkout'
            && $derived === $app->slug
            && $facts->branch === $app->default_branch
        ) {
            if ($explicit !== null && $explicit !== 'default') {
                throw $this->conflict(
                    'instance.identity_conflict',
                    'This source has the reserved default AppInstance identity.',
                );
            }

            return 'default';
        }

        $name = $explicit ?? $derived;

        if (preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $name) !== 1 || strlen($name) > 63) {
            throw new ResourceOperationException(
                'instance.name_invalid',
                'The AppInstance name must be confirmed as a lowercase DNS label.',
                422,
            );
        }

        return $name;
    }

    private function retainedInstanceName(AppInstance $instance, ?string $explicit): string
    {
        if ($explicit !== null && $explicit !== $instance->name) {
            throw $this->conflict(
                'instance.registration_conflict',
                'Registration retry input conflicts with retained AppInstance identity.',
            );
        }

        return $instance->name;
    }

    /**
     * @return array{
     *     domain: string|null,
     *     provenance: string,
     *     backfill: bool
     * }
     */
    private function registrationRouteIntent(?AppInstance $instance, ?string $requestedHostname): array
    {
        $requestedHostname = $requestedHostname === null
            ? null
            : RouteDomain::validate($requestedHostname);

        if (! $instance instanceof AppInstance) {
            return [
                'domain' => $requestedHostname,
                'provenance' => $requestedHostname === null
                    ? RouteProvenance::Generated->value
                    : RouteProvenance::Explicit->value,
                'backfill' => false,
            ];
        }

        if ($this->currentRouteIsAuthoritative($instance)) {
            $current = $this->currentRegistrationRouteIntent($instance);
            $this->assertRequestedRegistrationRouteIntent(
                $requestedHostname,
                $current['domain'],
                $current['provenance'],
            );

            return [
                'domain' => $current['domain'],
                'provenance' => $current['provenance'],
                'backfill' => false,
            ];
        }

        $domain = $instance->registration_route_domain;
        $provenance = $instance->registration_route_provenance;
        $backfill = false;

        if ($domain === null && $provenance === null) {
            $legacyIntent = $this->legacyRegistrationRouteIntent($instance);
            $domain = $legacyIntent['domain'];
            $provenance = $legacyIntent['provenance'];
            $backfill = $instance->registration_request_id !== null;
        }

        $this->assertValidRegistrationRouteIntent($domain, $provenance);
        assert(is_string($provenance));
        $this->assertPrePublicationRegistrationRouteIntent($instance, $domain, $provenance);
        $this->assertRequestedRegistrationRouteIntent($requestedHostname, $domain, $provenance);

        return [
            'domain' => $domain,
            'provenance' => $provenance,
            'backfill' => $backfill,
        ];
    }

    private function currentRouteIsAuthoritative(AppInstance $instance): bool
    {
        return $instance->registration_completed_at !== null
            || $instance->status === AppInstanceState::Active
            && $instance->provisioning_step === 'active';
    }

    /**
     * @return array{
     *     domain: string|null,
     *     provenance: string
     * }
     */
    private function currentRegistrationRouteIntent(AppInstance $instance): array
    {
        $routes = $instance->routes()->get();

        if ($routes->count() !== 1) {
            throw $this->invalidRegistrationRouteIntent();
        }

        $route = $routes->first();
        assert($route instanceof Route);
        $this->assertRouteDomainChangeComplete($route);
        $domain = RouteDomain::validate($route->domain);
        $provenance = $route->provenance->value;

        return [
            'domain' => $provenance === RouteProvenance::Explicit->value ? $domain : null,
            'provenance' => $provenance,
        ];
    }

    private function assertPrePublicationRegistrationRouteIntent(
        AppInstance $instance,
        ?string $domain,
        string $provenance,
    ): void {
        $routes = $instance->routes()->get();

        if ($routes->count() > 1) {
            throw $this->invalidRegistrationRouteIntent();
        }

        $route = $routes->first();

        if ($route instanceof Route) {
            $this->assertRouteDomainChangeComplete($route);
            $this->assertRegistrationRouteIntentMatches(
                $domain,
                $provenance,
                [
                    'id' => $route->id,
                    'domain' => $route->domain,
                    'provenance' => $route->provenance->value,
                ],
            );
        }
    }

    private function assertRouteDomainChangeComplete(Route $route): void
    {
        if ($route->replaced_by_route_id === null && $route->replaces_route_id === null) {
            return;
        }

        throw $this->conflict(
            'instance.registration_conflict',
            'Registration cannot resume while the authoritative Route domain change is incomplete.',
        );
    }

    /** @param array{id: int, domain: string, provenance: string} $route */
    private function assertRegistrationRouteIntentMatches(
        ?string $domain,
        string $provenance,
        array $route,
    ): void {
        if (
            $route['provenance'] === $provenance
            && ($provenance === RouteProvenance::Generated->value
            || $route['domain'] === $domain)
        ) {
            return;
        }

        throw $this->invalidRegistrationRouteIntent();
    }

    private function assertRequestedRegistrationRouteIntent(
        ?string $requestedHostname,
        ?string $domain,
        string $provenance,
    ): void {
        if (
            $requestedHostname === null
            || $provenance === RouteProvenance::Explicit->value
            && $domain === $requestedHostname
        ) {
            return;
        }

        throw $this->conflict(
            'instance.registration_conflict',
            'Registration retry input conflicts with the authoritative Route domain intent.',
        );
    }

    /** @return array{domain: string|null, provenance: string} */
    private function legacyRegistrationRouteIntent(AppInstance $instance): array
    {
        if ($instance->routes()->count() !== 1) {
            throw $this->invalidRegistrationRouteIntent();
        }

        $route = $instance->routes()->sole();
        $routeIntent = [
            'domain' => $route->domain,
            'provenance' => $route->provenance->value,
        ];

        if (! RouteDomain::isValid($routeIntent['domain'])) {
            throw $this->invalidRegistrationRouteIntent();
        }

        return [
            'domain' => $routeIntent['provenance'] === RouteProvenance::Explicit->value
                ? RouteDomain::normalize($routeIntent['domain'])
                : null,
            'provenance' => $routeIntent['provenance'],
        ];
    }

    private function assertValidRegistrationRouteIntent(?string $domain, ?string $provenance): void
    {
        if (
            $provenance === RouteProvenance::Explicit->value
            && $domain !== null
            && RouteDomain::isValid($domain)
            && RouteDomain::normalize($domain) === $domain
            || $provenance === RouteProvenance::Generated->value
            && $domain === null
        ) {
            return;
        }

        throw $this->invalidRegistrationRouteIntent();
    }

    private function invalidRegistrationRouteIntent(): ResourceOperationException
    {
        return $this->conflict(
            'instance.registration_evidence_invalid',
            'Retained Route registration intent is incomplete or conflicting.',
        );
    }

    private function assertRetry(
        AppInstance $instance,
        OrbitApp $app,
        RegistrationSourceFacts $facts,
        StoragePath $destination,
        ?string $requestedRoot,
    ): void {
        if (
            $instance->app_id !== $app->id
            || $instance->source_layout !== $facts->layout->value
            || $instance->registration_repository_identity !== null
            && $instance->registration_repository_identity !== $facts->repositoryIdentity
            || $instance->registration_source_digest !== null
            && $instance->registration_source_digest !== $facts->sourceDigest
            || $instance->checkout_path !== $destination->value
            || $requestedRoot !== null
            && ($instance->root ?? $app->root) !== $requestedRoot
        ) {
            throw $this->conflict(
                'instance.registration_conflict',
                'Registration retry input conflicts with retained evidence.',
            );
        }
    }

    private function assertRetryRequest(
        AppInstance $instance,
        bool $primary,
        bool $includeWorktrees,
    ): void {
        if (
            $instance->registration_request_id === null
            || $instance->registration_primary !== $primary
            || $instance->registration_include_worktrees !== $includeWorktrees
        ) {
            throw $this->conflict(
                'instance.registration_conflict',
                'Registration retry input conflicts with retained evidence.',
            );
        }
    }

    private function completeMember(
        AppInstance $instance,
        RegistrationSourceFacts $facts,
        ?string $domain,
    ): AppInstance {
        if ($instance->registration_completed_at !== null && $instance->status === AppInstanceState::Active) {
            $retryHostname = $this->retainedRouteDomain($instance, $domain);
            $this->provisioner->reserve($instance, $retryHostname);
            $completed = $this->provisioner->complete($instance, $retryHostname);
            $this->sources->discardLaravelRollback($completed);
            $completed->update(['failed_step' => null, 'error_code' => null]);

            return $completed->refresh()->load('routes.targets');
        }

        if (
            $instance->status === AppInstanceState::Active
            && $instance->provisioning_step === 'active'
            && $instance->registration_request_id !== null
        ) {
            $this->provisioner->reserve($instance, $domain);

            return $this->finishPublishedRegistration(
                $this->provisioner->complete($instance, $domain),
            );
        }

        DB::transaction(static function () use ($instance, $facts): void {
            $locked = AppInstance::query()->lockForUpdate()->findOrFail($instance->id);
            $locked->update([
                'name' => $instance->name,
                'source_layout' => $facts->layout,
                'checkout_path' => $instance->checkout_path,
                'branch' => $facts->branch,
                'branch_override' => null,
                'starting_commit' => $facts->commit,
                'registration_original_path' => $facts->path,
                'registration_repository_identity' => $facts->repositoryIdentity,
                'registration_source_digest' => $facts->sourceDigest,
                'registration_detached' => $facts->detached,
                'status' => AppInstanceState::SourceResolved,
                'failed_step' => null,
                'error_code' => null,
            ]);
        });

        $instance = $instance->refresh();
        $this->sources->prepareLaravelRollback($instance);

        try {
            $this->provisioner->reserve($instance, $domain);
            $completed = $this->provisioner->complete($instance, $domain);
        } catch (Throwable $exception) {
            $this->sources->restoreLaravelConfiguration($instance);

            throw $exception;
        }

        return $this->finishPublishedRegistration($completed);
    }

    private function retainedRouteDomain(AppInstance $instance, ?string $domain): ?string
    {
        if ($domain !== null) {
            return $domain;
        }

        $route = $instance->routes()->sole();

        return $route->provenance === RouteProvenance::Explicit ? $route->domain : null;
    }

    private function finishPublishedRegistration(AppInstance $completed): AppInstance
    {
        $completed->update([
            'registration_completed_at' => now(),
            'failed_step' => null,
            'error_code' => null,
        ]);
        $this->sources->discardLaravelRollback($completed);

        return $completed->refresh()->load('routes.targets');
    }

    private function assertPlacement(Node $node): void
    {
        if (
            $node->status !== LifecycleStatus::Active
            || $node->platform !== 'linux'
            || ! $node->roles()->where('role', RoleName::AppDev)->where('status', LifecycleStatus::Active)->exists()
        ) {
            throw new ResourceOperationException(
                'instance.caller_not_app_dev',
                'Registration requires an active app-dev caller Node.',
                422,
            );
        }
    }

    private function recordFailure(AppInstance $instance, Throwable $exception): void
    {
        $errorCode = property_exists($exception, 'errorCode') && is_string($exception->errorCode)
            ? $exception->errorCode
            : 'instance.registration_incomplete';
        AppInstance::query()
            ->whereKey($instance->id)
            ->update([
                'failed_step' => 'registration',
                'error_code' => $errorCode,
            ]);
    }

    private function conflict(string $code, string $message): ResourceOperationException
    {
        return new ResourceOperationException($code, $message, 409);
    }
}
