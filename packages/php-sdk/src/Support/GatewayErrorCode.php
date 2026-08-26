<?php

declare(strict_types=1);

namespace Orbit\Sdk\Support;

use SensitiveParameter;

/** @internal */
final class GatewayErrorCode
{
    private const int MAX_LENGTH = 128;

    private const string PATTERN = '/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/D';

    public static function fromTransport(#[SensitiveParameter] mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > self::MAX_LENGTH) {
            return null;
        }

        return preg_match(self::PATTERN, $value) === 1 ? $value : null;
    }
}
