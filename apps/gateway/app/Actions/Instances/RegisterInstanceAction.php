<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Data\Instances\RegisterInstanceData;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\Instances\DevelopmentInstanceConfigurator;
use App\Domain\Instances\DevelopmentInstanceProvisioner;
use App\Domain\Instances\InstanceDestinationGuard;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\Registration\RegistrationSourceFacts;
use App\Domain\Instances\Registration\RegistrationSourceManager;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Projects\DevelopmentNodeExclusion;
use App\Domain\Projects\ProjectApps;
use App\Domain\Routes\RouteDomain;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class RegisterInstanceAction
{
    public function __construct(
        private RegistrationSourceManager $sources,
        private ManagedUserAccountResolver $accounts,
        private StorageRootResolver $storageRoots,
        private NodeSettingsNormalizer $nodeSettings,
        private ManagedCheckoutOverlap $checkoutOverlap,
        private InstanceDestinationGuard $destinationGuard,
        private AppDevSourceOperationLock $sourceLock,
        private DevelopmentProjectionOperationLock $projectionLock,
        private DevelopmentInstanceProvisioner $provisioner,
        private DevelopmentInstanceConfigurator $configuration,
        private ?RunInstanceSetupAction $setup = null,
    ) {}

    /** @return array{project: Project, primary: Instance, instances: list<Instance>} */
    public function execute(Node $caller, RegisterInstanceData $data): array
    {
        $result = $this->performRegistration($caller, $data);

        if ($data->runSetup && ! $result['primary']->placedOnAppProd()) {
            ($this->setup ?? app(RunInstanceSetupAction::class))->execute($result['primary']);
        }

        return $result;
    }

    /** @return array{project: Project, primary: Instance, instances: list<Instance>} */
    private function performRegistration(Node $caller, RegisterInstanceData $data): array
    {
        $this->assertPlacement($caller);
        app(MigrateAppRuntimeAction::class)->execute($caller);

        if ($data->domain !== null) {
            RouteDomain::validate($data->domain);
        }

        return $this->sourceLock->synchronized($caller->id, function () use ($caller, $data): array {
            $retainedMember = $this->retainedMember($caller, $data);

            if (
                $retainedMember instanceof Instance
                && ! $retainedMember->registration_primary
            ) {
                throw $this->conflict(
                    'instance.registration_conflict',
                    'Registration retry input conflicts with retained evidence.',
                );
            }

            $retainedPrimary = $retainedMember;
            $facts = $retainedPrimary instanceof Instance
                ? $this->retainedFacts($retainedPrimary, $data)
                : $this->sources->inspect(
                    $caller,
                    $data->sourcePath,
                    $data->includeWorktrees,
                );

            if (! $retainedPrimary instanceof Instance) {
                $this->assertSourcesNotRetained($caller, $facts);
            }

            $primaryFacts = $retainedPrimary instanceof Instance
                ? $facts[0]
                : $this->primaryFacts($facts, $data->sourcePath);
            $project = $this->resolveProject($primaryFacts, $data);
            $this->preflightSources($caller, $project, $facts, $retainedPrimary, $data->sourcePath, $this->requestedOverrides($project, $data));
            [$members, $instances] = $this->projectionLock->run(function () use (
                $caller,
                $project,
                $facts,
                $primaryFacts,
                $data,
            ): array {
                $members = $this->reserveMembers($caller, $project, $facts, $primaryFacts, $data);

                try {
                    if ($this->needsRelocation($members)) {
                        $this->sources->relocateSet(array_map(
                            static fn (array $member): array => [
                                'instance' => $member['instance'],
                                'facts' => $member['facts'],
                            ],
                            $members,
                        ));
                    }
                    $instances = [];

                    foreach ($members as $member) {
                        $instances[] = $this->completeMember(
                            $member['instance'],
                            $member['facts'],
                            $member['routeDomain'],
                        );
                    }
                } catch (Throwable $exception) {
                    foreach ($members as $member) {
                        $this->recordFailure($member['instance'], $exception);
                    }

                    throw new ResourceOperationException(
                        errorCode: 'instance.registration_incomplete',
                        message: 'Instance registration is incomplete and can be retried.',
                        status: 502,
                        previous: $exception,
                    );
                }

                return [$members, $instances];
            });

            $primary = collect($instances)->first(
                static fn (Instance $instance): bool => (
                    $instance->registration_original_path === $primaryFacts->path
                ),
            );
            assert($primary instanceof Instance);

            return [
                'project' => $project->refresh(),
                'primary' => $primary,
                'instances' => $instances,
            ];
        });
    }

    private function retainedMember(Node $node, RegisterInstanceData $data): ?Instance
    {
        $matches = Instance::query()
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

        if (! $member instanceof Instance) {
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

    private function assertRetainedEntryPath(Instance $instance, string $submittedPath): void
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
        $retained = Instance::query()
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

    /**
     * @param  list<RegistrationSourceFacts>  $facts
     * @param  array<string, array{path: string, web_root: ?string}>|null  $requestedOverrides
     */
    private function preflightSources(
        Node $node,
        Project $project,
        array $facts,
        ?Instance $retainedPrimary,
        string $submittedPath,
        ?array $requestedOverrides,
    ): void {
        foreach ($facts as $fact) {
            $instance = Instance::query()
                ->where('node_id', $node->id)
                ->where('registration_original_path', $fact->path)
                ->first();
            $path = $this->authoritativeSourcePath($instance, $fact);

            if ($retainedPrimary instanceof Instance) {
                if ($instance instanceof Instance && $instance->registration_relocation_state === 'relocating') {
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

            $candidate = new Instance([
                'project_id' => $project->id,
                'node_id' => $node->id,
                'checkout_path' => $path,
                'app_overrides' => $instance instanceof Instance ? $instance->app_overrides : ($requestedOverrides ?? []),
            ]);
            $candidate->setRelation('project', $project);
            $candidate->setRelation('node', $node);
            $this->configuration->inspect($candidate);
        }
    }

    private function authoritativeSourcePath(
        ?Instance $instance,
        RegistrationSourceFacts $facts,
    ): string {
        if (! $instance instanceof Instance) {
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
        Instance $instance,
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
    private function retainedFacts(Instance $primary, RegisterInstanceData $data): array
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

        $instances = Instance::query()
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
                static fn (Instance $instance): bool => (
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

    private function retainedFact(Instance $instance): RegistrationSourceFacts
    {
        $layout = InstanceSourceLayout::tryFrom($instance->source_layout);
        $worktreePaths = $instance->getAttribute('registration_worktree_paths');

        if (
            ! $layout instanceof InstanceSourceLayout
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

    /**
     * Registration adopts a checkout only for an existing Project
     * ([Projects](/reference/projects#registration-needs-a-project)).
     */
    private function resolveProject(RegistrationSourceFacts $facts, RegisterInstanceData $data): Project
    {
        $byRepository = Project::query()
            ->where('repository_identity', $facts->repositoryIdentity)
            ->get();

        if ($byRepository->count() > 1) {
            throw $this->conflict(
                'project.repository_identity_conflict',
                'Several Projects own the requested repository identity.',
            );
        }

        $explicit = $data->projectId === null ? null : Project::query()->findOrFail($data->projectId);
        $resolved = $byRepository->first();

        if ($explicit instanceof Project && $explicit->repository_identity !== $facts->repositoryIdentity) {
            throw $this->conflict(
                'project.repository_identity_conflict',
                'The selected Project owns a different repository identity.',
            );
        }

        if ($explicit instanceof Project && $resolved instanceof Project && ! $explicit->is($resolved)) {
            throw $this->conflict('project.repository_identity_conflict', 'The repository is owned by a different Project.');
        }

        $project = $explicit ?? $resolved;

        if (! $project instanceof Project) {
            throw new ResourceOperationException(
                'instance.project_missing',
                "No Project owns repository [{$facts->repositoryUrl}]. Create it with `orbit project:create` first.",
                422,
            );
        }

        $this->requestedOverrides($project, $data);

        return $project;
    }

    /**
     * @param  list<RegistrationSourceFacts>  $facts
     * @return list<array{instance: Instance, facts: RegistrationSourceFacts, routeDomain: string|null}>
     */
    private function reserveMembers(
        Node $node,
        Project $project,
        array $facts,
        RegistrationSourceFacts $primary,
        RegisterInstanceData $data,
    ): array {
        $account = $this->accounts->resolve($node);
        $roots = $this->storageRoots->resolveApps(
            $this->nodeSettings->fromStored($node->settings),
            $account,
        );
        $overrides = $this->requestedOverrides($project, $data);
        $retainedRequestId = Instance::query()
            ->where('node_id', $node->id)
            ->where('registration_original_path', $primary->path)
            ->value('registration_request_id');
        $requestId = is_string($retainedRequestId) ? $retainedRequestId : (string) Str::uuid();
        $proposals = [];
        $names = [];
        $destinations = [];

        foreach ($facts as $fact) {
            $instance = Instance::query()
                ->where('node_id', $node->id)
                ->where('registration_original_path', $fact->path)
                ->first();

            $explicitName = $fact === $primary ? $data->instanceName : null;
            $name = $instance instanceof Instance && $instance->registration_request_id !== null
                ? $this->retainedInstanceName($instance, $explicitName)
                : $this->instanceName($project, $fact, $explicitName);
            $destination = $roots->append($project->slug, $name);

            if (isset($names[$name]) || isset($destinations[$destination->value])) {
                throw $this->conflict(
                    'instance.identity_conflict',
                    'The requested source set contains conflicting Instance identities or placements.',
                );
            }

            $names[$name] = true;
            $destinations[$destination->value] = true;
            $sourcePath = $instance instanceof Instance
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
                ignoreInstanceId: $instance?->id,
            );

            if (! $instance instanceof Instance) {
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
                    $project,
                    $fact,
                    $destination,
                    $overrides,
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

        $reserved = DB::transaction(function () use ($proposals, $project, $node, $overrides, $requestId, $data): array {
            $reserved = [];

            foreach ($proposals as $proposal) {
                $fact = $proposal['facts'];
                $instance = $proposal['instance'];

                if ($instance instanceof Instance) {
                    if ($proposal['backfillRouteIntent']) {
                        $instance->update([
                            'registration_route_domain' => $proposal['routeDomain'],
                            'registration_route_provenance' => $proposal['routeProvenance'],
                        ]);
                    }
                    app(SelectInstanceSeedAction::class)->execute($instance);
                    $instance->name = $proposal['name'];
                    $instance->checkout_path = $proposal['destination']->value;
                    $reserved[] = [
                        'instance' => $instance,
                        'facts' => $fact,
                        'routeDomain' => $proposal['routeDomain'],
                    ];

                    continue;
                }

                app(DevelopmentNodeExclusion::class)->assertAvailable($project, $node);
                $instance = Instance::query()->create([
                    'project_id' => $project->id,
                    'node_id' => $node->id,
                    'name' => $proposal['name'],
                    'source_layout' => $fact->layout,
                    'checkout_path' => $proposal['destination']->value,
                    'app_overrides' => $overrides ?? [],
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
                    'status' => InstanceState::Reserved,
                ]);
                app(SelectInstanceSeedAction::class)->execute($instance);
                $reserved[] = [
                    'instance' => $instance,
                    'facts' => $fact,
                    'routeDomain' => $proposal['routeDomain'],
                ];
            }

            return $reserved;
        });

        return $reserved;
    }

    /**
     * @param  list<array{instance: Instance, facts: RegistrationSourceFacts, routeDomain: string|null}>  $members
     */
    private function needsRelocation(array $members): bool
    {
        foreach ($members as $member) {
            $instance = $member['instance'];

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

    private function instanceName(Project $project, RegistrationSourceFacts $facts, ?string $explicit): string
    {
        $derived = basename($facts->path);

        if (
            $facts->layout->value === 'checkout'
            && $derived === $project->slug
            && $facts->branch === $project->default_branch
        ) {
            if ($explicit !== null && $explicit !== 'default') {
                throw $this->conflict(
                    'instance.identity_conflict',
                    'This source has the reserved default Instance identity.',
                );
            }

            return 'default';
        }

        $name = $explicit ?? $derived;

        if (preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $name) !== 1 || strlen($name) > 63) {
            throw new ResourceOperationException(
                'instance.name_invalid',
                'The Instance name must be confirmed as a lowercase DNS label.',
                422,
            );
        }

        return $name;
    }

    private function retainedInstanceName(Instance $instance, ?string $explicit): string
    {
        if ($explicit !== null && $explicit !== $instance->name) {
            throw $this->conflict(
                'instance.registration_conflict',
                'Registration retry input conflicts with retained Instance identity.',
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
    private function registrationRouteIntent(?Instance $instance, ?string $requestedHostname): array
    {
        $requestedHostname = $requestedHostname === null
            ? null
            : RouteDomain::validate($requestedHostname);

        if (! $instance instanceof Instance) {
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

    private function currentRouteIsAuthoritative(Instance $instance): bool
    {
        return $instance->registration_completed_at !== null
            || $instance->status === InstanceState::Active
            && $instance->provisioning_step === 'active';
    }

    /**
     * @return array{
     *     domain: string|null,
     *     provenance: string
     * }
     */
    private function currentRegistrationRouteIntent(Instance $instance): array
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
        Instance $instance,
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
    private function legacyRegistrationRouteIntent(Instance $instance): array
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

    /**
     * Registration inherits every Project app unless the caller sends an override map.
     *
     * @return array<string, array{path: string, web_root: ?string}>|null
     */
    private function requestedOverrides(Project $project, RegisterInstanceData $data): ?array
    {
        return $data->appOverrides === null ? null : ProjectApps::overrides($project->configuredApps(), $data->appOverrides);
    }

    /** @param array<string, array{path: string, web_root: ?string}>|null $requestedOverrides */
    private function assertRetry(
        Instance $instance,
        Project $project,
        RegistrationSourceFacts $facts,
        StoragePath $destination,
        ?array $requestedOverrides,
    ): void {
        if (
            $instance->project_id !== $project->id
            || $instance->source_layout !== $facts->layout->value
            || $instance->registration_repository_identity !== null
            && $instance->registration_repository_identity !== $facts->repositoryIdentity
            || $instance->registration_source_digest !== null
            && $instance->registration_source_digest !== $facts->sourceDigest
            || $instance->checkout_path !== $destination->value
            || $requestedOverrides !== null
            && ProjectApps::overrides($project->configuredApps(), $instance->app_overrides ?? []) !== $requestedOverrides
        ) {
            throw $this->conflict(
                'instance.registration_conflict',
                'Registration retry input conflicts with retained evidence.',
            );
        }
    }

    private function assertRetryRequest(
        Instance $instance,
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
        Instance $instance,
        RegistrationSourceFacts $facts,
        ?string $domain,
    ): Instance {
        if ($instance->registration_completed_at !== null && $instance->status === InstanceState::Active) {
            $retryHostname = $this->retainedRouteDomain($instance, $domain);
            $this->provisioner->reserve($instance, $retryHostname);
            $completed = $this->provisioner->complete($instance, $retryHostname);
            $this->sources->discardLaravelRollback($completed);
            $completed->update(['failed_step' => null, 'error_code' => null]);

            return $completed->refresh()->load('routes.targets');
        }

        if (
            $instance->status === InstanceState::Active
            && $instance->provisioning_step === 'active'
            && $instance->registration_request_id !== null
        ) {
            $this->provisioner->reserve($instance, $domain);

            return $this->finishPublishedRegistration(
                $this->provisioner->complete($instance, $domain),
            );
        }

        DB::transaction(static function () use ($instance, $facts): void {
            $locked = Instance::query()->lockForUpdate()->findOrFail($instance->id);
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
                'status' => InstanceState::SourceResolved,
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

    private function retainedRouteDomain(Instance $instance, ?string $domain): ?string
    {
        if ($domain !== null) {
            return $domain;
        }

        $route = $instance->routes()->sole();

        return $route->provenance === RouteProvenance::Explicit ? $route->domain : null;
    }

    private function finishPublishedRegistration(Instance $completed): Instance
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

    private function recordFailure(Instance $instance, Throwable $exception): void
    {
        $errorCode = property_exists($exception, 'errorCode') && is_string($exception->errorCode)
            ? $exception->errorCode
            : 'instance.registration_incomplete';
        Instance::query()
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
