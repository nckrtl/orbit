<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Logs;

/** A log stream ID is 128 random bits written as 32 lowercase hexadecimal characters. */
final class LogStreamId
{
    /** @phpstan-assert-if-true string $value */
    public static function valid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{32}\z/D', $value) === 1;
    }
}
