<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $database_connection_id
 * @property int $instance_id
 * @property string $prefix
 * @property-read DatabaseConnection $databaseConnection
 * @property-read Instance $instance
 */
final class DatabaseConnectionTarget extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'database_connection_id',
        'instance_id',
        'prefix',
    ];

    /** @return BelongsTo<DatabaseConnection, $this> */
    public function databaseConnection(): BelongsTo
    {
        return $this->belongsTo(DatabaseConnection::class);
    }

    /** @return BelongsTo<Instance, $this> */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class, 'instance_id');
    }
}
