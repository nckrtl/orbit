<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Projects\ProjectUpdateStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property ProjectUpdateStatus $status
 * @property string $fingerprint
 * @property string|null $requested_slug
 * @property string|null $requested_repository_url
 * @property string|null $requested_default_branch
 * @property string $previous_slug
 * @property string $previous_repository_url
 * @property string|null $previous_default_branch
 * @property array<string, mixed>|null $inventory
 * @property array<string, mixed>|null $evidence
 * @property string|null $error_code
 * @property-read Project $project
 */
final class ProjectUpdate extends Model
{
    #[\Override]
    protected $table = 'project_updates';

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'project_id',
        'status',
        'fingerprint',
        'requested_slug',
        'requested_repository_url',
        'requested_default_branch',
        'previous_slug',
        'previous_repository_url',
        'previous_default_branch',
        'inventory',
        'evidence',
        'error_code',
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function mergeEvidence(array $evidence): void
    {
        $this->update([
            'evidence' => [
                ...($this->evidence ?? []),
                ...$evidence,
            ],
        ]);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ProjectUpdateStatus::class,
            'inventory' => 'array',
            'evidence' => 'array',
        ];
    }
}
