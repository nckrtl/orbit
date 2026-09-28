<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $route_id
 * @property int $instance_id
 * @property int $position
 * @property-read Route $route
 * @property-read Instance $appInstance
 */
final class RouteTarget extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['route_id', 'instance_id', 'position'];

    /** @return BelongsTo<Route, $this> */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    /** @return BelongsTo<Instance, $this> */
    public function appInstance(): BelongsTo
    {
        return $this->belongsTo(Instance::class, 'instance_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
