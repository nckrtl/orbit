<?php

declare(strict_types=1);

namespace App\E2E;

use Throwable;

/**
 * Copy a map so its string keys are part of the value's type.
 *
 * JSON objects and reviewed inventories use string keys. An integer key is not
 * that shape, so the copy refuses it instead of pretending the map was typed.
 */
final class StringKeyedMap
{
    /**
     * @param  array<mixed, mixed>  $value
     * @return array<string, mixed>
     */
    public static function of(array $value, Throwable $failure): array
    {
        $mapped = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw $failure;
            }
            $mapped[$key] = $item;
        }

        return $mapped;
    }
}
