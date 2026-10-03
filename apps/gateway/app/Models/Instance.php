<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Relations\DualSafeMorphMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $vite_port
 * @property int|null $agentation_port
 * @property int $node_id
 * @property string $name
 * @property string $source_layout
 * @property string $checkout_path
 * @property string|null $source_prepare_id
 * @property string|null $production_user
 * @property string|null $production_home
 * @property string|null $production_php_service
 * @property string|null $production_php_pool
 * @property string|null $production_php_socket
 * @property string|null $root
 * @property string|null $branch
 * @property string|null $deployment_branch
 * @property string|null $branch_override
 * @property int|null $clone_candidate_id
 * @property string|null $clone_candidate_commit
 * @property string|null $clone_requested_branch
 * @property string|null $clone_preview_name
 * @property string|null $clone_preview_domain
 * @property string|null $clone_sqlite_source_path
 * @property Carbon|null $clone_completed_at
 * @property string|null $registration_original_path
 * @property string|null $registration_request_id
 * @property bool $registration_primary
 * @property bool $registration_include_worktrees
 * @property string|null $registration_repository_url
 * @property string|null $registration_repository_identity
 * @property string|null $registration_source_digest
 * @property bool $registration_detached
 * @property string|null $registration_default_branch
 * @property string|null $registration_inferred_slug
 * @property string|null $registration_inferred_root
 * @property string|null $registration_common_repository_path
 * @property list<string>|null $registration_worktree_paths
 * @property string|null $registration_relocation_state
 * @property string|null $registration_authoritative_path
 * @property string|null $registration_route_domain
 * @property string|null $registration_route_provenance
 * @property int|null $registration_source_device
 * @property int|null $registration_source_inode
 * @property Carbon|null $registration_completed_at
 * @property string|null $starting_commit
 * @property string|null $selected_php_version
 * @property bool|null $source_is_laravel
 * @property string|null $provisioning_step
 * @property string|null $failed_step
 * @property string|null $error_code
 * @property Carbon|null $runtime_definitions_captured_at
 * @property bool $development_release_layout
 * @property bool|null $task_workspace_routed
 * @property InstanceState $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read Node $node
 * @property-read Collection<int, RouteTarget> $routeTargets
 * @property-read Collection<int, Route> $routes
 * @property-read Collection<int, InstanceDeployStep> $deploySteps
 * @property-read Collection<int, InstanceEnvironmentValue> $environmentValues
 * @property-read Collection<int, DatabaseConnectionTarget> $databaseConnectionTargets
 * @property-read Collection<int, Process> $processes
 * @property-read Collection<int, Schedule> $schedules
 * @property-read InstanceRemovalMember|null $removalMember
 * @property-read Collection<int, InstanceTransfer> $transfers
 * @property-read Collection<int, InstanceDependencyObservation> $dependencyObservations
 * @property-read Collection<int, InstanceDependencyScanAttempt> $dependencyScanAttempts
 * @property-read Collection<int, Task> $tasks
 */
final class Instance extends Model
{
    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = [
        'source_layout' => 'checkout',
        'registration_detached' => false,
        'registration_primary' => false,
        'registration_include_worktrees' => false,
        'status' => 'reserved',
    ];

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'project_id',
        'node_id',
        'vite_port',
        'agentation_port',
        'name',
        'source_layout',
        'checkout_path',
        'source_prepare_id',
        'production_user',
        'production_home',
        'production_php_service',
        'production_php_pool',
        'production_php_socket',
        'root',
        'branch',
        'deployment_branch',
        'branch_override',
        'clone_candidate_id',
        'clone_candidate_commit',
        'clone_requested_branch',
        'clone_preview_name',
        'clone_preview_domain',
        'clone_sqlite_source_path',
        'clone_completed_at',
        'registration_original_path',
        'registration_request_id',
        'registration_primary',
        'registration_include_worktrees',
        'registration_repository_url',
        'registration_repository_identity',
        'registration_source_digest',
        'registration_detached',
        'registration_default_branch',
        'registration_inferred_slug',
        'registration_inferred_root',
        'registration_common_repository_path',
        'registration_worktree_paths',
        'registration_relocation_state',
        'registration_authoritative_path',
        'registration_route_domain',
        'registration_route_provenance',
        'registration_source_device',
        'registration_source_inode',
        'registration_completed_at',
        'starting_commit',
        'selected_php_version',
        'source_is_laravel',
        'provisioning_step',
        'failed_step',
        'runtime_definitions_captured_at',
        'status',
        'error_code',
        'task_workspace_routed',
        'development_release_layout',
    ];

    public const string MorphAlias = 'instance';

    /** @return list<string> */
    public static function morphTypes(): array
    {
        return [self::MorphAlias];
    }

    public static function isMorphType(mixed $type): bool
    {
        return $type === self::MorphAlias;
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return HasMany<RouteTarget, $this> */
    public function routeTargets(): HasMany
    {
        return $this->hasMany(RouteTarget::class);
    }

    /** @return BelongsToMany<Route, $this> */
    public function routes(): BelongsToMany
    {
        return $this->belongsToMany(Route::class, 'route_targets')->withPivot('position');
    }

    public function authoritativeRoute(): ?Route
    {
        $this->loadMissing('routes');

        return $this->routes->first(
            static fn (Route $route): bool => $route->isAuthoritative(),
        );
    }

    /** @return HasMany<InstanceDeployStep, $this> */
    public function deploySteps(): HasMany
    {
        return $this->hasMany(InstanceDeployStep::class);
    }

    /** @return HasMany<InstanceDeployment, $this> */
    public function deployments(): HasMany
    {
        return $this->hasMany(InstanceDeployment::class);
    }

    /** @return HasMany<InstanceEnvironmentValue, $this> */
    public function environmentValues(): HasMany
    {
        return $this->hasMany(InstanceEnvironmentValue::class);
    }

    /** @return HasMany<DatabaseConnectionTarget, $this> */
    public function databaseConnectionTargets(): HasMany
    {
        return $this->hasMany(DatabaseConnectionTarget::class);
    }

    /** @return MorphMany<Process, $this> */
    public function processes(): MorphMany
    {
        return $this->morphMany(Process::class, 'owner');
    }

    /** @return MorphMany<Schedule, $this> */
    public function schedules(): MorphMany
    {
        return $this->morphMany(Schedule::class, 'target');
    }

    /** @return HasOne<InstanceRemovalMember, $this> */
    public function removalMember(): HasOne
    {
        return $this->hasOne(InstanceRemovalMember::class)->whereNull('row_deleted_at');
    }

    /** @return HasMany<InstanceTransfer, $this> */
    public function transfers(): HasMany
    {
        return $this->hasMany(InstanceTransfer::class);
    }

    /** @return HasMany<InstanceDependencyObservation, $this> */
    public function dependencyObservations(): HasMany
    {
        return $this->hasMany(InstanceDependencyObservation::class);
    }

    /** @return HasMany<InstanceDependencyScanAttempt, $this> */
    public function dependencyScanAttempts(): HasMany
    {
        return $this->hasMany(InstanceDependencyScanAttempt::class);
    }

    /** @return MorphMany<Task, $this> */
    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable')->topLevel();
    }

    public function usesProductionReleaseLayout(): bool
    {
        return
            $this->placementEnvironment() === 'production'
            && is_string($this->production_home)
            && str_starts_with($this->checkout_path, "{$this->production_home}/releases/");
    }

    public function placedOnAppProd(): bool
    {
        return $this->placementEnvironment() === 'production';
    }

    public function placedOnAppDev(): bool
    {
        return $this->placementEnvironment() === 'development';
    }

    public function requiresRoute(): bool
    {
        $this->loadMissing('project');

        return $this->project->type === ProjectType::LaravelApp;
    }

    public function servesPhp(): bool
    {
        $this->loadMissing('project');

        if ($this->project->type->servesPhpByDefault()) {
            return true;
        }

        return $this->project->type === ProjectType::Monorepo
            && $this->authoritativeRoute() !== null
            && $this->source_is_laravel === true;
    }

    public function defaultAppEnv(): string
    {
        $environment = $this->placementEnvironment();

        if ($environment === null) {
            throw new ResourceOperationException(
                errorCode: 'instance.placement_unavailable',
                message: "Instance [{$this->name}] has no app-dev or app-prod Node role.",
                status: 409,
            );
        }

        return $environment;
    }

    public function configuredAppEnv(): string
    {
        $value = $this->environmentValues()
            ->where('env_key', 'APP_ENV')
            ->first()
            ?->env_value;

        if (is_string($value) && $value !== '' && $value !== '{{instance.environment}}') {
            return $value;
        }

        return $this->defaultAppEnv();
    }

    public function effectiveRoot(): ?string
    {
        $root = $this->root ?? $this->project->root;

        if ($this->placementEnvironment() === 'production' && is_string($this->production_home) && is_string($root)) {
            return "{$this->production_home}/current/{$root}";
        }

        return $root;
    }

    public function placementEnvironment(): ?string
    {
        $this->loadMissing('node.roles');
        $node = $this->getRelation('node');

        if (! $node instanceof Node) {
            return null;
        }

        // The Node's app role decides the environment in every lifecycle state, so an
        // app-dev role that is converging, failed, or being removed still places its Instances.
        $roles = $node->roles->filter(
            static fn (NodeRole $role): bool => in_array($role->role, [RoleName::AppDev, RoleName::AppProd], strict: true),
        );

        if ($roles->count() !== 1) {
            return null;
        }

        return $roles->sole()->role === RoleName::AppProd ? 'production' : 'development';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'vite_port' => 'integer',
            'agentation_port' => 'integer',
            'clone_candidate_id' => 'integer',
            'clone_completed_at' => 'immutable_datetime',
            'registration_detached' => 'boolean',
            'registration_primary' => 'boolean',
            'registration_include_worktrees' => 'boolean',
            'registration_worktree_paths' => 'array',
            'registration_source_device' => 'integer',
            'registration_source_inode' => 'integer',
            'registration_completed_at' => 'immutable_datetime',
            'runtime_definitions_captured_at' => 'immutable_datetime',
            'source_is_laravel' => 'boolean',
            'task_workspace_routed' => 'boolean',
            'development_release_layout' => 'boolean',
            'status' => InstanceState::class,
        ];
    }

    /**
     * @param  Builder<Model>  $query
     * @return MorphMany<Model, Model>
     */
    protected function newMorphMany($query, $parent, $type, $id, $localKey)
    {
        return new DualSafeMorphMany($query, $parent, $type, $id, $localKey, self::morphTypes());
    }
}
