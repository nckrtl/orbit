<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Provides a writable directory on a different filesystem from sys_get_temp_dir().
 *
 * Tests that prove cross-filesystem moves need two filesystems. Linux has the /dev/shm tmpfs. macOS has no
 * /dev/shm and keeps its temporary directories on one APFS volume, so this class attaches a small
 * case-sensitive RAM disk the first time a test asks, without root, and ejects it when the process ends.
 * Disks that a killed run left behind are ejected on the next run.
 */
final class SeparateFilesystem
{
    private const string VolumePrefix = 'orbit-gateway-testing-';

    private const int RamDiskSectors = 262_144;

    private const int NoSuchProcess = 3;

    private static ?string $root = null;

    /** Returns a new, not yet created path on the separate filesystem. */
    public static function path(string $name): string
    {
        return self::root().DIRECTORY_SEPARATOR.$name;
    }

    private static function root(): string
    {
        if (self::$root !== null) {
            return self::$root;
        }

        if (self::separate('/dev/shm')) {
            return self::$root = '/dev/shm';
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            return self::$root = self::attachRamDisk();
        }

        throw new RuntimeException(
            'This test needs a writable directory on a different filesystem from '.sys_get_temp_dir()
            .'. Mount a tmpfs at /dev/shm and run the tests again.',
        );
    }

    private static function separate(string $directory): bool
    {
        $candidate = @stat($directory);
        $temporary = @stat(sys_get_temp_dir());

        return is_array($candidate)
            && is_array($temporary)
            && is_dir($directory)
            && is_writable($directory)
            && $candidate['dev'] !== $temporary['dev'];
    }

    private static function attachRamDisk(): string
    {
        self::ejectAbandonedRamDisks();

        $attach = self::run(['hdiutil', 'attach', '-nomount', 'ram://'.self::RamDiskSectors]);
        $device = trim($attach);
        $volume = self::VolumePrefix.getmypid().'-'.bin2hex(random_bytes(4));

        if (preg_match('#\A/dev/disk\d+\z#', $device) !== 1) {
            throw new RuntimeException("Could not attach a RAM disk for the separate test filesystem: {$attach}");
        }

        $owner = getmypid();
        register_shutdown_function(static function () use ($device, $owner): void {
            // A forked child shares this handler; only the process that attached the disk ejects it.
            if (getmypid() === $owner) {
                new Process(['hdiutil', 'detach', $device, '-force'])->run();
            }
        });

        self::run(['diskutil', 'erasevolume', 'Case-sensitive HFS+', $volume, $device]);
        $root = '/Volumes/'.$volume;

        if (! self::separate($root)) {
            throw new RuntimeException("The RAM disk [{$root}] is not a separate writable filesystem.");
        }

        return $root;
    }

    /** Ejects RAM disks whose test process no longer runs, such as those of a run ended by SIGKILL. */
    private static function ejectAbandonedRamDisks(): void
    {
        foreach (glob('/Volumes/'.self::VolumePrefix.'*', GLOB_ONLYDIR) ?: [] as $volume) {
            if (
                preg_match('/\A'.preg_quote(self::VolumePrefix, '/').'(\d+)-[0-9a-f]{8}\z/', basename($volume), $matches) !== 1
                || fileowner($volume) !== posix_geteuid()
                || posix_kill((int) $matches[1], 0)
                || posix_get_last_error() !== self::NoSuchProcess
            ) {
                continue;
            }

            new Process(['hdiutil', 'detach', $volume, '-force'])->run();
        }
    }

    /** @param list<string> $command */
    private static function run(array $command): string
    {
        $process = new Process($command);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'Could not prepare the separate test filesystem with [%s]: %s',
                implode(' ', $command),
                trim($process->getErrorOutput()),
            ));
        }

        return $process->getOutput();
    }
}
