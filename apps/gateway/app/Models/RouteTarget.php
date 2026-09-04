<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $route_id
 * @property int $app_instance_id
 * @property int $position
 * @property-read Route $route
 * @property-read AppInstance $appInstance
 */
final class RouteTarget extends Model
{
    /** @var array<int, string> */
    #[\Override]
    protected $fillable = ['route_id', 'app_instance_id', 'position'];

    /** @return BelongsTo<Route, $this> */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    /** @return BelongsTo<AppInstance, $this> */
    public function appInstance(): BelongsTo
    {
        return $this->belongsTo(AppInstance::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
