<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\LifecycleStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A MySQL server that Orbit runs as a Docker Node Process. The Gateway is the only holder of its
 * root password, which the model encrypts at rest and never serializes.
 *
 * @property int $id
 * @property string $slug
 * @property int $node_id
 * @property int|null $process_id
 * @property string $tag
 * @property int $port
 * @property string $root_password
 * @property LifecycleStatus $status
 * @property string|null $failed_step
 * @property string|null $error_code
 * @property-read Node $node
 * @property-read Process|null $process
 * @property-read Collection<int, DatabaseConnection> $databaseConnections
 */
final class DatabaseServer extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'slug',
        'node_id',
        'process_id',
        'tag',
        'port',
        'root_password',
        'status',
        'failed_step',
        'error_code',
    ];

    /** @var list<string> */
    #[\Override]
    protected $hidden = [
        'root_password',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return array<array-key, mixed> */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return BelongsTo<Process, $this> */
    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    /** @return HasMany<DatabaseConnection, $this> */
    public function databaseConnections(): HasMany
    {
        return $this->hasMany(DatabaseConnection::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'node_id' => 'integer',
            'process_id' => 'integer',
            'port' => 'integer',
            'root_password' => 'encrypted',
            'status' => LifecycleStatus::class,
        ];
    }
}
