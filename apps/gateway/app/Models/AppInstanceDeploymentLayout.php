<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\AppInstances\DeploymentLayout\DeploymentLayoutStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $app_instance_id
 * @property DeploymentLayoutStep $step
 * @property string $source_path
 * @property string $release_path
 * @property string|null $sqlite_source_path
 * @property array<string, mixed> $inventory
 * @property string|null $failed_step
 * @property string|null $error_code
 * @property Carbon|null $completed_at
 * @property-read AppInstance $appInstance
 */
final class AppInstanceDeploymentLayout extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'app_instance_id',
        'step',
        'source_path',
        'release_path',
        'sqlite_source_path',
        'inventory',
        'failed_step',
        'error_code',
        'completed_at',
    ];

    /** @return BelongsTo<AppInstance, $this> */
    public function appInstance(): BelongsTo
    {
        return $this->belongsTo(AppInstance::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'step' => DeploymentLayoutStep::class,
            'inventory' => 'array',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
