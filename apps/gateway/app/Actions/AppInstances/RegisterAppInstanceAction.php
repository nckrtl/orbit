<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Actions\Apps\CreateAppAction;
use App\Data\AppInstances\RegisterAppInstanceData;
use App\Data\Apps\CreateAppData;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppInstances\AppInstanceDestinationGuard;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DevelopmentAppInstanceConfigurator;
use App\Domain\AppInstances\DevelopmentAppInstanceProvisioner;
use App\Domain\AppInstances\Registration\RegistrationSourceFacts;
use App\Domain\AppInstances\Registration\RegistrationSourceManager;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Routes\RouteHostname;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\RelativeWebRoot;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * @mago-expect lint:too-many-methods,cyclomatic-complexity Registration keeps its fail-closed inference and durable retry gates together.
 * @mago-expect lint:kan-defect The coordinator keeps mutation, rollback, and retained retry states in one lifecycle.
 */
final readonly class RegisterAppInstanceAction
{
    /** @mago-expect lint:excessive-parameter-list Each collaborator owns one existing lifecycle boundary. */
    public function __construct(
        private RegistrationSourceManager $sources,
        private CreateAppAction $createApp,
        private ManagedUserAccountResolver $accounts,
        private StorageRootResolver $storageRoots,
        private NodeSettingsNormalizer $nodeSettings,
        private ManagedCheckoutOverlap $checkoutOverlap,
        private AppInstanceDestinationGuard $destinationGuard,
        private AppDevSourceOperationLock $sourceLock,
        private DevelopmentAppInstanceProvisioner $provisioner,
        private DevelopmentAppInstanceConfigurator $configuration,
    ) {}

    /** @return array{app: OrbitApp, primary: AppInstance, instances: list<AppInstance>, created: bool} */
    public function execute(Node $caller, RegisterAppInstanceData $data): array
    {
        $this->assertPlacement($caller);

        if ($data->hostname !== null) {
            RouteHostname::validate($data->hostname);
        }

        return $this->sourceLock->synchronized($caller->id, function () use ($caller, $data): array {
            [$retainedMember, $inspectedFacts] = $this->retainedMember($caller, $data);

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
                : $inspectedFacts ?? $this->sources->inspect(
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
                        $member['routeHostname'],
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

    /** @return array{AppInstance|null, list<RegistrationSourceFacts>|null} */
    private function retainedMember(Node $node, RegisterAppInstanceData $data): array
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
        $inspectedFacts = null;

        if (! $member instanceof AppInstance) {
            if ($data->appId === null) {
                $inspectedFacts = $this->sources->inspect(
                    $node,
                    $data->sourcePath,
                    $data->includeWorktrees,
                );
            }

            $member = $this->migrationAtPlannedDestination($node, $data, $inspectedFacts);
        }

        if (! $member instanceof AppInstance) {
            return [null, $inspectedFacts];
        }

        if (! $member->registration_primary) {
            throw $this->conflict(
                'instance.registration_conflict',
                'Registration retry input conflicts with retained evidence.',
            );
        }

        $this->assertRetainedEntryPath($node, $member, $data->sourcePath);

        return [$member, $inspectedFacts];
    }

    /** @param list<RegistrationSourceFacts>|null $inspectedFacts */
    private function migrationAtPlannedDestination(
        Node $node,
        RegisterAppInstanceData $data,
        ?array $inspectedFacts,
    ): ?AppInstance {
        $query = AppInstance::query()
            ->with('app')
            ->where('node_id', $node->id)
            ->whereNotNull('registration_request_id')
            ->where('registration_primary', true)
            ->where('migration_required', true)
            ->whereIn('registration_relocation_state', [
                'reserved',
                'relocating',
                'destination_verified',
                'original_cleanup',
                'relocated',
            ]);

        if ($data->appId !== null) {
            $query->where('app_id', $data->appId);
        } else {
            if ($inspectedFacts === null) {
                return null;
            }

            $primaryFacts = $this->primaryFacts($inspectedFacts, $data->sourcePath);
            $query->where('registration_repository_identity', $primaryFacts->repositoryIdentity);
        }

        $matches = $query
            ->get()
            ->filter(
                fn (AppInstance $instance): bool => $this->retainedDestination($node, $instance) === $data->sourcePath,
            );

        if ($matches->count() > 1) {
            throw $this->conflict(
                'instance.registration_evidence_invalid',
                'Several retained manual migrations identify the requested managed destination.',
            );
        }

        return $matches->first();
    }

    private function assertRetainedEntryPath(Node $node, AppInstance $instance, string $submittedPath): void
    {
        if ($submittedPath === $instance->registration_original_path) {
            return;
        }

        $destination = $this->retainedDestination($node, $instance);
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
            $path = $this->authoritativeSourcePath($node, $instance, $fact);

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
        Node $node,
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
            ? $this->retainedDestination($node, $instance)
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
                $destination = $this->retainedDestination($node, $instance);
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

        $expectedPaths = $data->includeWorktrees
            ? $primary->registration_worktree_paths
            : [$primary->registration_original_path];

        if (
            ! is_array($expectedPaths)
            || array_filter($expectedPaths, static fn (mixed $path): bool => ! is_string($path)) !== []
        ) {
            throw $this->conflict(
                'instance.registration_evidence_invalid',
                'Retained registration evidence does not contain the complete requested source set.',
            );
        }

        /** @var list<string> $expectedPaths */
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
        $worktreePaths = $instance->registration_worktree_paths;

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

        /** @var list<string> $worktreePaths */
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
            worktreePaths: array_values($worktreePaths),
            sourceDigest: $instance->registration_source_digest,
        );
    }

    private function retainedDestination(Node $node, AppInstance $instance): string
    {
        if (! $instance->migration_required) {
            return $instance->checkout_path;
        }

        $app = $instance->relationLoaded('app') ? $instance->app : $instance->app()->firstOrFail();

        if (! $app instanceof OrbitApp) {
            throw $this->conflict(
                'instance.registration_evidence_invalid',
                'Retained manual migration App evidence is incomplete.',
            );
        }

        $recovery = $instance->registration_migration_recovery;
        $planned = is_array($recovery) ? $recovery['planned'] ?? null : null;

        if (
            $planned !== null
            && (! is_array($planned)
            || ! is_string($planned['name'] ?? null)
            || ! is_string($planned['checkout_path'] ?? null))
        ) {
            throw $this->conflict(
                'instance.registration_evidence_invalid',
                'Retained manual migration planned placement is incomplete or conflicting.',
            );
        }

        $plannedName = is_array($planned) ? $planned['name'] : null;
        $plannedCheckoutPath = is_array($planned) ? $planned['checkout_path'] : null;
        assert(is_string($plannedName) || $plannedName === null);
        assert(is_string($plannedCheckoutPath) || $plannedCheckoutPath === null);
        $name = 'default';
        $account = $this->accounts->resolve($node);
        $roots = $this->storageRoots->resolveApps(
            $this->nodeSettings->fromStored($node->settings),
            $this->nodeSettings->legacyFromStored($node->settings),
            $account,
        );
        $destination = $roots->instance->append($app->slug, $name)->value;
        if ($planned !== null && ($plannedName !== $name || $plannedCheckoutPath !== $destination)) {
            throw $this->conflict(
                'instance.registration_evidence_invalid',
                'Retained manual migration planned placement is incomplete or conflicting.',
            );
        }

        return $destination;
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

            return [$app, false];
        }

        $slug = $data->appSlug ?? $facts->inferredSlug;
        $defaultBranch = $data->defaultBranch ?? $facts->defaultBranch;
        $root = $data->root ?? $facts->inferredRoot;

        if ($slug !== $facts->inferredSlug) {
            throw $this->conflict(
                'app.slug_conflict',
                'The confirmed App slug conflicts with verified repository evidence.',
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

        $result = $this->createApp->execute(new CreateAppData(
            name: $data->appName ?? $slug,
            slug: $slug,
            repositoryUrl: $facts->repositoryUrl,
            defaultBranch: GitBranchName::validate($defaultBranch),
            root: RelativeWebRoot::validate($root),
            defaults: null,
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
     * @param list<RegistrationSourceFacts> $facts
     * @return list<array{appInstance: AppInstance, facts: RegistrationSourceFacts, routeHostname: string|null}>
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
            $this->nodeSettings->legacyFromStored($node->settings),
            $account,
        );
        $rootOverride = $data->root !== null && $data->root !== $app->root ? $data->root : null;
        $retainedRequestId = AppInstance::query()
            ->where('node_id', $node->id)
            ->where('registration_original_path', $primary->path)
            ->value('registration_request_id');
        $requestId = is_string($retainedRequestId) ? $retainedRequestId : (string) Str::uuid();
        /** @var list<array{facts: RegistrationSourceFacts, name: string, destination: StoragePath, instance: AppInstance|null, primary: bool, routeHostname: string|null, routeProvenance: string, backfillRouteIntent: bool}> $proposals */
        $proposals = [];
        $names = [];
        $destinations = [];

        foreach ($facts as $fact) {
            $instance = AppInstance::query()
                ->where('node_id', $node->id)
                ->where('registration_original_path', $fact->path)
                ->first();

            if (! $instance instanceof AppInstance && $fact === $primary) {
                $instance = AppInstance::query()
                    ->where('app_id', $app->id)
                    ->where('node_id', $node->id)
                    ->where('migration_required', true)
                    ->where('checkout_path', $fact->path)
                    ->first();
            }

            $explicitName = $fact === $primary ? $data->instanceName : null;
            $name = match (true) {
                $instance instanceof AppInstance && $instance->migration_required => $this->migrationInstanceName(
                    $explicitName,
                ),
                $instance instanceof AppInstance && $instance->registration_request_id !== null => $this
                    ->retainedInstanceName($instance, $explicitName),
                default => $this->instanceName($app, $fact, $explicitName),
            };
            $destination = $roots->instance->append($app->slug, $name);

            if ($instance instanceof AppInstance && $instance->migration_required) {
                $this->assertMigrationProposal($node, $instance, $name, $destination);
            }

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
                $fact === $primary ? $data->hostname : null,
            );

            $proposals[] = [
                'facts' => $fact,
                'name' => $name,
                'destination' => $destination,
                'instance' => $instance,
                'primary' => $fact === $primary,
                'routeHostname' => $routeIntent['hostname'],
                'routeProvenance' => $routeIntent['provenance'],
                'backfillRouteIntent' => $routeIntent['backfill'],
            ];
        }

        /** @var list<array{appInstance: AppInstance, facts: RegistrationSourceFacts, routeHostname: string|null}> $reserved */
        $reserved = DB::transaction(function () use ($proposals, $app, $node, $rootOverride, $requestId, $data): array {
            $reserved = [];

            foreach ($proposals as $proposal) {
                $fact = $proposal['facts'];
                $instance = $proposal['instance'];

                if ($instance instanceof AppInstance) {
                    if ($instance->registration_request_id === null) {
                        $recovery = $instance->migration_required
                            ? $this->captureMigrationRecovery(
                                $instance,
                                $proposal['name'],
                                $proposal['destination']->value,
                            )
                            : null;
                        $instance->fill($this->registrationEvidence(
                            $fact,
                            requestId: $requestId,
                            primary: $proposal['primary'],
                            includeWorktrees: $data->includeWorktrees,
                            routeIntent: [
                                'hostname' => $proposal['routeHostname'],
                                'provenance' => $proposal['routeProvenance'],
                            ],
                        ));
                        $instance->registration_migration_recovery = $recovery;
                        $instance->save();
                    } elseif ($proposal['backfillRouteIntent']) {
                        $instance->update([
                            'registration_route_hostname' => $proposal['routeHostname'],
                            'registration_route_provenance' => $proposal['routeProvenance'],
                        ]);
                    }

                    if (
                        $instance->registration_request_id !== null
                        && $instance->migration_required
                        && $instance->registration_migration_recovery === null
                    ) {
                        $instance->update([
                            'registration_migration_recovery' => $this->captureMigrationRecovery(
                                $instance,
                                $proposal['name'],
                                $proposal['destination']->value,
                            ),
                        ]);
                    } elseif (
                        $instance->migration_required
                        && $instance->registration_migration_recovery !== null
                    ) {
                        $recovery = $this->migrationRecovery($instance);

                        if ($recovery !== null && ! isset($recovery['planned'])) {
                            $instance->update([
                                'registration_migration_recovery' => [
                                    ...$recovery,
                                    'planned' => [
                                        'name' => $proposal['name'],
                                        'checkout_path' => $proposal['destination']->value,
                                    ],
                                ],
                            ]);
                        }
                    }
                    $instance->name = $proposal['name'];
                    $instance->checkout_path = $proposal['destination']->value;
                    $reserved[] = [
                        'appInstance' => $instance,
                        'facts' => $fact,
                        'routeHostname' => $proposal['routeHostname'],
                    ];

                    continue;
                }

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
                            'hostname' => $proposal['routeHostname'],
                            'provenance' => $proposal['routeProvenance'],
                        ],
                    ),
                    'status' => AppInstanceState::Reserved,
                ]);
                $reserved[] = [
                    'appInstance' => $instance,
                    'facts' => $fact,
                    'routeHostname' => $proposal['routeHostname'],
                ];
            }

            return $reserved;
        });

        return $reserved;
    }

    /**
     * @param list<array{appInstance: AppInstance, facts: RegistrationSourceFacts, routeHostname: string|null}> $members
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

    /** @param list<string> $paths */
    private function sortedPaths(array $paths): array
    {
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * @param array{hostname: string|null, provenance: string} $routeIntent
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
            'registration_route_hostname' => $routeIntent['hostname'],
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

    private function migrationInstanceName(?string $explicit): string
    {
        if ($explicit !== null && $explicit !== 'default') {
            throw $this->conflict(
                'instance.registration_conflict',
                'The retained default migration has the reserved default AppInstance identity.',
            );
        }

        return 'default';
    }

    private function assertMigrationProposal(
        Node $node,
        AppInstance $instance,
        string $name,
        StoragePath $destination,
    ): void {
        if (
            $name !== 'default'
            || $instance->registration_request_id !== null
            && $this->retainedDestination($node, $instance) !== $destination->value
        ) {
            throw $this->conflict(
                'instance.registration_conflict',
                'Registration retry input conflicts with retained default migration placement.',
            );
        }
    }

    /** @return array{hostname: string|null, provenance: string, backfill: bool} */
    private function registrationRouteIntent(?AppInstance $instance, ?string $requestedHostname): array
    {
        $requestedHostname = $requestedHostname === null
            ? null
            : RouteHostname::validate($requestedHostname);

        if (! $instance instanceof AppInstance) {
            return [
                'hostname' => $requestedHostname,
                'provenance' => $requestedHostname === null
                    ? RouteProvenance::Generated->value
                    : RouteProvenance::Explicit->value,
                'backfill' => false,
            ];
        }

        $hostname = $instance->registration_route_hostname;
        $provenance = $instance->registration_route_provenance;
        $backfill = false;

        if ($hostname === null && $provenance === null) {
            $legacyIntent = $this->legacyRegistrationRouteIntent($instance);
            $hostname = $legacyIntent['hostname'];
            $provenance = $legacyIntent['provenance'];
            $backfill = $instance->registration_request_id !== null;
        }

        $this->assertValidRegistrationRouteIntent($hostname, $provenance);
        assert(is_string($provenance));

        if (
            $requestedHostname !== null
            && ($provenance !== RouteProvenance::Explicit->value
            || $hostname !== $requestedHostname)
        ) {
            throw $this->conflict(
                'instance.registration_conflict',
                'Registration retry input conflicts with retained Route hostname intent.',
            );
        }

        return [
            'hostname' => $hostname,
            'provenance' => $provenance,
            'backfill' => $backfill,
        ];
    }

    /** @return array{hostname: string|null, provenance: string} */
    private function legacyRegistrationRouteIntent(AppInstance $instance): array
    {
        $recovery = $this->migrationRecovery($instance);
        $routeIntent = $recovery['route'] ?? null;

        if ($routeIntent === null) {
            if ($instance->routes()->count() !== 1) {
                throw $this->invalidRegistrationRouteIntent();
            }

            $route = $instance->routes()->sole();
            $routeIntent = [
                'hostname' => $route->hostname,
                'provenance' => $route->provenance->value,
            ];
        }

        if (! RouteHostname::isValid($routeIntent['hostname'])) {
            throw $this->invalidRegistrationRouteIntent();
        }

        return [
            'hostname' => $routeIntent['provenance'] === RouteProvenance::Explicit->value
                ? RouteHostname::normalize($routeIntent['hostname'])
                : null,
            'provenance' => $routeIntent['provenance'],
        ];
    }

    private function assertValidRegistrationRouteIntent(?string $hostname, ?string $provenance): void
    {
        if (
            $provenance === RouteProvenance::Explicit->value
            && $hostname !== null
            && RouteHostname::isValid($hostname)
            && RouteHostname::normalize($hostname) === $hostname
            || $provenance === RouteProvenance::Generated->value
            && $hostname === null
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
            || ! $instance->migration_required
            && $instance->checkout_path !== $destination->value
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
            && ! $instance->migration_required
            || $instance->registration_request_id !== null
            && ($instance->registration_primary !== $primary
            || $instance->registration_include_worktrees !== $includeWorktrees)
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
        ?string $hostname,
    ): AppInstance {
        if ($instance->registration_completed_at !== null && $instance->status === AppInstanceState::Active) {
            $retryHostname = $this->retainedRouteHostname($instance, $hostname);
            $this->provisioner->reserve($instance, $retryHostname);
            $completed = $this->provisioner->complete($instance, $retryHostname);
            $this->sources->discardLaravelRollback($completed);
            $completed->update(['failed_step' => null, 'error_code' => null]);

            return $completed->refresh()->load('routes.targets');
        }

        $migration = $instance->migration_required;
        $recovery = $this->migrationRecovery($instance);
        $recoveringMigration = $migration || $recovery !== null;
        $provisioningHostname = $hostname;

        if ($migration && $recovery === null) {
            $recovery = $this->captureMigrationRecovery(
                $instance,
                $instance->name,
                $instance->checkout_path,
            );
        }

        if ($recovery !== null && $provisioningHostname === null) {
            $provisioningHostname = $recovery['route']['provenance'] === RouteProvenance::Explicit->value
                ? $recovery['route']['hostname']
                : null;
        }

        if (
            ! $migration
            && $instance->status === AppInstanceState::Active
            && $instance->provisioning_step === 'active'
            && $instance->registration_request_id !== null
        ) {
            $provisioningHostname = $this->retainedRouteHostname($instance, $provisioningHostname);
            $this->provisioner->reserve($instance, $provisioningHostname);

            return $this->finishPublishedRegistration(
                $this->provisioner->complete($instance, $provisioningHostname),
            );
        }

        DB::transaction(static function () use ($instance, $facts, $recovery, $recoveringMigration): void {
            $locked = AppInstance::query()->lockForUpdate()->findOrFail($instance->id);
            $locked->update([
                'name' => $instance->name,
                'source_layout' => $facts->layout,
                'checkout_path' => $instance->checkout_path,
                'branch' => $facts->branch,
                'branch_override' => null,
                'migration_required' => false,
                'starting_commit' => $facts->commit,
                'registration_original_path' => $facts->path,
                'registration_repository_identity' => $facts->repositoryIdentity,
                'registration_source_digest' => $facts->sourceDigest,
                'registration_detached' => $facts->detached,
                'registration_migration_recovery' => $recovery,
                ...(
                    $recoveringMigration
                        ? [
                            'selected_php_version' => null,
                            'source_is_laravel' => null,
                            'provisioning_step' => null,
                        ] : []
                ),
                'status' => AppInstanceState::SourceResolved,
                'failed_step' => null,
                'error_code' => null,
            ]);
        });

        $instance = $instance->refresh();
        $this->sources->prepareLaravelRollback($instance);

        try {
            $this->provisioner->reserve($instance, $provisioningHostname);
            $completed = $this->provisioner->complete($instance, $provisioningHostname);
        } catch (Throwable $exception) {
            $this->sources->restoreLaravelConfiguration($instance);

            if ($recoveringMigration && $recovery !== null) {
                $this->sources->restoreOriginal($instance, $facts);
                AppInstance::query()
                    ->whereKey($instance->id)
                    ->update([
                        ...$recovery['app_instance'],
                        'registration_migration_recovery' => null,
                    ]);
            }

            throw $exception;
        }

        return $this->finishPublishedRegistration($completed);
    }

    private function retainedRouteHostname(AppInstance $instance, ?string $hostname): ?string
    {
        if ($hostname !== null) {
            return $hostname;
        }

        $route = $instance->routes()->sole();

        return $route->provenance === RouteProvenance::Explicit ? $route->hostname : null;
    }

    private function finishPublishedRegistration(AppInstance $completed): AppInstance
    {
        $completed->update([
            'registration_completed_at' => now(),
            'registration_migration_recovery' => null,
            'failed_step' => null,
            'error_code' => null,
        ]);
        $this->sources->discardLaravelRollback($completed);

        return $completed->refresh()->load('routes.targets');
    }

    /**
     * @return array{
     *     app_instance: array<string, mixed>,
     *     route: array{id: int, hostname: string, provenance: string},
     *     planned: array{name: string, checkout_path: string}
     * }
     */
    private function captureMigrationRecovery(
        AppInstance $instance,
        string $plannedName,
        string $plannedCheckoutPath,
    ): array {
        $route = $instance->routes()->sole();
        $fields = [
            'name',
            'source_layout',
            'checkout_path',
            'root',
            'branch',
            'branch_override',
            'migration_required',
            'starting_commit',
            'selected_php_version',
            'source_is_laravel',
            'provisioning_step',
            'status',
        ];
        $original = [];

        foreach ($fields as $field) {
            $original[$field] = $instance->getRawOriginal($field);
        }

        return [
            'app_instance' => $original,
            'route' => [
                'id' => $route->id,
                'hostname' => $route->hostname,
                'provenance' => $route->provenance->value,
            ],
            'planned' => [
                'name' => $plannedName,
                'checkout_path' => $plannedCheckoutPath,
            ],
        ];
    }

    /**
     * @return array{
     *     app_instance: array<string, mixed>,
     *     route: array{id: int, hostname: string, provenance: string},
     *     planned?: array{name: string, checkout_path: string}
     * }|null
     */
    private function migrationRecovery(AppInstance $instance): ?array
    {
        $recovery = $instance->registration_migration_recovery;

        if ($recovery === null) {
            return null;
        }

        $original = $recovery['app_instance'] ?? null;
        $routeIntent = $recovery['route'] ?? null;
        $planned = $recovery['planned'] ?? null;
        $route = is_array($routeIntent) && is_int($routeIntent['id'] ?? null)
            ? $instance->routes()->whereKey($routeIntent['id'])->first()
            : null;

        if (
            ! is_array($original)
            || ! is_array($routeIntent)
            || ! is_string($routeIntent['hostname'] ?? null)
            || ! in_array(
                $routeIntent['provenance'] ?? null,
                [RouteProvenance::Explicit->value, RouteProvenance::Generated->value],
                strict: true,
            )
            || $planned !== null
            && (! is_array($planned)
            || ! is_string($planned['name'] ?? null)
            || ! is_string($planned['checkout_path'] ?? null))
            || $route === null
            || $route->hostname !== $routeIntent['hostname']
            || $route->provenance->value !== $routeIntent['provenance']
            || array_diff([
                'name',
                'source_layout',
                'checkout_path',
                'root',
                'branch',
                'branch_override',
                'migration_required',
                'starting_commit',
                'selected_php_version',
                'source_is_laravel',
                'provisioning_step',
                'status',
            ], array_keys($original)) !== []
        ) {
            throw $this->conflict(
                'instance.registration_evidence_invalid',
                'Retained manual migration recovery evidence is incomplete or conflicting.',
            );
        }

        /** @var array{app_instance: array<string, mixed>, route: array{id: int, hostname: string, provenance: string}, planned?: array{name: string, checkout_path: string}} $recovery */
        return $recovery;
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
