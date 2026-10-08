<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A WireGuard peer that called the T3 Code layer. The WireGuard address is its identity. A Node's
 * peer links to that Node; a phone or laptop that is not a Node has no Node.
 *
 * @property int $id
 * @property string $wireguard_ip
 * @property int|null $node_id
 * @property int|null $t3_profile_id
 * @property Carbon $last_seen_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Node|null $node
 * @property-read T3Profile|null $profile
 */
final class T3Peer extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['wireguard_ip', 'node_id', 't3_profile_id', 'last_seen_at'];

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

    /** The Node name when the peer is a Node, otherwise its WireGuard address. */
    public function displayName(): string
    {
        return $this->node instanceof Node ? $this->node->name : $this->wireguard_ip;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'node_id' => 'integer',
            't3_profile_id' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }
}
