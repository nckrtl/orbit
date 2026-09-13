<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $jti
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 */
final class HerdrObservationNonce extends Model
{
    #[\Override]
    public $incrementing = false;

    #[\Override]
    public $timestamps = false;

    #[\Override]
    protected $primaryKey = 'jti';

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'jti',
        'expires_at',
        'consumed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
