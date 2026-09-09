<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\AppInstanceState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
 * @property string|null $root
 * @property string|null $branch
 * @property string|null $branch_override
 * @property bool $migration_required
 * @property string|null $starting_commit
 * @property string|null $selected_php_version
 * @property bool|null $source_is_laravel
 * @property string|null $provisioning_step
 * @property string|null $failed_step
 * @property string|null $error_code
 * @property AppInstanceState $status
 * @property-read App $app
 * @property-read Node $node
 * @property-read \Illuminate\Database\Eloquent\Collection<int, RouteTarget> $routeTargets
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Route> $routes
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
        'status' => 'reserved',
    ];

    /** @var array<int, string> */
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
        'root',
        'branch',
        'branch_override',
        'migration_required',
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

    /** @return HasOne<AppInstanceRemovalMember, $this> */
    public function removalMember(): HasOne
    {
        return $this->hasOne(AppInstanceRemovalMember::class)->whereNull('row_deleted_at');
    }

    public function effectiveRoot(): ?string
    {
        $root = $this->root ?? $this->app->root;

        if ($this->environment === 'production' && is_string($this->production_home) && is_string($root)) {
            return "{$this->production_home}/{$root}";
        }

        return $root;
    }

    /** @return array<string, class-string> */
    protected function casts(): array
    {
        return [
            'migration_required' => 'boolean',
            'source_is_laravel' => 'boolean',
            'status' => AppInstanceState::class,
        ];
    }
}
