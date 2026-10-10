<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Binds a Node to the one Conn profile whose settings it shares.
 *
 * @property int $id
 * @property int $node_id
 * @property int $conn_profile_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Node $node
 * @property-read ConnProfile $profile
 */
final class ConnProfileBinding extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['node_id', 'conn_profile_id'];

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return BelongsTo<ConnProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(ConnProfile::class, 'conn_profile_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'node_id' => 'integer',
            'conn_profile_id' => 'integer',
        ];
    }
}
