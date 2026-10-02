<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property bool $required
 * @property string $name
 * @property string $command
 * @property int $timeout_seconds
 * @property int $position
 * @property-read Project $project
 */
final class ProjectDevelopmentDeployStep extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $hidden = ['command'];

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'project_id',
        'required',
        'name',
        'command',
        'timeout_seconds',
        'position',
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'timeout_seconds' => 'integer',
            'required' => 'boolean',
            'position' => 'integer',
        ];
    }
}
