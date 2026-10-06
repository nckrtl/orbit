<?php

declare(strict_types=1);

namespace App\Domain\Instances\Apps;

final readonly class AppProjectionIdentity
{
    /** @param array<array-key, mixed> $value */
    public static function digest(array $value): string
    {
        return hash('sha256', json_encode(self::normalize($value), JSON_THROW_ON_ERROR));
    }

    public static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            if ($value !== [] && array_all($value, static fn (mixed $entry): bool => is_array($entry) && isset($entry['name']) && is_string($entry['name']))) {
                usort($value, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));
            }
        } else {
            ksort($value);
        }

        return array_map(self::normalize(...), $value);
    }
}
