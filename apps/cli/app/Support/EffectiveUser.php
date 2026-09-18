<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The effective user ID of this process.
 *
 * The packaged CLI binary ships without the posix extension, so the fallback reads the owner of
 * a file this process just created, which the kernel sets to the effective user ID.
 */
final class EffectiveUser
{
    private static ?int $id = null;

    public static function id(): ?int
    {
        if (self::$id !== null) {
            return self::$id;
        }

        if (function_exists('posix_geteuid')) {
            return self::$id = posix_geteuid();
        }

        $probe = tempnam(sys_get_temp_dir(), 'orbit-euid-');

        if (! is_string($probe)) {
            return null;
        }

        $owner = fileowner($probe);
        @unlink($probe);

        return is_int($owner) ? self::$id = $owner : null;
    }
}
