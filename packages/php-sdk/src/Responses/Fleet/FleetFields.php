<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Fleet;

use Orbit\Sdk\GatewayApiException;

/** @internal Field readers for fleet rollout responses. */
final class FleetFields
{
    public static function invalid(string $record, string $requestId): GatewayApiException
    {
        return new GatewayApiException("Gateway response contains an invalid {$record}.", requestId: $requestId);
    }

    /** @param array<array-key, mixed> $data */
    public static function string(array $data, string $key, string $record, string $requestId): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            throw self::invalid($record, $requestId);
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    public static function nullableString(array $data, string $key, string $record, string $requestId): ?string
    {
        $value = $data[$key] ?? null;

        if ($value !== null && ! is_string($value)) {
            throw self::invalid($record, $requestId);
        }

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    public static function int(array $data, string $key, string $record, string $requestId): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value)) {
            throw self::invalid($record, $requestId);
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>|null
     */
    public static function nullableObject(array $data, string $key, string $record, string $requestId): ?array
    {
        $value = $data[$key] ?? null;

        if ($value !== null && ! is_array($value)) {
            throw self::invalid($record, $requestId);
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<array<array-key, mixed>>
     */
    public static function rows(array $data, string $key, string $record, string $requestId): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            throw self::invalid($record, $requestId);
        }

        $rows = [];

        foreach ($value as $row) {
            if (! is_array($row)) {
                throw self::invalid($record, $requestId);
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
