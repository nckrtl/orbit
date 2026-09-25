<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Data\Apps\AppData;
use App\Data\Apps\UpdateAppData;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\Apps\AppDefaultBranchInheritance;
use App\Domain\Apps\AppRepositoryUpdatePlanner;
use App\Domain\Apps\AppUpdateProjectionMutator;
use App\Domain\Apps\AppUpdateSourceMutator;
use App\Domain\Apps\AppUpdateStatus;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Projects\ProjectCode;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Domain\SourceControl\ProjectRoot;
use App\Domain\SourceControl\RepositoryDefaultBranchResolver;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppUpdate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class UpdateAppAction
{
    public function __construct(
        private AppInstanceEnvironmentOperationLock $operations,
        private AppDefaultBranchInheritance $inheritance,
        private AppRepositoryUpdatePlanner $repositories,
        private AppUpdateSourceMutator $sources,
        private AppUpdateProjectionMutator $projections,
        private RepositoryDefaultBranchResolver $branches,
        private ?RecordEventBroadcaster $broadcaster = null,
    ) {}

    public function execute(OrbitApp $app, UpdateAppData $data): OrbitApp
    {
        if (! $data->hasChanges()) {
            throw new ResourceOperationException(
                errorCode: 'app.update_required',
                message: 'Provide at least one Project update.',
                status: 422,
            );
        }

        if ($data->code !== null && ($data->hasReconcilableChanges() || $data->typeProvided)) {
            throw new ResourceOperationException('app.code_update_separate', 'Update the Project code separately from source settings.', 422);
        }

        if ($data->code !== null) {
            $code = ProjectCode::validate($data->code);
            try {
                $app->update(['code' => $code]);
            } catch (UniqueConstraintViolationException $exception) {
                throw new ResourceOperationException('app.code_conflict', 'This Project code is already in use.', 409, previous: $exception);
            }
        }

        if ($data->typeProvided && $data->type instanceof ProjectType) {
            $this->assertTypeChange($app, $data->type);
            $app->update(['type' => $data->type]);
            $app = $app->fresh() ?? $app;
        }

        if (! $data->hasReconcilableChanges()) {
            ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
                RecordEventType::AppUpdated,
                $app->id,
                AppData::fromModel($app)->toArray(),
            );

            return $app;
        }

        $instanceIds = $app->appInstances()
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $result = $this->operations->run(
            $instanceIds,
            fn (): OrbitApp => $this->executeOwned($app->fresh() ?? $app, $data),
        );

        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            RecordEventType::AppUpdated,
            $result->id,
            AppData::fromModel($result)->toArray(),
        );

        return $result;
    }

    private function executeOwned(OrbitApp $app, UpdateAppData $data): OrbitApp
    {
        $update = $this->reserve($app, $data);

        try {
            $this->advance($update);

            return $app->fresh() ?? $app;
        } catch (Throwable $exception) {
            $update->refresh();

            if ($update->status->recoversForward()) {
                throw $exception;
            }

            if ($update->status !== AppUpdateStatus::RolledBack) {
                $this->beginRollback($update, $exception);
            }

            throw $exception;
        }
    }

    private function reserve(OrbitApp $app, UpdateAppData $data): AppUpdate
    {
        return DB::transaction(function () use ($app, $data): AppUpdate {
            $locked = OrbitApp::query()->lockForUpdate()->findOrFail($app->id);
            $fingerprint = $data->fingerprint();
            $incomplete = AppUpdate::query()
                ->where('app_id', $locked->id)
                ->whereNotIn('status', [
                    AppUpdateStatus::Complete->value,
                    AppUpdateStatus::RolledBack->value,
                ])
                ->lockForUpdate()
                ->first();

            if ($incomplete instanceof AppUpdate) {
                if ($incomplete->fingerprint !== $fingerprint) {
                    throw new ResourceOperationException(
                        errorCode: 'app.update_in_progress',
                        message: 'A Project update is already in progress.',
                        status: 409,
                    );
                }

                return $incomplete;
            }

            $normalized = $this->normalized($locked, $data);

            if (! $this->changesState($locked, $normalized)) {
                $complete = AppUpdate::query()->create([
                    'app_id' => $locked->id,
                    'status' => AppUpdateStatus::Complete,
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

            return AppUpdate::query()->create([
                'app_id' => $locked->id,
                'status' => AppUpdateStatus::Reserved,
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

    private function advance(AppUpdate $update): void
    {
        $update->refresh();

        if ($update->status === AppUpdateStatus::Complete) {
            return;
        }

        if ($update->status === AppUpdateStatus::RollingBack) {
            $this->rollback($update);

            return;
        }

        match ($update->status) {
            AppUpdateStatus::Reserved => $this->preflight($update),
            AppUpdateStatus::Preflighted => $this->prepare($update),
            AppUpdateStatus::Prepared, AppUpdateStatus::Publishing => $this->publish($update),
            AppUpdateStatus::CleaningUp => $this->cleanup($update),
            default => null,
        };

        $update->refresh();

        if ($update->status->isIncomplete() && $update->status !== AppUpdateStatus::RollingBack) {
            $this->advance($update);
        }
    }

    private function preflight(AppUpdate $update): void
    {
        $app = $update->app()->with('appInstances')->firstOrFail();
        $instances = $app->appInstances;
        $plan = $this->repositories->inventory($instances);
        $inventory = [
            'instances' => $instances->pluck('id')->all(),
            'production' => $this->productionSnapshots($instances),
            'inherited_defaults' => [],
            'explicit_defaults' => [],
            'repository' => [
                'checkout_ids' => array_map(static fn (AppInstance $instance): int => $instance->id, $plan['checkouts']),
                'worktree_ids' => array_map(static fn (AppInstance $instance): int => $instance->id, $plan['worktrees']),
                'production_ids' => array_map(static fn (AppInstance $instance): int => $instance->id, $plan['production']),
            ],
            'slug' => null,
            'root' => null,
        ];

        if (is_string($update->requested_default_branch)) {
            $this->branches->verify(
                $update->requested_repository_url ?? $app->repository_url,
                $update->requested_default_branch,
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
            $this->assertRepositoryIdentity($app, $update->requested_repository_url);
            $this->repositories->assertWorktreesOwned($plan['checkouts'], $plan['worktrees']);
            $this->sources->preflightRepository(
                $plan['checkouts'],
                $app->repository_url,
                $update->requested_repository_url,
            );
        }

        if (is_string($update->requested_slug) && $update->requested_slug !== $app->slug) {
            $this->assertSlugAvailable($app, $update->requested_slug);
            $inventory['slug'] = $this->projections->preflightSlug($app, $update->requested_slug);
        }

        if (is_string($update->requested_root) && $update->requested_root !== $app->root) {
            $inventory['root'] = $this->projections->preflightRoot($app, $update->requested_root);
        }

        $update->update([
            'status' => AppUpdateStatus::Preflighted,
            'inventory' => $inventory,
        ]);
    }

    private function prepare(AppUpdate $update): void
    {
        $app = $update->app()->with('appInstances')->firstOrFail();
        $inventory = $update->inventory ?? [];
        $evidence = $update->evidence ?? [];

        if (is_string($update->requested_default_branch)) {
            $evidence['branches'] = $this->prepareDefaultBranches($app, $update, $evidence['branches'] ?? []);
        }

        if (is_string($update->requested_repository_url)) {
            $checkoutIds = $inventory['repository']['checkout_ids'] ?? [];
            $checkouts = $app->appInstances
                ->whereIn('id', is_array($checkoutIds) ? $checkoutIds : [])
                ->values()
                ->all();
            $evidence['origins'] = $this->sources->changeOrigins(
                $checkouts,
                $update->previous_repository_url,
                $update->requested_repository_url,
                $evidence['origins'] ?? [],
            );
        }

        if (is_array($inventory['slug'] ?? null) && is_string($update->requested_slug)) {
            $evidence['slug'] = $this->projections->prepareSlug($app, $update->requested_slug, $inventory['slug']);
        }

        if (is_array($inventory['root'] ?? null) && is_string($update->requested_root)) {
            $evidence['root'] = $this->projections->prepareRoot($app, $update->requested_root, $inventory['root']);
        }

        $update->update([
            'status' => AppUpdateStatus::Prepared,
            'evidence' => $evidence,
        ]);
    }

    /**
     * @param  list<array{instance_id: int, previous_branch: ?string, current_branch: string, switched: bool}>  $evidence
     * @return list<array{instance_id: int, previous_branch: ?string, current_branch: string, switched: bool}>
     */
    private function prepareDefaultBranches(OrbitApp $app, AppUpdate $update, array $evidence): array
    {
        $byId = [];

        foreach ($evidence as $row) {
            $byId[$row['instance_id']] = $row;
        }

        foreach ($app->appInstances as $instance) {
            if (! $this->inheritance->inheritsAppDefault($instance)) {
                continue;
            }

            $existing = $byId[$instance->id] ?? null;

            if (is_array($existing) && $existing['switched']) {
                continue;
            }

            $previous = $instance->branch;
            $this->sources->switchDefaultBranch($instance, (string) $update->requested_default_branch);
            $instance->update(['branch' => $update->requested_default_branch]);
            $byId[$instance->id] = [
                'instance_id' => $instance->id,
                'previous_branch' => $previous,
                'current_branch' => $update->requested_default_branch,
                'switched' => true,
            ];
        }

        return array_values($byId);
    }

    private function publish(AppUpdate $update): void
    {
        $update->update(['status' => AppUpdateStatus::Publishing]);
        $app = OrbitApp::query()->lockForUpdate()->findOrFail($update->app_id);
        $attributes = [];

        if (is_string($update->requested_slug)) {
            $attributes['slug'] = $update->requested_slug;
        }

        if (is_string($update->requested_repository_url)) {
            $attributes['repository_url'] = $update->requested_repository_url;
            $app->repository_identity = GitRepositoryIdentity::derive($update->requested_repository_url);
        }

        if (is_string($update->requested_default_branch)) {
            $attributes['default_branch'] = $update->requested_default_branch;
        }

        if (is_string($update->requested_root)) {
            $attributes['root'] = $update->requested_root;
        }

        if ($attributes !== []) {
            $app->fill($attributes);
            $app->save();
        }

        $evidence = $update->evidence ?? [];

        if (is_array($evidence['slug'] ?? null) && is_string($update->requested_slug)) {
            $this->projections->publishSlug($app->refresh(), $update->requested_slug, $evidence['slug']);
        }

        if (is_array($evidence['root'] ?? null) && is_string($update->requested_root)) {
            $this->projections->publishRoot($app->refresh(), $update->requested_root, $evidence['root']);
        }

        $this->assertProductionUnchanged($update);
        $update->update(['status' => AppUpdateStatus::CleaningUp]);
    }

    private function cleanup(AppUpdate $update): void
    {
        $this->assertProductionUnchanged($update);
        $update->update([
            'status' => AppUpdateStatus::Complete,
            'error_code' => null,
        ]);
    }

    private function beginRollback(AppUpdate $update, Throwable $exception): void
    {
        $code = $exception instanceof ResourceOperationException
            ? $exception->errorCode
            : 'app.update_failed';

        $update->update([
            'status' => AppUpdateStatus::RollingBack,
            'error_code' => $code,
        ]);

        $this->rollback($update);
    }

    private function rollback(AppUpdate $update): void
    {
        $app = $update->app()->with('appInstances')->firstOrFail();
        $evidence = $update->evidence ?? [];

        if (is_array($evidence['slug'] ?? null)) {
            $this->projections->rollbackSlug($app, $evidence['slug']);
        }

        if (is_array($evidence['root'] ?? null)) {
            $this->projections->rollbackRoot($app, $evidence['root']);
        }

        if (is_array($evidence['origins'] ?? null)) {
            $this->sources->restoreOrigins($evidence['origins']);
        }

        foreach ($evidence['branches'] ?? [] as $row) {
            if (! is_array($row) || ! ($row['switched'] ?? false)) {
                continue;
            }

            $instance = $app->appInstances->firstWhere('id', $row['instance_id']);

            if (! $instance instanceof AppInstance) {
                continue;
            }

            $previous = is_string($row['previous_branch'] ?? null) ? $row['previous_branch'] : $update->previous_default_branch;

            if (is_string($previous)) {
                $this->sources->restoreDefaultBranch($instance, $previous);
                $instance->update(['branch' => $previous]);
            }
        }

        $app->fill([
            'slug' => $update->previous_slug,
            'repository_url' => $update->previous_repository_url,
            'default_branch' => $update->previous_default_branch,
            'root' => $update->previous_root,
        ]);
        $app->repository_identity = GitRepositoryIdentity::derive($update->previous_repository_url);
        $app->save();

        $this->assertProductionUnchanged($update);
        $update->update(['status' => AppUpdateStatus::RolledBack]);
    }

    /**
     * @return array{slug: ?string, repository_url: ?string, default_branch: ?string, root: ?string}
     */
    private function normalized(OrbitApp $app, UpdateAppData $data): array
    {
        $type = $data->typeProvided ? $data->type ?? $app->type : $app->type;
        $root = $data->rootProvided ? (string) $data->root : $app->root;
        if (is_string($root)) {
            $root = ProjectRoot::validate($root, $type);
        }

        return [
            'slug' => $data->slugProvided ? $data->slug : $app->slug,
            'repository_url' => $data->repositoryUrlProvided
                ? GitRepositoryOrigin::validate((string) $data->repositoryUrl)
                : $app->repository_url,
            'default_branch' => $data->defaultBranchProvided
                ? GitBranchName::validate((string) $data->defaultBranch)
                : $app->default_branch,
            'root' => $root,
        ];
    }

    /**
     * @param  array{slug: ?string, repository_url: ?string, default_branch: ?string, root: ?string}  $normalized
     */
    private function changesState(OrbitApp $app, array $normalized): bool
    {
        return $normalized['slug'] !== $app->slug
            || $normalized['repository_url'] !== $app->repository_url
            || $normalized['default_branch'] !== $app->default_branch
            || $normalized['root'] !== $app->root;
    }

    private function assertSlugAvailable(OrbitApp $app, string $slug): void
    {
        if (OrbitApp::query()->where('slug', $slug)->whereKeyNot($app->id)->exists()) {
            throw new ResourceOperationException(
                errorCode: 'app.slug_conflict',
                message: "Project slug [{$slug}] is already owned.",
                status: 409,
            );
        }
    }

    private function assertRepositoryIdentity(OrbitApp $app, string $repositoryUrl): void
    {
        $identity = GitRepositoryIdentity::derive($repositoryUrl);

        if (
            $identity !== $app->repository_identity
            && OrbitApp::query()->where('repository_identity', $identity)->whereKeyNot($app->id)->exists()
        ) {
            throw new ResourceOperationException(
                errorCode: 'app.repository_identity_conflict',
                message: 'The repository is already owned by another Project.',
                status: 409,
            );
        }
    }

    /**
     * @param  iterable<int, AppInstance>  $instances
     * @return list<array<string, mixed>>
     */
    private function productionSnapshots(iterable $instances): array
    {
        $snapshots = [];

        foreach ($instances as $instance) {
            if ($instance->environment !== 'production') {
                continue;
            }

            $snapshots[] = $this->productionSnapshot($instance);
        }

        return $snapshots;
    }

    /** @return array<string, mixed> */
    private function productionSnapshot(AppInstance $instance): array
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

    private function assertProductionUnchanged(AppUpdate $update): void
    {
        $expected = $update->inventory['production'] ?? [];

        if (! is_array($expected)) {
            return;
        }

        foreach ($expected as $snapshot) {
            if (! is_array($snapshot) || ! isset($snapshot['id'])) {
                continue;
            }

            $instance = AppInstance::query()->find((int) $snapshot['id']);

            if (! $instance instanceof AppInstance) {
                throw new ResourceOperationException(
                    errorCode: 'app.production_ownership_changed',
                    message: 'A production Instance changed during the Project update.',
                    status: 409,
                );
            }

            if ($this->productionSnapshot($instance) !== $snapshot) {
                throw new ResourceOperationException(
                    errorCode: 'app.production_ownership_changed',
                    message: 'A production Instance changed during the Project update.',
                    status: 409,
                );
            }
        }
    }

    private function assertTypeChange(OrbitApp $app, ProjectType $type): void
    {
        if ($type !== ProjectType::LaravelApp) {
            return;
        }

        $unrouted = $app->appInstances()
            ->where('status', AppInstanceState::Active)
            ->whereDoesntHave('routes')
            ->orderBy('id')
            ->pluck('id');

        if ($unrouted->isEmpty()) {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'project.type_requires_route',
            message: 'A laravel-app Project cannot be assigned while an active Instance has no Route.',
            status: 409,
            details: ['instance_ids' => $unrouted->implode(',')],
        );
    }
}
