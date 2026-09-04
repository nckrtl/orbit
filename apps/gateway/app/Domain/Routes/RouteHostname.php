<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Shared\ResourceOperationException;

final class RouteHostname
{
    private const string PATTERN = '/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/D';

    public static function normalize(string $hostname): string
    {
        return mb_strtolower(trim($hostname));
    }

    public static function isValid(string $hostname): bool
    {
        $normalized = self::normalize($hostname);

        return $normalized !== '' && strlen($normalized) <= 253 && preg_match(self::PATTERN, $normalized) === 1;
    }

    public static function validate(string $hostname): string
    {
        $normalized = self::normalize($hostname);

        if (! self::isValid($normalized)) {
            throw new ResourceOperationException(
                errorCode: 'route.hostname_invalid',
                message: 'The Route hostname is invalid.',
            );
        }

        return $normalized;
    }
}
