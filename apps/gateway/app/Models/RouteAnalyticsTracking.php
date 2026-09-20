<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $route_id
 * @property int $app_instance_id
 * @property-read Route|null $route
 * @property-read AppInstance|null $appInstance
 */
final class RouteAnalyticsTracking extends Model
{
    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $keyType = 'int';

    #[\Override]
    protected $primaryKey = 'route_id';

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'route_id',
        'app_instance_id',
    ];

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
}
