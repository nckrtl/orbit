<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

/**
 * The version the Gateway reports in its status: `APP_VERSION` when it is set, else the commit in
 * the release's `REVISION` file, else `dev`.
 */
final class GatewayVersion
{
    public static function resolve(mixed $configured, string $revisionFile): string
    {
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        if (is_file($revisionFile)) {
            $revision = trim((string) @file_get_contents($revisionFile));

            if (GatewayReleaseCommit::isSha($revision)) {
                return $revision;
            }
        }

        return 'dev';
    }
}
