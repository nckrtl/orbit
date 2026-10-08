<?php

declare(strict_types=1);

use App\Infrastructure\Nodes\NodeUpdateLock;

/**
 * Runs the update lock's acquire script for real, with `systemd-run` and `systemctl` replaced by stubs that
 * start the unit's command as a process group and report it active while it runs.
 */
function updateLockSandbox(): string
{
    $dir = sys_get_temp_dir().'/orbit-update-lock-'.bin2hex(random_bytes(4));
    mkdir($dir.'/bin', 0755, true);
    file_put_contents($dir.'/bin/systemd-run', <<<'SH'
        #!/bin/bash
        unit=
        while [ $# -gt 0 ]; do
          case "$1" in
            --unit=*) unit=${1#--unit=}; shift ;;
            --*) shift ;;
            *) break ;;
          esac
        done
        setsid "$@" >/dev/null 2>&1 < /dev/null &
        echo $! > "$STUB_DIR/$unit.pid"
        SH);
    file_put_contents($dir.'/bin/systemctl', <<<'SH'
        #!/bin/bash
        unit=${@: -1}
        pid=$(cat "$STUB_DIR/$unit.pid" 2>/dev/null || true)
        case "$1" in
          show) if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then echo active; else echo inactive; fi ;;
          stop) [ -n "$pid" ] && kill -- "-$pid" 2>/dev/null; true ;;
        esac
        SH);
    chmod($dir.'/bin/systemd-run', 0755);
    chmod($dir.'/bin/systemctl', 0755);

    return $dir;
}

/** @return array{int, float} The exit code and the seconds it took. */
function updateLockAcquire(string $dir, string $unit, int $wait): array
{
    $started = microtime(true);
    $process = proc_open(
        ['bash', '-seu', '--', $unit, $dir.'/update.lock', (string) $wait, '60', $dir],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['PATH' => $dir.'/bin:'.getenv('PATH'), 'STUB_DIR' => $dir],
    );
    fwrite($pipes[0], NodeUpdateLock::AcquireScript);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);

    return [proc_close($process), microtime(true) - $started];
}

function updateLockFree(string $dir): bool
{
    exec('flock -n '.escapeshellarg($dir.'/update.lock').' true', $output, $status);

    return $status === 0;
}

describe('Node update lock script', function (): void {
    it('holds the lock until its unit stops', function (): void {
        $dir = updateLockSandbox();

        [$status] = updateLockAcquire($dir, 'orbit-update-lock-test', 5);

        expect($status)->toBe(0)
            ->and(is_file($dir.'/orbit-update-lock-test.held'))->toBeTrue()
            ->and(updateLockFree($dir))->toBeFalse();

        exec('STUB_DIR='.escapeshellarg($dir).' '.escapeshellarg($dir.'/bin/systemctl').' stop orbit-update-lock-test');
        usleep(300_000);

        expect(updateLockFree($dir))->toBeTrue();
        exec('rm -rf '.escapeshellarg($dir));
    });

    it('gives up after its wait while another process holds the lock', function (): void {
        $dir = updateLockSandbox();
        touch($dir.'/update.lock');
        $holder = proc_open(['flock', $dir.'/update.lock', 'sleep', '20'], [], $pipes);
        usleep(300_000);

        [$status, $seconds] = updateLockAcquire($dir, 'orbit-update-lock-busy', 1);

        expect($status)->toBe(3)
            ->and(is_file($dir.'/orbit-update-lock-busy.held'))->toBeFalse()
            ->and($seconds)->toBeLessThan(10);

        proc_terminate($holder);
        proc_close($holder);
        exec('rm -rf '.escapeshellarg($dir));
    });
});
