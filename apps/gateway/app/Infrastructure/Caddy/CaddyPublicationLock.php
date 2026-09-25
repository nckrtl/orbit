<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy;

/**
 * The one Node-local lock that a Node Caddy build holds while it swaps the Caddyfile and reloads, and that
 * a certificate step holds while it reloads Caddy.
 */
final readonly class CaddyPublicationLock
{
    public const string Path = '/run/lock/orbit/caddy.lock';

    /**
     * Bash that opens the lock on descriptor 9 and waits up to 30 seconds for it.
     *
     * Without a path, the script reads the lock path from `$lock`. At Path, the lock must be a
     * root-owned 0600 regular file in a root-owned 0700 directory.
     */
    public static function script(?string $path = null): string
    {
        $assignment = $path === null ? '' : 'lock='.escapeshellarg($path).PHP_EOL;
        $hardened = self::Path;

        return $assignment.<<<BASH
            lock_directory=\$(dirname "\$lock")
            if ! mkdir -m 0700 -- "\$lock_directory" 2>/dev/null; then
                test -d "\$lock_directory"
                test ! -L "\$lock_directory"
            fi
            if [ "\$lock" = {$hardened} ]; then
                test "\$(stat -c %u:%g:%a -- "\$lock_directory")" = 0:0:700
            fi
            if [ -e "\$lock" ] || [ -L "\$lock" ]; then
                test ! -L "\$lock"
                test -f "\$lock"
                if [ "\$lock" = {$hardened} ]; then
                    test "\$(stat -c %u:%g -- "\$lock")" = 0:0
                fi
            fi
            exec 9>>"\$lock"
            if [ "\$lock" = {$hardened} ]; then
                chmod 0600 -- "\$lock"
                test "\$(stat -c %a -- "\$lock")" = 600
            fi
            flock -w 30 9
            BASH;
    }
}
