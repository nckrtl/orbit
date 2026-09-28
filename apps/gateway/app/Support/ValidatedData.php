<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class ValidatedData
{
    public static function string(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('Validated input must be a string.');
        }

        return $value;
    }

    public static function nullableString(mixed $value): ?string
    {
        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException('Validated input must be a string or null.');
        }

        return $value;
    }

    public static function integer(mixed $value): int
    {
        if (! is_int($value)) {
            throw new InvalidArgumentException('Validated input must be an integer.');
        }

        return $value;
    }

    /** @return list<string> */
    public static function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('Validated input must be a list.');
        }
        $result = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException('Validated list values must be strings.');
            }
            $result[] = $item;
        }

        return $result;
    }

    /** @return array<string, string> */
    public static function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Validated input must be an object.');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (! is_string($key) || ! is_string($item)) {
                throw new InvalidArgumentException('Validated object values must be strings.');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public static function object(mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Validated input must be an object.');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Validated object keys must be strings.');
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
