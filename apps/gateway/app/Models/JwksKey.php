<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $kid
 * @property string $algorithm
 * @property string $private_pem
 * @property string $public_pem
 */
final class JwksKey extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'kid',
        'algorithm',
        'private_pem',
        'public_pem',
    ];

    /** @var list<string> */
    #[\Override]
    protected $hidden = [
        'private_pem',
    ];
}
