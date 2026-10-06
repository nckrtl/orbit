<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $entry_id
 * @property int $upload_id
 * @property int $number
 * @property string $media_type
 * @property int $size_bytes
 * @property string $sha256
 * @property string $storage_key
 * @property int|null $created_by_node_id
 */
final class ProjectDocumentVersion extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    #[\Override]
    protected $fillable = ['entry_id', 'upload_id', 'number', 'media_type', 'size_bytes', 'sha256', 'storage_key', 'created_by_node_id'];

    /** @var list<string> */
    #[\Override]
    protected $hidden = ['storage_key', 'upload_id'];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Document versions are immutable.');
        });
        self::deleting(static function (): never {
            throw new LogicException('Remove the owning document, not an individual version.');
        });
    }
}
