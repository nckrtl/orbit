<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;

/**
 * Swaps two paths on one file system in a single step, with `renameat2(RENAME_EXCHANGE)`. A
 * directory cannot be replaced by a link with one plain rename, so adoption swaps the checkout
 * directory with a ready link instead: every lookup sees either the old directory or the link,
 * never nothing. PHP has no `renameat2`, so the swap runs through Python's `ctypes`. When the
 * kernel, the file system, or Python cannot do it, the caller falls back to two renames.
 */
final readonly class GatewayReleaseExchange
{
    private const string Program = <<<'PYTHON'
        import ctypes, os, sys
        libc = ctypes.CDLL(None, use_errno=True)
        renameat2 = getattr(libc, "renameat2", None)
        if renameat2 is None:
            sys.exit(3)
        AT_FDCWD, RENAME_EXCHANGE = -100, 2
        if renameat2(AT_FDCWD, os.fsencode(sys.argv[1]), AT_FDCWD, os.fsencode(sys.argv[2]), RENAME_EXCHANGE) != 0:
            error = ctypes.get_errno()
            sys.stderr.write(os.strerror(error))
            sys.exit(3 if error in (22, 38, 95) else 1)
        PYTHON;

    private const string MoveProgram = <<<'PYTHON'
        import os, sys, time
        source, target = sys.argv[1], sys.argv[2]
        started = time.perf_counter_ns()
        os.rename(source, target)
        try:
            os.symlink(target, source)
        except OSError:
            os.rename(target, source)
            raise
        print((time.perf_counter_ns() - started) // 1000)
        PYTHON;

    public function __construct(
        private ProcessRunner $processes,
        private string $python = 'python3',
    ) {}

    /**
     * Swaps the two paths. Returns false, without changing anything, when an atomic swap is not
     * available here.
     *
     * @throws GatewayReleaseException when the swap is available and fails
     */
    public function swap(string $first, string $second): bool
    {
        $result = $this->processes->run(new ProcessInvocation(
            arguments: [$this->python, '-c', self::Program, $first, $second],
            timeout: 30.0,
        ));

        if ($result->succeeded()) {
            return true;
        }

        // 3: no renameat2 or no exchange support here. 126 and 127: no Python to run it.
        if (in_array($result->exitCode, [3, 126, 127], true)) {
            return false;
        }

        throw new GatewayReleaseException(
            step: 'switch',
            errorCode: 'gateway.release_switch_failed',
            message: "[{$first}] and [{$second}] could not be swapped: ".trim($result->stderr),
            status: 500,
            result: $result,
        );
    }

    /**
     * Moves a directory to another path on the same file system and leaves a link to it behind. A
     * directory cannot be in two places, so the old path is missing between the rename and the link.
     * Both run back to back in one process, to keep that gap to microseconds.
     *
     * @return int the gap in microseconds
     *
     * @throws GatewayReleaseException
     */
    public function moveAndLink(string $source, string $target): int
    {
        $result = $this->processes->run(new ProcessInvocation(
            arguments: [$this->python, '-c', self::MoveProgram, $source, $target],
            timeout: 30.0,
        ));

        if ($result->succeeded()) {
            return (int) trim($result->stdout);
        }

        if (in_array($result->exitCode, [126, 127], true)) {
            $started = hrtime(true);
            $moved = @rename($source, $target);
            $linked = $moved && @symlink($target, $source);
            $gap = intdiv(hrtime(true) - $started, 1_000);

            if ($linked) {
                return $gap;
            }

            if ($moved) {
                @rename($target, $source);
            }
        }

        throw new GatewayReleaseException(
            step: 'adopt',
            errorCode: 'gateway.release_adopt_storage_failed',
            message: "[{$source}] cannot be moved to [{$target}] and linked back: ".trim($result->stderr),
            status: 500,
            result: $result,
        );
    }
}
