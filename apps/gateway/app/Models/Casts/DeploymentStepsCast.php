<?php

declare(strict_types=1);

namespace App\Models\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use JsonException;
use UnexpectedValueException;

/** @implements CastsAttributes<list<array<string, mixed>>, list<array<string, mixed>>> */
final class DeploymentStepsCast implements CastsAttributes
{
    /** @return list<array<string, mixed>> */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [];
        }

        try {
            $decoded = json_decode((string) $value, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('The stored deployment steps are invalid.', previous: $exception);
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new UnexpectedValueException('The stored deployment steps are invalid.');
        }

        /** @var list<array<string, mixed>> $decoded */
        return $decoded;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('The deployment steps could not be stored.', previous: $exception);
        }
    }
}
