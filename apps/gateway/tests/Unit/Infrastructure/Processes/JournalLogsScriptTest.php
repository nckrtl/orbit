<?php

declare(strict_types=1);

use App\Domain\Logs\LogReadLimit;
use App\Infrastructure\Processes\RemoteProcessRuntimeManager;
use Symfony\Component\Process\Process;

/**
 * Runs the one-shot journal read against a stand-in `journalctl` that checks its arguments and runs
 * `$body`, and returns what the Node would send back.
 */
function readJournal(string $body): string
{
    $bin = sys_get_temp_dir().'/orbit-journal-logs-'.bin2hex(random_bytes(4));
    mkdir($bin);
    file_put_contents($bin.'/journalctl', <<<SH
        #!/bin/sh
        test "\$*" = "--unit orbit-process-7-web.service --lines 1000 --reverse --no-pager --output short-iso --utc" || exit 9
        {$body}
        SH);
    chmod($bin.'/journalctl', 0755);

    try {
        $process = new Process(
            ['sh', '-c', RemoteProcessRuntimeManager::JournalLogsScript, 'orbit-process-logs', 'orbit-process-7-web.service', '1000', (string) LogReadLimit::Bytes],
            env: ['PATH' => $bin.':/usr/bin:/bin'],
            timeout: 60,
        );
        $process->mustRun();

        return $process->getOutput();
    } finally {
        array_map(unlink(...), glob($bin.'/*') ?: []);
        rmdir($bin);
    }
}

it('stops reading the journal after the byte limit and returns whole entries in time order', function (): void {
    // Newest entry first, then endless entries, as a journal with more than 4 MiB of lines.
    $output = readJournal(<<<'SH'
        echo "2026-09-25T10:00:03+00:00 host web[7]: newest"
        line="2026-09-25T10:00:02+00:00 host web[7]: $(printf '%0100d' 0)"
        while :; do echo "$line" || exit 0; done
        SH);

    $lines = explode("\n", LogReadLimit::journalInTimeOrder($output));

    expect(strlen($output))->toBe(LogReadLimit::Bytes)
        ->and(end($lines))->toBe('')
        ->and($lines[count($lines) - 2])->toBe('2026-09-25T10:00:03+00:00 host web[7]: newest')
        ->and(count($lines))->toBeGreaterThan(29_000);
});

it('cuts a huge entry at 256 KiB and keeps the entries around it', function (): void {
    // A 4 MB message of a million short lines, between two ordinary entries.
    $output = readJournal(<<<'SH'
        echo "2026-09-25T10:00:03+00:00 host web[7]: newer"
        awk 'BEGIN { print "2026-09-25T10:00:02+00:00 host web[7]: huge 0"; for (i = 1; i < 1000000; i++) print "                                       " i }'
        echo "2026-09-25T10:00:01+00:00 host web[7]: older"
        SH);

    $lines = explode("\n", LogReadLimit::journalInTimeOrder($output));
    $indent = str_repeat(' ', 39);

    expect(strlen($output))->toBeLessThan(270_000)
        ->and($lines[0])->toBe('2026-09-25T10:00:01+00:00 host web[7]: older')
        ->and($lines[1])->toBe('2026-09-25T10:00:02+00:00 host web[7]: huge 0')
        ->and($lines[2])->toBe($indent.'1')
        ->and($lines[count($lines) - 3])->toBe($indent.'[orbit] message cut at 256 KiB')
        ->and($lines[count($lines) - 2])->toBe('2026-09-25T10:00:03+00:00 host web[7]: newer')
        ->and(strlen(implode('', array_slice($lines, 1, -3))))->toBeLessThanOrEqual(262_144);
});

it('reads a message over 256 KiB as the same lines the Node agent streams', function (): void {
    // The agent's lines for this 400 KB message; its test `a_message_over_256_kib_gives_the_lines_of_the_one_shot_read` reads the same file.
    $agent = file_get_contents(dirname(__DIR__, 5).'/agent/tests/journalctl_cut_message.txt');
    $prefix = '2026-09-25T19:05:31+00:00 beast lvtbig[2773152]: ';
    $indent = str_repeat(' ', strlen($prefix));
    $full = '';

    foreach (range(0, 399) as $i) {
        $full .= ($i === 0 ? $prefix : $indent).sprintf('part %03d ', $i).str_repeat('q', 1000)."\n";
    }

    $file = tempnam(sys_get_temp_dir(), 'orbit-journal-message-');
    file_put_contents($file, "2026-09-25T19:05:32+00:00 beast lvtbig[2773152]: newer\n{$full}2026-09-25T19:05:30+00:00 beast lvtbig[2773152]: older\n");

    try {
        $output = readJournal("cat '{$file}'");
    } finally {
        unlink($file);
    }

    expect(LogReadLimit::journalInTimeOrder($output))->toBe(
        "2026-09-25T19:05:30+00:00 beast lvtbig[2773152]: older\n{$agent}2026-09-25T19:05:32+00:00 beast lvtbig[2773152]: newer\n",
    )->and(substr_count($agent, "\n"))->toBe(248);
});

it('reads a line over 256 KiB as the same lines the Node agent streams', function (): void {
    // The agent's lines for this 300 KB line; its test `a_line_over_256_kib_gives_the_lines_of_the_one_shot_read` reads the same file.
    $agent = file_get_contents(dirname(__DIR__, 5).'/agent/tests/journalctl_cut_line.txt');
    $file = tempnam(sys_get_temp_dir(), 'orbit-journal-line-');
    file_put_contents($file, "2026-09-25T10:15:03+00:00 beast lvtbig[7]: newer\n2026-09-25T10:15:02+00:00 beast lvtbig[7]: a".str_repeat('é', 150_000)."\n2026-09-25T10:15:01+00:00 beast lvtbig[7]: older\n");

    try {
        $output = readJournal("cat '{$file}'");
    } finally {
        unlink($file);
    }

    expect(LogReadLimit::journalInTimeOrder($output))->toBe(
        "2026-09-25T10:15:01+00:00 beast lvtbig[7]: older\n{$agent}2026-09-25T10:15:03+00:00 beast lvtbig[7]: newer\n",
    )->and(substr_count($agent, "\n"))->toBe(2);
});
