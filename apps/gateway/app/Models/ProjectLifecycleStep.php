<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $app_id
 * @property string $phase
 * @property string $name
 * @property string $command
 * @property int $timeout_seconds
 * @property int $position
 * @property-read App $app
 */
final class ProjectLifecycleStep extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $hidden = ['command'];

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'app_id',
        'phase',
        'name',
        'command',
        'timeout_seconds',
        'position',
    ];

    /** @return BelongsTo<App, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'timeout_seconds' => 'integer',
            'position' => 'integer',
        ];
    }
}
