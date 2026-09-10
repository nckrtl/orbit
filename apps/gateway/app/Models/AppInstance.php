<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\AppInstances\AppInstanceState;
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
 * @property int $app_id
 * @property int $node_id
 * @property string $name
 * @property string $environment
 * @property string $source_layout
 * @property string $checkout_path
 * @property string|null $production_user
 * @property string|null $production_home
 * @property string|null $production_php_service
 * @property string|null $production_php_pool
 * @property string|null $production_php_socket
 * @property string|null $root
 * @property string|null $branch
 * @property string|null $branch_override
 * @property bool $migration_required
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
 * @property string|null $registration_route_hostname
 * @property string|null $registration_route_provenance
 * @property int|null $registration_source_device
 * @property int|null $registration_source_inode
 * @property array<string, mixed>|null $registration_migration_recovery
 * @property Carbon|null $registration_completed_at
 * @property string|null $starting_commit
 * @property string|null $selected_php_version
 * @property bool|null $source_is_laravel
 * @property string|null $provisioning_step
 * @property string|null $failed_step
 * @property string|null $error_code
 * @property AppInstanceState $status
 * @property-read App $app
 * @property-read Node $node
 * @property-read Collection<int, RouteTarget> $routeTargets
 * @property-read Collection<int, Route> $routes
 * @property-read Collection<int, AppInstanceEnvironmentValue> $environmentValues
 * @property-read Collection<int, Process> $processes
 * @property-read AppInstanceRemovalMember|null $removalMember
 */
final class AppInstance extends Model
{
    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = [
        'environment' => 'development',
        'source_layout' => 'checkout',
        'migration_required' => false,
        'registration_detached' => false,
        'registration_primary' => false,
        'registration_include_worktrees' => false,
        'status' => 'reserved',
    ];

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'app_id',
        'node_id',
        'name',
        'environment',
        'source_layout',
        'checkout_path',
        'production_user',
        'production_home',
        'production_php_service',
        'production_php_pool',
        'production_php_socket',
        'root',
        'branch',
        'branch_override',
        'migration_required',
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
        'registration_route_hostname',
        'registration_route_provenance',
        'registration_source_device',
        'registration_source_inode',
        'registration_migration_recovery',
        'registration_completed_at',
        'starting_commit',
        'selected_php_version',
        'source_is_laravel',
        'provisioning_step',
        'failed_step',
        'error_code',
        'status',
    ];

    /** @return BelongsTo<App, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
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

    /** @return HasMany<AppInstanceEnvironmentValue, $this> */
    public function environmentValues(): HasMany
    {
        return $this->hasMany(AppInstanceEnvironmentValue::class);
    }

    /** @return MorphMany<Process, $this> */
    public function processes(): MorphMany
    {
        return $this->morphMany(Process::class, 'owner');
    }

    /** @return HasOne<AppInstanceRemovalMember, $this> */
    public function removalMember(): HasOne
    {
        return $this->hasOne(AppInstanceRemovalMember::class)->whereNull('row_deleted_at');
    }

    public function effectiveRoot(): ?string
    {
        $root = $this->root ?? $this->app->root;

        if ($this->environment === 'production' && is_string($this->production_home) && is_string($root)) {
            $base = $this->usesProductionReleaseLayout()
                ? "{$this->production_home}/current"
                : $this->production_home;

            return "{$base}/{$root}";
        }

        return $root;
    }

    public function usesProductionReleaseLayout(): bool
    {
        return
            $this->environment === 'production'
            && is_string($this->production_home)
            && str_starts_with($this->checkout_path, "{$this->production_home}/releases/");
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'migration_required' => 'boolean',
            'registration_detached' => 'boolean',
            'registration_primary' => 'boolean',
            'registration_include_worktrees' => 'boolean',
            'registration_worktree_paths' => 'array',
            'registration_source_device' => 'integer',
            'registration_source_inode' => 'integer',
            'registration_migration_recovery' => 'array',
            'registration_completed_at' => 'immutable_datetime',
            'source_is_laravel' => 'boolean',
            'status' => AppInstanceState::class,
        ];
    }
}
