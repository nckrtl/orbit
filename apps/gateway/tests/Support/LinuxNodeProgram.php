<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Marks a test that runs an Ubuntu Node program on the test host.
 *
 * Some Node programs rely on Linux kernel interfaces, such as os.O_PATH and /proc. No other host provides
 * them, and the test would only prove the program on Linux anyway. Those tests fail on another host with a
 * clear reason instead of an opaque program failure. CI runs them on Linux for every pull request.
 */
final class LinuxNodeProgram
{
    public static function require(string $interfaces): void
    {
        if (PHP_OS_FAMILY === 'Linux') {
            return;
        }

        throw new RuntimeException(sprintf(
            'This test runs an Ubuntu Node program that uses %s, which only Linux provides. '
            .'Run it on a Linux host; CI runs it for every pull request.',
            $interfaces,
        ));
    }
}
