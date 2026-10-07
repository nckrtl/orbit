<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

/**
 * Commit and release-id rules. A release is named by the first 12 hex digits of its commit. A
 * caller names a commit by hex SHA only, never by branch, tag, or other revision syntax.
 */
final class GatewayReleaseCommit
{
    public const int IdLength = 12;

    /** A lower-case hex SHA prefix of at least 7 digits, as typed by a caller. */
    public static function parse(string $revision): string
    {
        $revision = strtolower($revision);

        if (preg_match('/\A[0-9a-f]{7,40}\z/D', $revision) !== 1) {
            throw new GatewayReleaseException(
                step: 'resolve',
                errorCode: 'gateway.release_commit_invalid',
                message: 'Name the commit by its hex SHA (7 to 40 characters).',
                status: 422,
            );
        }

        return $revision;
    }

    public static function isSha(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{40}\z/D', $value) === 1;
    }

    public static function isId(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{12}\z/D', $value) === 1;
    }

    public static function assertId(string $value): string
    {
        if (! self::isId($value)) {
            throw new GatewayReleaseException(
                step: 'resolve',
                errorCode: 'gateway.release_id_invalid',
                message: 'A release id is the first 12 hex digits of its commit.',
                status: 422,
            );
        }

        return $value;
    }

    public static function id(string $sha): string
    {
        return substr($sha, 0, self::IdLength);
    }
}
