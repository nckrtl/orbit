<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property int $app_id
 * @property string $name
 * @property list<string> $environments
 * @property array<string, mixed> $spec
 * @property-read App $app
 */
final class ProcessDefinition extends Model
{
    #[\Override]
    public $incrementing = false;

    #[\Override]
    protected $keyType = 'string';

    /** @var list<string> */
    #[\Override]
    protected $fillable = ['app_id', 'name', 'environments', 'spec'];

    /** @var list<string> */
    #[\Override]
    protected $hidden = ['spec'];

    protected static function booted(): void
    {
        self::creating(static function (self $definition): void {
            $id = $definition->getAttribute('id');
            $definition->id = is_string($id) && $id !== '' ? $id : (string) Str::uuid();
        });
    }

    /** @return array<array-key, mixed> */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    /** @return BelongsTo<App, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'environments' => 'array',
            'spec' => 'array',
        ];
    }
}
