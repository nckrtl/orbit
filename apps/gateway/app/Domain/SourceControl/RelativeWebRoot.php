<?php

declare(strict_types=1);

namespace App\Domain\SourceControl;

use InvalidArgumentException;

final class RelativeWebRoot
{
    public static function validate(string $root): string
    {
        if (! self::isValid($root)) {
            throw new InvalidArgumentException('The relative web root is invalid.');
        }

        return $root;
    }

    public static function isValid(string $root): bool
    {
        if ($root === '' || strlen($root) > 255 || str_starts_with($root, '/') || str_ends_with($root, '/')) {
            return false;
        }

        if (str_contains($root, '//')) {
            return false;
        }

        return array_all(
            explode('/', $root),
            static fn (string $segment): bool => ! (
                $segment === ''
                || $segment === '.'
                || $segment === '..'
                || preg_match('/\A[A-Za-z0-9._-]+\z/D', $segment) !== 1
            ),
        );
    }
}
