<?php

declare(strict_types=1);

use App\Domain\Logs\LogReadLimit;
use App\Infrastructure\Processes\RemoteProcessRuntimeManager;
use Symfony\Component\Process\Process;

it('stops reading the journal after the byte limit and returns whole entries in time order', function (): void {
    $bin = sys_get_temp_dir().'/orbit-journal-logs-'.bin2hex(random_bytes(4));
    mkdir($bin);
    // A stand-in for journalctl: newest entry first, then one entry of endless short lines.
    file_put_contents($bin.'/journalctl', <<<'SH'
        #!/bin/sh
        test "$*" = "--unit orbit-process-7-web.service --lines 1000 --reverse --no-pager --output short-iso --utc" || exit 9
        echo "2026-09-25T10:00:03+00:00 host web[7]: newest"
        echo "2026-09-25T10:00:02+00:00 host web[7]: before"
        echo "2026-09-25T10:00:01+00:00 host web[7]: huge 0"
        i=1
        while :; do echo "                                           $i" || exit 0; i=$((i+1)); done
        SH);
    chmod($bin.'/journalctl', 0755);

    $process = new Process(
        ['sh', '-c', RemoteProcessRuntimeManager::JournalLogsScript, 'orbit-process-logs', 'orbit-process-7-web.service', '1000', (string) LogReadLimit::Bytes],
        env: ['PATH' => $bin.':/usr/bin:/bin'],
        timeout: 20,
    );
    $process->mustRun();

    expect(strlen($process->getOutput()))->toBe(LogReadLimit::Bytes)
        ->and(LogReadLimit::journalInTimeOrder($process->getOutput()))->toBe(
            "2026-09-25T10:00:02+00:00 host web[7]: before\n2026-09-25T10:00:03+00:00 host web[7]: newest\n",
        );

    unlink($bin.'/journalctl');
    rmdir($bin);
});
