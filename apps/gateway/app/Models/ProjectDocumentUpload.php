<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $entry_id
 * @property string $storage_key
 * @property string $state
 * @property Carbon $created_at
 */
final class ProjectDocumentUpload extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['project_id', 'entry_id', 'storage_key', 'state'];

    /** @var list<string> */
    #[\Override]
    protected $hidden = ['storage_key'];
}
