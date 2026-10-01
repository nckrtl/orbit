<?php

declare(strict_types=1);

namespace Orbit\Sdk\Support;

use JsonException;
use SensitiveParameter;
use stdClass;

/**
 * PHP arrays cannot tell an empty object from an empty list.
 * Objects stay arrays with string keys, and an empty object stays stdClass, so encoding keeps {}.
 *
 * @internal
 */
final class JsonObjects
{
    public static function decode(#[SensitiveParameter] string $json): mixed
    {
        if (trim($json) === '') {
            return null;
        }

        try {
            return self::preserve(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return null;
        }
    }

    public static function preserve(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);

            if ($properties === []) {
                return new stdClass;
            }

            $object = [];

            foreach ($properties as $key => $item) {
                $object[$key] = self::preserve($item);
            }

            return $object;
        }

        if (is_array($value)) {
            return array_map(self::preserve(...), $value);
        }

        return $value;
    }
}
