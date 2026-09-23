<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tasks;

use Orbit\Sdk\GatewayApiException;

/**
 * Bounded field reads shared by the task responses.
 *
 * @internal
 */
final class TaskFields
{
    /** @param array<string, mixed> $data */
    public static function id(array $data, string $key, string $record, string $requestId): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value) || $value < 1) {
            throw self::invalid($record, $requestId);
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function text(array $data, string $key, string $record, string $requestId): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            throw self::invalid($record, $requestId);
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function nullableText(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @param array<string, mixed> $data */
    public static function nullableInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    public static function invalid(string $record, string $requestId): GatewayApiException
    {
        return new GatewayApiException("Gateway response contains an invalid {$record}.", requestId: $requestId);
    }
}
