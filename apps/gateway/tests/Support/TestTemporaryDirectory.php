<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Gives each test process a unique, traversable fixture root outside private role TMPDIRs.
 *
 * Cross-user tests grant access on individual fixtures; their parent must be traversable too.
 * Canonicalize /tmp on macOS, where it is a symlink. PHP caches sys_get_temp_dir(), so this
 * runs before other test bootstrap code. Production/check processes retain their private TMPDIR;
 * only test processes replace it. Shell programs under test inherit the same fixture root.
 */
final class TestTemporaryDirectory
{
    public static function bootstrap(): void
    {
        $configured = getenv('TMPDIR');
        $directory = is_string($configured) && $configured !== '' ? $configured : '/tmp';
        // Cross-user fixtures cannot traverse a role-private workspace TMPDIR ancestor.
        // Preserve other callers' configured roots (including database safety probes).
        if (preg_match('~/(?:orbit/tmp/(?:agent|check)-[0-9]+|orbit-check-[0-9]+-[^/]+)$~', $directory) === 1) {
            $directory = '/tmp/orbit-gateway-tests-'.bin2hex(random_bytes(12));
            if (! mkdir($directory, 0755)) {
                throw new RuntimeException('Could not create the shared test fixture root.');
            }
            chmod($directory, 0755);
            register_shutdown_function(static function () use ($directory): void {
                // Run after other bootstrap shutdown hooks have removed their fixtures.
                register_shutdown_function(static function () use ($directory): void {
                    // Tests own cleanup of worker-owned files; never delete their leftovers here.
                    @rmdir($directory);
                });
            });
        }
        $canonical = realpath($directory);

        if ($canonical === false || ! is_dir($canonical)) {
            throw new RuntimeException("The temporary directory [{$directory}] does not exist.");
        }

        if (! putenv("TMPDIR={$canonical}")) {
            throw new RuntimeException('Could not set the TMPDIR test environment variable.');
        }

        $_ENV['TMPDIR'] = $canonical;
        $_SERVER['TMPDIR'] = $canonical;

    }
}
