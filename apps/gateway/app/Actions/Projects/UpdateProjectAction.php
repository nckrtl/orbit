<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Data\Projects\ProjectData;
use App\Data\Projects\UpdateProjectData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\InstanceState;
use App\Domain\Projects\ProjectCode;
use App\Domain\Projects\ProjectDefaultBranchInheritance;
use App\Domain\Projects\ProjectRepositoryUpdatePlanner;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectType;
use App\Domain\Projects\ProjectUpdateProjectionMutator;
use App\Domain\Projects\ProjectUpdateSourceMutator;
use App\Domain\Projects\ProjectUpdateStatus;
use App\Domain\Routes\RouteTargetWebRoot;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Shared\StoredInteger;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Domain\SourceControl\ProjectRoot;
use App\Domain\SourceControl\RepositoryDefaultBranchResolver;
use App\Models\Instance;
use App\Models\Project;
use App\Models\ProjectUpdate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class UpdateProjectAction
{
    public function __construct(
        private InstanceEnvironmentOperationLock $operations,
        private ProjectDefaultBranchInheritance $inheritance,
        private ProjectRepositoryUpdatePlanner $repositories,
        private ProjectUpdateSourceMutator $sources,
        private ProjectUpdateProjectionMutator $projections,
        private RepositoryDefaultBranchResolver $branches,
        private ?RecordEventBroadcaster $broadcaster = null,
    ) {}

    public function execute(Project $project, UpdateProjectData $data): Project
    {
        if (! $data->hasChanges()) {
            throw new ResourceOperationException(
                errorCode: 'project.update_required',
                message: 'Provide at least one Project update.',
                status: 422,
            );
        }

        if ($data->code !== null && ($data->hasReconcilableChanges() || $data->typeProvided || $data->sourceAccessProvided)) {
            throw new ResourceOperationException('project.code_update_separate', 'Update the Project code separately from source settings.', 422);
        }

        if ($data->rootProvided || $data->typeProvided) {
            $effectiveRoot = $data->rootProvided ? $data->root : $project->root;
            $message = ! $data->rootProvided && $data->type instanceof ProjectType
                ? "A Route targets an Instance that inherits root [{$effectiveRoot}], which is not a web root. Send a web root with the change."
                : null;
            $this->assertRouteTargetRootCompatibility($project, $effectiveRoot, $message);
        }

        if ($data->code !== null) {
            $code = ProjectCode::validate($data->code);
            try {
                $project->update(['code' => $code]);
            } catch (UniqueConstraintViolationException $exception) {
                throw new ResourceOperationException('project.code_conflict', 'This Project code is already in use.', 409, previous: $exception);
            }
        }

        if ($data->typeProvided && $data->type instanceof ProjectType) {
            $this->assertTypeChange($project, $data->type);
            $project->update(['type' => $data->type]);
            $project = $project->fresh() ?? $project;
        }

        if ($data->sourceAccessProvided && $data->sourceAccess instanceof ProjectSourceAccess) {
            $project = $this->changeSourceAccess($project, $data, $data->sourceAccess);
        }

        $instanceIds = array_values($project->instances()
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => StoredInteger::from($id))
            ->all());

        if (! $data->hasReconcilableChanges()) {
            if ($data->taskCheckProvided || $data->taskWorkspaceRoutedProvided || $data->taskCompute !== null) {
                $project = $this->operations->run(
                    $instanceIds,
                    fn (): Project => $this->applyProjectCommands($project->fresh() ?? $project, $data),
                );
            }
            ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
                RecordEventType::ProjectUpdated,
                $project->id,
                ProjectData::fromModel($project)->toArray(),
            );

            return $project;
        }

        $result = $this->operations->run(
            $instanceIds,
            fn (): Project => $this->applyProjectCommands($this->executeOwned($project->fresh() ?? $project, $data), $data),
        );

        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            RecordEventType::ProjectUpdated,
            $result->id,
            ProjectData::fromModel($result)->toArray(),
        );

        return $result;
    }

    /**
     * Applies a source access change at once, after the repository reads with the new value
     * ([Projects](/reference/projects#change-source-access)). It
     * touches no checkout, so a later source change in the same request reads with the new value.
     */
    private function changeSourceAccess(Project $project, UpdateProjectData $data, ProjectSourceAccess $access): Project
    {
        if ($project->source_access === $access) {
            return $project;
        }

        $repository = $data->repositoryUrlProvided && is_string($data->repositoryUrl)
            ? GitRepositoryOrigin::validate($data->repositoryUrl)
            : $project->repository_url;
        $this->branches->resolve($repository, $access);
        $project->update(['source_access' => $access]);

        return $project->fresh() ?? $project;
    }

    /**
     * Stores Project task settings while the caller holds the update's operation lock.
     * Routing does not reconcile sources or Routes.
     */
    private function applyProjectCommands(Project $project, UpdateProjectData $data): Project
    {
        $changes = [];
        if ($data->taskCompute !== null) {
            $changes['task_compute'] = $data->taskCompute;
        }
        if ($data->taskCheckProvided) {
            $changes['task_check'] = $data->taskCheck;
        }
        if ($data->taskWorkspaceRoutedProvided) {
            $changes['task_workspace_routed'] = $data->taskWorkspaceRouted;
        }
        if ($changes === []) {
            return $project;
        }

        $project->update($changes);

        return $project->fresh() ?? $project;
    }

    private function executeOwned(Project $project, UpdateProjectData $data): Project
    {
        $update = $this->reserve($project, $data);

        try {
            $this->advance($update);

            return $project->fresh() ?? $project;
        } catch (Throwable $exception) {
            $update->refresh();

            if ($update->status->recoversForward()) {
                throw $exception;
            }

            if ($update->status !== ProjectUpdateStatus::RolledBack) {
                $this->beginRollback($update, $exception);
            }

            throw $exception;
        }
    }

    private function reserve(Project $project, UpdateProjectData $data): ProjectUpdate
    {
        return DB::transaction(function () use ($project, $data): ProjectUpdate {
            $locked = Project::query()->lockForUpdate()->findOrFail($project->id);
            $fingerprint = $data->fingerprint();
            $incomplete = ProjectUpdate::query()
                ->where('project_id', $locked->id)
                ->whereNotIn('status', [
                    ProjectUpdateStatus::Complete->value,
                    ProjectUpdateStatus::RolledBack->value,
                ])
                ->lockForUpdate()
                ->first();

            if ($incomplete instanceof ProjectUpdate) {
                if ($incomplete->fingerprint !== $fingerprint) {
                    throw new ResourceOperationException(
                        errorCode: 'project.update_in_progress',
                        message: 'A Project update is already in progress.',
                        status: 409,
                    );
                }

                return $incomplete;
            }

            $normalized = $this->normalized($locked, $data);

            if (! $this->changesState($locked, $normalized)) {
                $complete = ProjectUpdate::query()->create([
                    'project_id' => $locked->id,
                    'status' => ProjectUpdateStatus::Complete,
                    'fingerprint' => $fingerprint,
                    'requested_slug' => $normalized['slug'],
                    'requested_repository_url' => $normalized['repository_url'],
                    'requested_default_branch' => $normalized['default_branch'],
                    'requested_root' => $normalized['root'],
                    'previous_slug' => $locked->slug,
                    'previous_repository_url' => $locked->repository_url,
                    'previous_default_branch' => $locked->default_branch,
                    'previous_root' => $locked->root,
                    'inventory' => ['noop' => true],
                    'evidence' => ['noop' => true],
                ]);

                return $complete;
            }

            return ProjectUpdate::query()->create([
                'project_id' => $locked->id,
                'status' => ProjectUpdateStatus::Reserved,
                'fingerprint' => $fingerprint,
                'requested_slug' => $data->slugProvided ? $normalized['slug'] : null,
                'requested_repository_url' => $data->repositoryUrlProvided ? $normalized['repository_url'] : null,
                'requested_default_branch' => $data->defaultBranchProvided ? $normalized['default_branch'] : null,
                'requested_root' => $data->rootProvided ? $normalized['root'] : null,
                'previous_slug' => $locked->slug,
                'previous_repository_url' => $locked->repository_url,
                'previous_default_branch' => $locked->default_branch,
                'previous_root' => $locked->root,
                'inventory' => null,
                'evidence' => [],
            ]);
        });
    }

    private function advance(ProjectUpdate $update): void
    {
        $update->refresh();

        if ($update->status === ProjectUpdateStatus::Complete) {
            return;
        }

        if ($update->status === ProjectUpdateStatus::RollingBack) {
            $this->rollback($update);

            return;
        }

        match ($update->status) {
            ProjectUpdateStatus::Reserved => $this->preflight($update),
            ProjectUpdateStatus::Preflighted => $this->prepare($update),
            ProjectUpdateStatus::Prepared, ProjectUpdateStatus::Publishing => $this->publish($update),
            ProjectUpdateStatus::CleaningUp => $this->cleanup($update),
            default => null,
        };

        $update->refresh();

        if ($update->status->isIncomplete() && $update->status !== ProjectUpdateStatus::RollingBack) {
            $this->advance($update);
        }
    }

    private function preflight(ProjectUpdate $update): void
    {
        $project = $update->project()->with('instances')->firstOrFail();
        $instances = $project->instances;
        $plan = $this->repositories->inventory($instances);
        $inventory = [
            'instances' => $instances->pluck('id')->all(),
            'production' => $this->productionSnapshots($instances),
            'inherited_defaults' => [],
            'explicit_defaults' => [],
            'repository' => [
                'checkout_ids' => array_map(static fn (Instance $instance): int => $instance->id, $plan['checkouts']),
                'worktree_ids' => array_map(static fn (Instance $instance): int => $instance->id, $plan['worktrees']),
                'production_ids' => array_map(static fn (Instance $instance): int => $instance->id, $plan['production']),
            ],
            'slug' => null,
            'root' => null,
        ];

        if (is_string($update->requested_default_branch)) {
            $this->branches->verify(
                $update->requested_repository_url ?? $project->repository_url,
                $update->requested_default_branch,
                $project->source_access,
            );

            foreach ($instances as $instance) {
                if ($instance->placedOnAppProd()) {
                    continue;
                }

                if ($this->inheritance->inheritsAppDefault($instance)) {
                    $this->sources->preflightDefaultBranch($instance, $update->requested_default_branch);
                    $inventory['inherited_defaults'][] = $instance->id;

                    continue;
                }

                if ($instance->name === 'default' && $instance->branch_override !== null) {
                    $inventory['explicit_defaults'][] = $instance->id;
                }
            }
        }

        if (is_string($update->requested_repository_url)) {
            $this->assertRepositoryIdentity($project, $update->requested_repository_url);
            $this->repositories->assertWorktreesOwned($plan['checkouts'], $plan['worktrees']);
            $this->sources->preflightRepository(
                $plan['checkouts'],
                $project->repository_url,
                $update->requested_repository_url,
            );
        }

        if (is_string($update->requested_slug) && $update->requested_slug !== $project->slug) {
            $this->assertSlugAvailable($project, $update->requested_slug);
            $inventory['slug'] = $this->projections->preflightSlug($project, $update->requested_slug);
        }

        if (is_string($update->requested_root) && $update->requested_root !== $project->root) {
            $inventory['root'] = $this->projections->preflightRoot($project, $update->requested_root);
        }

        $update->update([
            'status' => ProjectUpdateStatus::Preflighted,
            'inventory' => $inventory,
        ]);
    }

    private function prepare(ProjectUpdate $update): void
    {
        $project = $update->project()->with('instances')->firstOrFail();
        $inventory = $this->storedMap($update->inventory) ?? [];
        $evidence = $this->storedMap($update->evidence) ?? [];

        if (is_string($update->requested_default_branch)) {
            $evidence['branches'] = $this->prepareDefaultBranches(
                $project,
                $update->requested_default_branch,
                $this->branchEvidence($evidence['branches'] ?? []),
            );
        }

        if (is_string($update->requested_repository_url)) {
            $repository = $this->storedMap($inventory['repository'] ?? null) ?? [];
            $checkoutIds = $this->integerList($repository['checkout_ids'] ?? []);
            $checkouts = array_values($project->instances
                ->whereIn('id', $checkoutIds)
                ->all());
            $origins = $evidence['origins'] ?? [];
            $origins = is_array($origins) ? $origins : [];
            $evidence['origins'] = $this->sources->changeOrigins(
                $checkouts,
                $update->previous_repository_url,
                $update->requested_repository_url,
                $this->originMutations($origins),
            );
        }

        $slugInventory = $this->storedMap($inventory['slug'] ?? null);
        if ($slugInventory !== null && is_string($update->requested_slug)) {
            $evidence['slug'] = $this->projections->prepareSlug($project, $update->requested_slug, $slugInventory);
        }

        $rootInventory = $this->storedMap($inventory['root'] ?? null);
        if ($rootInventory !== null && is_string($update->requested_root)) {
            $evidence['root'] = $this->projections->prepareRoot($project, $update->requested_root, $rootInventory);
        }

        $update->update([
            'status' => ProjectUpdateStatus::Prepared,
            'evidence' => $evidence,
        ]);
    }

    /**
     * @param  list<array{instance_id: int, previous_branch: ?string, current_branch: string, switched: bool}>  $evidence
     * @return list<array{instance_id: int, previous_branch: ?string, current_branch: string, switched: bool}>
     */
    private function prepareDefaultBranches(Project $project, string $branch, array $evidence): array
    {
        $byId = [];

        foreach ($evidence as $row) {
            $byId[$row['instance_id']] = $row;
        }

        foreach ($project->instances as $instance) {
            if (! $this->inheritance->inheritsAppDefault($instance)) {
                continue;
            }

            $existing = $byId[$instance->id] ?? null;

            if ($existing !== null && $existing['switched']) {
                continue;
            }

            $previous = $instance->branch;
            $this->sources->switchDefaultBranch($instance, $branch);
            $instance->update(['branch' => $branch]);
            $byId[$instance->id] = [
                'instance_id' => $instance->id,
                'previous_branch' => $previous,
                'current_branch' => $branch,
                'switched' => true,
            ];
        }

        return array_values($byId);
    }

    private function publish(ProjectUpdate $update): void
    {
        $update->update(['status' => ProjectUpdateStatus::Publishing]);
        $project = Project::query()->lockForUpdate()->findOrFail($update->project_id);
        $attributes = [];

        if (is_string($update->requested_slug)) {
            $attributes['slug'] = $update->requested_slug;
        }

        if (is_string($update->requested_repository_url)) {
            $attributes['repository_url'] = $update->requested_repository_url;
            $project->repository_identity = GitRepositoryIdentity::derive($update->requested_repository_url);
        }

        if (is_string($update->requested_default_branch)) {
            $attributes['default_branch'] = $update->requested_default_branch;
        }

        if (is_string($update->requested_root)) {
            $attributes['root'] = $update->requested_root;
        }

        $evidence = $update->evidence ?? [];

        $slugEvidence = $this->storedMap($evidence['slug'] ?? null);
        if ($slugEvidence !== null && is_string($update->requested_slug)) {
            $this->projections->publishSlug($project, $update->requested_slug, $slugEvidence);
        }

        if ($attributes !== []) {
            $project->fill($attributes);
            $project->save();
        }

        $rootEvidence = $this->storedMap($evidence['root'] ?? null);
        if ($rootEvidence !== null && is_string($update->requested_root)) {
            $this->projections->publishRoot($project->refresh(), $update->requested_root, $rootEvidence);
        }

        $this->assertProductionUnchanged($update);
        $update->update(['status' => ProjectUpdateStatus::CleaningUp]);
    }

    private function cleanup(ProjectUpdate $update): void
    {
        $this->assertProductionUnchanged($update);
        $update->update([
            'status' => ProjectUpdateStatus::Complete,
            'error_code' => null,
        ]);
    }

    private function beginRollback(ProjectUpdate $update, Throwable $exception): void
    {
        $code = $exception instanceof ResourceOperationException
            ? $exception->errorCode
            : 'project.update_failed';

        $update->update([
            'status' => ProjectUpdateStatus::RollingBack,
            'error_code' => $code,
        ]);

        $this->rollback($update);
    }

    /**
     * @param  array<mixed, mixed>  $origins
     * @return list<array{path: string, previous_url: string, current_url: string, mutated: bool}>
     */
    private function originMutations(array $origins): array
    {
        $mutations = [];

        foreach ($origins as $origin) {
            if (
                ! is_array($origin)
                || ! is_string($origin['path'] ?? null)
                || ! is_string($origin['previous_url'] ?? null)
                || ! is_string($origin['current_url'] ?? null)
                || ! is_bool($origin['mutated'] ?? null)
            ) {
                throw new ResourceOperationException(
                    errorCode: 'project.update_failed',
                    message: 'App update origin evidence is invalid.',
                    status: 409,
                );
            }

            $mutations[] = [
                'path' => $origin['path'],
                'previous_url' => $origin['previous_url'],
                'current_url' => $origin['current_url'],
                'mutated' => $origin['mutated'],
            ];
        }

        return $mutations;
    }

    private function rollback(ProjectUpdate $update): void
    {
        $project = $update->project()->with('instances')->firstOrFail();
        $evidence = $this->storedMap($update->evidence) ?? [];

        $slugEvidence = $this->storedMap($evidence['slug'] ?? null);
        if ($slugEvidence !== null) {
            $this->projections->rollbackSlug($project, $slugEvidence);
        }

        $rootEvidence = $this->storedMap($evidence['root'] ?? null);
        if ($rootEvidence !== null) {
            $this->projections->rollbackRoot($project, $rootEvidence);
        }

        if (is_array($evidence['origins'] ?? null)) {
            $this->sources->restoreOrigins($this->originMutations($evidence['origins']));
        }

        foreach ($this->branchEvidence($evidence['branches'] ?? []) as $row) {
            if (! $row['switched']) {
                continue;
            }

            $instance = $project->instances->firstWhere('id', $row['instance_id']);

            if (! $instance instanceof Instance) {
                continue;
            }

            $previous = $row['previous_branch'] ?? $update->previous_default_branch;

            if (is_string($previous)) {
                $this->sources->restoreDefaultBranch($instance, $previous);
                $instance->update(['branch' => $previous]);
            }
        }

        $project->fill([
            'slug' => $update->previous_slug,
            'repository_url' => $update->previous_repository_url,
            'default_branch' => $update->previous_default_branch,
            'root' => $update->previous_root,
        ]);
        $project->repository_identity = GitRepositoryIdentity::derive($update->previous_repository_url);
        $project->save();

        $this->assertProductionUnchanged($update);
        $update->update(['status' => ProjectUpdateStatus::RolledBack]);
    }

    /**
     * @return array{slug: ?string, repository_url: ?string, default_branch: ?string, root: ?string}
     */
    private function normalized(Project $project, UpdateProjectData $data): array
    {
        $type = $data->typeProvided ? $data->type ?? $project->type : $project->type;
        $root = $data->rootProvided ? (string) $data->root : $project->root;
        if (is_string($root)) {
            $root = ProjectRoot::validate($root, $type);
        }

        return [
            'slug' => $data->slugProvided ? $data->slug : $project->slug,
            'repository_url' => $data->repositoryUrlProvided
                ? GitRepositoryOrigin::validate((string) $data->repositoryUrl)
                : $project->repository_url,
            'default_branch' => $data->defaultBranchProvided
                ? GitBranchName::validate((string) $data->defaultBranch)
                : $project->default_branch,
            'root' => $root,
        ];
    }

    /**
     * @param  array{slug: ?string, repository_url: ?string, default_branch: ?string, root: ?string}  $normalized
     */
    private function changesState(Project $project, array $normalized): bool
    {
        return $normalized['slug'] !== $project->slug
            || $normalized['repository_url'] !== $project->repository_url
            || $normalized['default_branch'] !== $project->default_branch
            || $normalized['root'] !== $project->root;
    }

    private function assertSlugAvailable(Project $project, string $slug): void
    {
        if (Project::query()->where('slug', $slug)->whereKeyNot($project->id)->exists()) {
            throw new ResourceOperationException(
                errorCode: 'project.slug_conflict',
                message: "Project slug [{$slug}] is already owned.",
                status: 409,
            );
        }
    }

    private function assertRepositoryIdentity(Project $project, string $repositoryUrl): void
    {
        $identity = GitRepositoryIdentity::derive($repositoryUrl);

        if (
            $identity !== $project->repository_identity
            && Project::query()->where('repository_identity', $identity)->whereKeyNot($project->id)->exists()
        ) {
            throw new ResourceOperationException(
                errorCode: 'project.repository_identity_conflict',
                message: 'The repository is already owned by another Project.',
                status: 409,
            );
        }
    }

    /**
     * @param  iterable<int, Instance>  $instances
     * @return list<array<string, mixed>>
     */
    private function productionSnapshots(iterable $instances): array
    {
        $snapshots = [];

        foreach ($instances as $instance) {
            if (! $instance->placedOnAppProd()) {
                continue;
            }

            $snapshots[] = $this->productionSnapshot($instance);
        }

        return $snapshots;
    }

    /** @return array<string, mixed> */
    private function productionSnapshot(Instance $instance): array
    {
        return [
            'id' => $instance->id,
            'branch' => $instance->branch,
            'starting_commit' => $instance->starting_commit,
            'checkout_path' => $instance->checkout_path,
            'source_layout' => $instance->source_layout,
            'deployment_branch' => $instance->deployment_branch,
            'production_home' => $instance->production_home,
            'production_user' => $instance->production_user,
        ];
    }

    /** @return array<string, mixed>|null */
    private function storedMap(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $map = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                return null;
            }

            $map[$key] = $item;
        }

        return $map;
    }

    /** @return list<int> */
    private function integerList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        foreach ($value as $item) {
            if (! is_int($item)) {
                return [];
            }
        }

        return $value;
    }

    /**
     * @return list<array{instance_id: int, previous_branch: ?string, current_branch: string, switched: bool}>
     */
    private function branchEvidence(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $row) {
            if (! is_array($row)) {
                return [];
            }

            $previousBranch = $row['previous_branch'] ?? null;
            if (
                ! is_int($row['instance_id'] ?? null)
                || (! is_string($previousBranch) && $previousBranch !== null)
                || ! is_string($row['current_branch'] ?? null)
                || ! is_bool($row['switched'] ?? null)
            ) {
                return [];
            }

            $rows[] = [
                'instance_id' => $row['instance_id'],
                'previous_branch' => $previousBranch,
                'current_branch' => $row['current_branch'],
                'switched' => $row['switched'],
            ];
        }

        return $rows;
    }

    private function assertProductionUnchanged(ProjectUpdate $update): void
    {
        $inventory = $this->storedMap($update->inventory) ?? [];
        $expected = $inventory['production'] ?? [];

        if (! is_array($expected)) {
            return;
        }

        foreach ($expected as $snapshot) {
            if (! is_array($snapshot) || ! is_int($snapshot['id'] ?? null)) {
                continue;
            }

            $instance = Instance::query()->find($snapshot['id']);

            if (! $instance instanceof Instance) {
                throw new ResourceOperationException(
                    errorCode: 'project.production_ownership_changed',
                    message: 'A production Instance changed during the Project update.',
                    status: 409,
                );
            }

            if ($this->productionSnapshot($instance) !== $snapshot) {
                throw new ResourceOperationException(
                    errorCode: 'project.production_ownership_changed',
                    message: 'A production Instance changed during the Project update.',
                    status: 409,
                );
            }
        }
    }

    private function assertRouteTargetRootCompatibility(Project $project, ?string $root, ?string $message = null): void
    {
        $hasInheritedRouteTarget = $project->instances()
            ->whereNull('root')
            ->whereHas('routeTargets')
            ->exists();

        if ($hasInheritedRouteTarget) {
            RouteTargetWebRoot::assertSupportedRoot($root, $message);
        }
    }

    private function assertTypeChange(Project $project, ProjectType $type): void
    {
        if (! $type->isWebServing()) {
            return;
        }

        $unrouted = $project->instances()
            ->where('status', InstanceState::Active)
            ->whereDoesntHave('routes')
            ->orderBy('id')
            ->pluck('id');

        if ($unrouted->isEmpty()) {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'project.type_requires_route',
            message: "A {$type->value} Project cannot be assigned while an active Instance has no Route.",
            status: 409,
            details: ['instance_ids' => $unrouted->implode(',')],
        );
    }
}
