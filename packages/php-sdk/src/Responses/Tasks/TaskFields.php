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

    /**
     * A subtask's typed deliverables. String fields pass through, and a test deliverable may carry boolean
     * fails_on_base. A record without the list has none.
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, string|bool>>
     */
    public static function deliverables(array $data, string $record, string $requestId): array
    {
        $value = $data['deliverables'] ?? [];

        if (! is_array($value) || ! array_is_list($value)) {
            throw self::invalid($record, $requestId);
        }

        $deliverables = [];

        foreach ($value as $deliverable) {
            if (! is_array($deliverable) || ! is_string($deliverable['id'] ?? null) || ! is_string($deliverable['type'] ?? null)) {
                throw self::invalid($record, $requestId);
            }

            $fields = [];

            foreach ($deliverable as $key => $field) {
                if (! is_string($key)) {
                    throw self::invalid($record, $requestId);
                }

                if (is_string($field) || ($key === 'fails_on_base' && is_bool($field))) {
                    $fields[$key] = $field;
                }
            }

            $deliverables[] = $fields;
        }

        return $deliverables;
    }

    public static function invalid(string $record, string $requestId): GatewayApiException
    {
        return new GatewayApiException("Gateway response contains an invalid {$record}.", requestId: $requestId);
    }
}
