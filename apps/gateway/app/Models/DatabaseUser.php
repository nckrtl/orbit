<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $database_connection_id
 * @property string $username
 * @property string $privileges
 * @property string|null $created_by
 * @property-read DatabaseConnection $databaseConnection
 */
final class DatabaseUser extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'database_connection_id',
        'username',
        'privileges',
        'created_by',
    ];

    /** @return BelongsTo<DatabaseConnection, $this> */
    public function databaseConnection(): BelongsTo
    {
        return $this->belongsTo(DatabaseConnection::class);
    }
}
