<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use JsonException;

/**
 * Keeps JSON objects through a read, including a nested empty object.
 *
 * @implements CastsAttributes<array<mixed>|null, array<mixed>|null>
 */
final class TaskDefinitionJsonCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = TaskDefinitionJson::decode($value);

        return is_array($decoded) ? $decoded : null;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }
}
