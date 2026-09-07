<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $app_instance_removal_id
 * @property int $position
 * @property int $app_instance_id
 * @property int $app_id
 * @property int $node_id
 * @property int|null $route_id
 * @property string $name
 * @property string $environment
 * @property string $source_layout
 * @property string|null $repository_identity
 * @property string|null $checkout_path
 * @property string|null $root
 * @property string|null $branch
 * @property string|null $starting_commit
 * @property string|null $common_repository_path
 * @property list<string> $linked_worktree_paths
 * @property string $source_digest
 * @property \Illuminate\Support\Carbon|null $source_prepared_at
 * @property \Illuminate\Support\Carbon|null $route_cleared_at
 * @property string|null $route_outcome
 * @property \Illuminate\Support\Carbon|null $source_finalized_at
 * @property string|null $finalization_receipt
 * @property \Illuminate\Support\Carbon|null $runtime_cleaned_at
 * @property \Illuminate\Support\Carbon|null $row_deleted_at
 * @property-read AppInstanceRemoval $removal
 */
final class AppInstanceRemovalMember extends Model
{
    /** @var array<int, string> */
    #[\Override]
    protected $fillable = [
        'app_instance_removal_id',
        'position',
        'app_instance_id',
        'app_id',
        'node_id',
        'route_id',
        'name',
        'environment',
        'source_layout',
        'repository_identity',
        'checkout_path',
        'root',
        'branch',
        'starting_commit',
        'common_repository_path',
        'linked_worktree_paths',
        'source_digest',
        'source_prepared_at',
        'route_cleared_at',
        'route_outcome',
        'source_finalized_at',
        'finalization_receipt',
        'runtime_cleaned_at',
        'row_deleted_at',
    ];

    /** @return BelongsTo<AppInstanceRemoval, $this> */
    public function removal(): BelongsTo
    {
        return $this->belongsTo(AppInstanceRemoval::class, 'app_instance_removal_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_prepared_at' => 'datetime',
            'linked_worktree_paths' => 'array',
            'route_cleared_at' => 'datetime',
            'source_finalized_at' => 'datetime',
            'runtime_cleaned_at' => 'datetime',
            'row_deleted_at' => 'datetime',
        ];
    }
}
