<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tasks\TaskDefinitionJsonCast;
use App\Domain\Tasks\TaskDefinitionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string $title
 * @property string $brief
 * @property list<array<string, mixed>> $parameters
 * @property TaskDefinitionStatus $status
 * @property array<string, mixed>|null $schedule
 * @property list<array<string, mixed>> $phases
 * @property list<array<string, mixed>> $subtasks
 * @property-read Project $project
 */
final class TaskDefinition extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'project_id',
        'name',
        'title',
        'brief',
        'parameters',
        'status',
        'schedule',
        'phases',
        'subtasks',
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'parameters' => TaskDefinitionJsonCast::class,
            'schedule' => 'array',
            'phases' => 'array',
            'subtasks' => TaskDefinitionJsonCast::class,
            'status' => TaskDefinitionStatus::class,
        ];
    }
}
