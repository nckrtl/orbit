<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $parent_id
 * @property string $kind
 * @property string $name
 * @property int $revision
 * @property int|null $current_version_id
 * @property Carbon|null $archived_at
 */
final class ProjectDocumentEntry extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['project_id', 'parent_id', 'sibling_scope', 'kind', 'name', 'revision', 'current_version_id', 'archived_at'];

    /** @var list<string> */
    #[\Override]
    protected $hidden = ['sibling_scope'];

    /** @return HasMany<ProjectDocumentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ProjectDocumentVersion::class, 'entry_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }
}
