<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $storage_key
 * @property bool $retained_fence
 * @property bool $pending
 * @property string|null $last_error_code
 * @property string|null $claim_token
 * @property Carbon|null $claim_expires_at
 * @property int $attempts
 */
final class ProjectDocumentCleanup extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['storage_key', 'retained_fence', 'pending', 'next_attempt_at', 'attempts', 'last_error_code', 'claim_token', 'claim_expires_at'];

    /** @var list<string> */
    #[\Override]
    protected $hidden = ['storage_key'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['retained_fence' => 'boolean', 'pending' => 'boolean', 'next_attempt_at' => 'datetime', 'claim_expires_at' => 'datetime', 'attempts' => 'integer'];
    }
}
