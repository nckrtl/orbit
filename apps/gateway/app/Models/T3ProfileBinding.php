<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Binds a Node to the one T3 Code profile whose settings it shares.
 *
 * @property int $id
 * @property int $node_id
 * @property int $t3_profile_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Node $node
 * @property-read T3Profile $profile
 */
final class T3ProfileBinding extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['node_id', 't3_profile_id'];

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return BelongsTo<T3Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(T3Profile::class, 't3_profile_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'node_id' => 'integer',
            't3_profile_id' => 'integer',
        ];
    }
}
