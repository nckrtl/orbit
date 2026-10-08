<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/**
 * Isolate each test process under short, trusted system temporary ancestry.
 *
 * Runner-supplied TMPDIR may be inside a group-writable checkout, or too long for Unix sockets.
 * Canonical /tmp also avoids macOS's /var symlink. Each parallel worker creates its own private
 * root so native access preparation cannot change another worker's recorded ancestor protections.
 * Mode 0711 permits Caddy traversal without directory listing or writes by other accounts.
 * Run before PHP caches sys_get_temp_dir(); shell and Python children inherit the same root.
 */
final class TestTemporaryDirectory
{
    public static function bootstrap(): void
    {
        $system = realpath('/tmp');
        if ($system === false || ! is_dir($system)) {
            throw new RuntimeException('The system temporary directory does not exist.');
        }
        $configured = getenv('TMPDIR');
        $token = getenv('TEST_TOKEN') ?: '';
        $inheritedToken = getenv('ORBIT_TEST_TEMP_TOKEN');
        if (is_string($configured) && preg_match('#^'.preg_quote($system, '#').'/ot-[a-f0-9]{12}$#D', $configured) === 1
            && is_dir($configured) && realpath($configured) === $configured && fileowner($configured) === posix_geteuid()
            && (fileperms($configured) & 0022) === 0 && $inheritedToken === $token) {
            // Probe subprocesses must keep the parent's explicitly allocated disposable databases valid.
            return;
        }
        $directory = $system.'/ot-'.bin2hex(random_bytes(6));
        if (! mkdir($directory, 0711) || ! chmod($directory, 0711)) {
            throw new RuntimeException('Could not create a private test temporary directory.');
        }
        register_shutdown_function(static function () use ($directory): void {
            // Remove only this process's newly allocated fixture scope, never a supplied TMPDIR.
            new Filesystem()->deleteDirectory($directory);
        });
        if (! putenv("TMPDIR={$directory}")) {
            throw new RuntimeException('Could not set the TMPDIR test environment variable.');
        }
        putenv("ORBIT_TEST_TEMP_TOKEN={$token}");
        $_ENV['ORBIT_TEST_TEMP_TOKEN'] = $token;
        $_SERVER['ORBIT_TEST_TEMP_TOKEN'] = $token;
        $_ENV['TMPDIR'] = $directory;
        $_SERVER['TMPDIR'] = $directory;
    }
}
