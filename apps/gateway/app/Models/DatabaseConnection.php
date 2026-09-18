<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\DatabaseConnections\DatabaseDriver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $slug
 * @property DatabaseDriver $driver
 * @property int|null $node_id
 * @property string|null $host
 * @property int|null $port
 * @property string|null $database
 * @property string|null $path
 * @property string|null $username
 * @property string|null $password
 * @property-read Node|null $node
 * @property-read Collection<int, DatabaseConnectionTarget> $targets
 * @property-read Collection<int, DatabaseUser> $users
 */
final class DatabaseConnection extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'slug',
        'driver',
        'node_id',
        'host',
        'port',
        'database',
        'path',
        'username',
        'password',
    ];

    /** @var list<string> */
    #[\Override]
    protected $hidden = [
        'password',
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

    /** @return HasMany<DatabaseConnectionTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(DatabaseConnectionTarget::class);
    }

    /** @return HasMany<DatabaseUser, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(DatabaseUser::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'driver' => DatabaseDriver::class,
            'port' => 'integer',
            'password' => 'encrypted',
        ];
    }
}
