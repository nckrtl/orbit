<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A pairing link the Gateway minted on a T3 server for one peer. The session the peer gets from it
 * carries `client_label` on that server, which is how a revoke finds it.
 *
 * @property int $id
 * @property int $t3_peer_id
 * @property int $t3_environment_id
 * @property string $pairing_link_id
 * @property string $client_label
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read T3Peer $peer
 * @property-read T3Environment $environment
 */
final class T3Pairing extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['t3_peer_id', 't3_environment_id', 'pairing_link_id', 'client_label', 'expires_at', 'revoked_at'];

    /** @return BelongsTo<T3Peer, $this> */
    public function peer(): BelongsTo
    {
        return $this->belongsTo(T3Peer::class, 't3_peer_id');
    }

    /** @return BelongsTo<T3Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(T3Environment::class, 't3_environment_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            't3_peer_id' => 'integer',
            't3_environment_id' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
