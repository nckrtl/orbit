<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A named set of Conn settings that the Nodes bound to it share.
 *
 * @property int $id
 * @property string $name
 * @property array<string, mixed> $settings
 * @property int $settings_version
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, ConnProfileBinding> $bindings
 */
final class ConnProfile extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['name', 'settings', 'settings_version'];

    /** @return HasMany<ConnProfileBinding, $this> */
    public function bindings(): HasMany
    {
        return $this->hasMany(ConnProfileBinding::class);
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
