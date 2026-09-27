<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use UnexpectedValueException;

/**
 * Integers that the database returns as mixed: a PHP int, or a base-10 string from a driver
 * that does not cast numeric columns.
 */
final class StoredInteger
{
    public static function from(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1) {
            return (int) $value;
        }

        throw new UnexpectedValueException('Expected an integer value.');
    }

    /**
     * Aggregates such as max() and a missing row's value() are null. That absence is zero,
     * matching the previous integer cast of null.
     */
    public static function fromOrZero(mixed $value): int
    {
        if ($value === null) {
            return 0;
        }

        return self::from($value);
    }

    /**
     * @return list<int>
     */
    public static function listFrom(mixed $values): array
    {
        if (! is_array($values)) {
            throw new UnexpectedValueException('Expected a list of integers.');
        }

        $integers = [];

        foreach ($values as $value) {
            $integers[] = self::from($value);
        }

        return $integers;
    }
}
