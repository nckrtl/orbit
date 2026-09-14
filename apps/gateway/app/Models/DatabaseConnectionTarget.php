<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $database_connection_id
 * @property int $app_instance_id
 * @property string $prefix
 * @property-read DatabaseConnection $databaseConnection
 * @property-read AppInstance $appInstance
 */
final class DatabaseConnectionTarget extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'database_connection_id',
        'app_instance_id',
        'prefix',
    ];

    /** @return BelongsTo<DatabaseConnection, $this> */
    public function databaseConnection(): BelongsTo
    {
        return $this->belongsTo(DatabaseConnection::class);
    }

    /** @return BelongsTo<AppInstance, $this> */
    public function appInstance(): BelongsTo
    {
        return $this->belongsTo(AppInstance::class);
    }
}
