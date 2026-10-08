<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A named set of T3 Code settings that the WireGuard peers bound to it share.
 *
 * @property int $id
 * @property string $name
 * @property array<string, mixed> $settings
 * @property int $settings_version
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, T3Peer> $peers
 */
final class T3Profile extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['name', 'settings', 'settings_version'];

    /** @return HasMany<T3Peer, $this> */
    public function peers(): HasMany
    {
        return $this->hasMany(T3Peer::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'settings_version' => 'integer',
        ];
    }
}
