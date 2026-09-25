<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Clusters\ClusterState;
use App\Domain\Shared\LifecycleStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $name
 * @property string|null $tld
 * @property ClusterState $state
 * @property-read Collection<int, Node> $nodes
 * @property-read NodeRole|null $routerAssignment
 * @property-read NodeRole|null $ingressAssignment
 */
final class Cluster extends Model
{
    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = [
        'state' => 'inactive',
    ];

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'name',
        'tld',
        'state',
    ];

    /** @return HasMany<Node, $this> */
    public function nodes(): HasMany
    {
        return $this->hasMany(Node::class);
    }

    /** @return HasOne<NodeRole, $this> */
    public function routerAssignment(): HasOne
    {
        return $this
            ->hasOne(NodeRole::class)
            ->where('role', 'router')
            ->where('status', LifecycleStatus::Active);
    }

    /**
     * The Ingress assignment while it serves public sites: active, converging, or after a failed convergence.
     * PublicRouteEligibility::servingIngress applies the same rule.
     *
     * @return HasOne<NodeRole, $this>
     */
    public function ingressAssignment(): HasOne
    {
        return $this
            ->hasOne(NodeRole::class)
            ->where('role', 'ingress')
            ->where(static fn (Builder $query): Builder => $query
                ->whereIn('status', [LifecycleStatus::Active, LifecycleStatus::Provisioning])
                ->orWhere(static fn (Builder $failed): Builder => $failed
                    ->where('status', LifecycleStatus::Failed)
                    ->where('failed_step', 'like', 'converge:%')));
    }

    /** @return HasMany<Route, $this> */
    public function routes(): HasMany
    {
        return $this->hasMany(Route::class);
    }

    /** @return array<string, class-string> */
    protected function casts(): array
    {
        return ['state' => ClusterState::class];
    }
}
