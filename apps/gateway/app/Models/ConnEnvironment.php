<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A T3 server that registered with the Gateway and handed it an admin session.
 *
 * @property int $id
 * @property string $environment_id
 * @property string $label
 * @property string $url
 * @property int|null $node_id
 * @property string|null $server_version
 * @property string $admin_session
 * @property Carbon $admin_session_expires_at
 * @property Carbon $registered_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Node|null $node
 */
final class ConnEnvironment extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'environment_id',
        'label',
        'url',
        'node_id',
        'server_version',
        'admin_session',
        'admin_session_expires_at',
        'registered_at',
    ];

    /** @var list<string> */
    #[\Override]
    protected $hidden = ['admin_session'];

    #[\Override]
    public function getRouteKeyName(): string
    {
        return 'environment_id';
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'node_id' => 'integer',
            'admin_session' => 'encrypted',
            'admin_session_expires_at' => 'datetime',
            'registered_at' => 'datetime',
        ];
    }
}
