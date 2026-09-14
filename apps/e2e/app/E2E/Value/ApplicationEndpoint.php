<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/**
 * One application endpoint from either the replacement `domain` contract or the
 * earlier `hostname` contract. A present `domain` never falls back to `hostname`.
 */
final readonly class ApplicationEndpoint
{
    public const string PATTERN = '/\A[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?\z/D';

    /**
     * @param  list<string>  $baseKeys
     * @param  array<array-key, mixed>  $record
     */
    public static function placementKeysMatch(array $baseKeys, array $record): bool
    {
        $keys = array_keys($record);
        if (array_slice($keys, 0, count($baseKeys)) !== $baseKeys) {
            return false;
        }

        $endpointKeys = array_slice($keys, count($baseKeys));
        sort($endpointKeys);

        return in_array($endpointKeys, [['domain'], ['hostname'], ['domain', 'hostname']], true);
    }

    /** @param array<array-key, mixed> $record */
    public static function fromRecord(array $record): string
    {
        if (array_key_exists('domain', $record)) {
            return self::validated($record['domain']);
        }

        if (array_key_exists('hostname', $record)) {
            return self::validated($record['hostname']);
        }

        $route = $record['route'] ?? null;
        if (is_array($route) && ! array_is_list($route)) {
            if (array_key_exists('domain', $route)) {
                return self::validated($route['domain']);
            }

            if (array_key_exists('hostname', $route)) {
                return self::validated($route['hostname']);
            }
        }

        throw new InvalidArgumentException('The application endpoint is missing.');
    }

    private static function validated(mixed $value): string
    {
        if (! is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('The application endpoint is invalid.');
        }

        return $value;
    }
}
