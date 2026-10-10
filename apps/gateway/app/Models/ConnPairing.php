<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A pairing link the Gateway minted on a T3 server for one Node. The session the Node gets from it
 * carries `client_label` on that server, which is how a revoke finds it.
 *
 * @property int $id
 * @property int $node_id
 * @property int $conn_environment_id
 * @property string $pairing_link_id
 * @property string $client_label
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Node $node
 * @property-read ConnEnvironment $environment
 */
final class ConnPairing extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['node_id', 'conn_environment_id', 'pairing_link_id', 'client_label', 'expires_at', 'revoked_at'];

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return BelongsTo<ConnEnvironment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(ConnEnvironment::class, 'conn_environment_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'node_id' => 'integer',
            'conn_environment_id' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
