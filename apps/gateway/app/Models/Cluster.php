<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Clusters\ClusterState;
use App\Domain\Shared\LifecycleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $name
 * @property string|null $tld
 * @property ClusterState $state
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Node> $nodes
 * @property-read NodeRole|null $routerAssignment
 */
final class Cluster extends Model
{
    /** @var array<string, mixed> */
    #[\Override]
    protected $attributes = [
        'state' => 'inactive',
    ];

    /** @var array<int, string> */
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
