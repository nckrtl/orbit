<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $storage_key
 * @property bool $retained_fence
 * @property bool $pending
 * @property string|null $last_error_code
 */
final class ProjectDocumentCleanup extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['storage_key', 'retained_fence', 'pending', 'next_attempt_at', 'attempts', 'last_error_code'];

    /** @var list<string> */
    #[\Override]
    protected $hidden = ['storage_key'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['retained_fence' => 'boolean', 'pending' => 'boolean', 'next_attempt_at' => 'datetime'];
    }
}
