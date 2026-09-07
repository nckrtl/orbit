<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\AppInstances\AppInstanceSourceKind;
use App\Domain\AppInstances\AppInstanceState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $app_id
 * @property int $node_id
 * @property string $name
 * @property string $environment
 * @property string $source_kind
 * @property string $checkout_path
 * @property string|null $root
 * @property string|null $branch
 * @property string|null $starting_commit
 * @property string|null $selected_php_version
 * @property string|null $provisioning_step
 * @property string|null $failed_step
 * @property string|null $error_code
 * @property AppInstanceState $status
 * @property-read App $app
 * @property-read Node $node
 * @property-read \Illuminate\Database\Eloquent\Collection<int, RouteTarget> $routeTargets
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Route> $routes
 */
final class AppInstance extends Model
{
    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = [
        'environment' => 'development',
        'source_kind' => 'managed_clone',
        'status' => 'reserved',
    ];

    /** @var array<int, string> */
    #[\Override]
    protected $fillable = [
        'app_id',
        'node_id',
        'name',
        'environment',
        'source_kind',
        'checkout_path',
        'root',
        'branch',
        'starting_commit',
        'selected_php_version',
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

    public function effectiveRoot(): ?string
    {
        return $this->root ?? $this->app->root;
    }

    /** @return array<string, class-string> */
    protected function casts(): array
    {
        return [
            'status' => AppInstanceState::class,
        ];
    }
}
