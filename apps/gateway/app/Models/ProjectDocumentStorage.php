<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $endpoint
 * @property string|null $region
 * @property string|null $bucket
 * @property string|null $access_key_id
 * @property string|null $secret_access_key
 * @property Carbon|null $updated_at
 */
final class ProjectDocumentStorage extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['endpoint', 'region', 'bucket', 'access_key_id', 'secret_access_key'];

    /** @var list<string> */
    #[\Override]
    protected $hidden = ['access_key_id', 'secret_access_key'];

    /** Compare credential ciphertext without decrypting the old value during lost-key recovery. */
    #[\Override]
    public function originalIsEquivalent(mixed $key): bool
    {
        if (in_array($key, ['access_key_id', 'secret_access_key'], true)) {
            return ($this->attributes[$key] ?? null) === ($this->original[$key] ?? null);
        }

        return parent::originalIsEquivalent($key);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['access_key_id' => 'encrypted', 'secret_access_key' => 'encrypted'];
    }

    /** @return array<array-key, mixed> */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }
}
