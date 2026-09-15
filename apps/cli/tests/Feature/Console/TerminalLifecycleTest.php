<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

describe('owned renderer lifecycle', function (): void {
    it('keeps the callback in the parent and restores signal state for actual renderer processes', function (string $case, int $status): void {
        $trace = tempnam(sys_get_temp_dir(), 'orbit-ux-trace-');

        try {
            $process = new Process([PHP_BINARY, __DIR__.'/../../Fixtures/Console/runtime-fixture.php', $case, '--renderer-fixture'],
                env: ['ORBIT_UX_TRACE' => $trace]);
            $process->setTimeout(10);
            $process->run();
            $events = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($trace, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
            $start = $events[0];
            $finish = $events[array_key_last($events)];
            $callbacks = array_values(array_filter($events, static fn (array $event): bool => $event['event'] === 'callback'));

            expect($process->getExitCode())->toBe($status)
                ->and($callbacks)->toHaveCount(1)
                ->and($callbacks[0]['pid'])->toBe($start['pid'])
                ->and($finish['async_restored'])->toBeTrue()
                ->and($finish['handler_restored'])->toBeTrue()
                ->and($process->getOutput())->toContain("\e[?25l", "\e[?25h")
                ->not->toContain('Private fixture detail');
        } finally {
            unlink($trace);
        }
    })->with([
        'progress success' => ['progress', 0],
        'progress exception' => ['failure', 7],
        'short spinner' => ['short-spinner', 0],
        'spinner exception' => ['spinner-failure', 7],
        'nested renderer' => ['nested', 0],
        'invalid final payload' => ['invalid-settled', 7],
        'callback and final payload failure' => ['invalid-settled-exception', 7],
        'nested spinners' => ['nested-spinners', 0],
        'nested progress' => ['nested-progress', 0],
        'nested startup payload failure' => ['nested-invalid-start', 7],
        'signal during failure teardown' => ['failure-teardown-signal', 7],
    ]);

    it('leaves machine and plain output free of cursor control and repeated frames', function (): void {
        $fixture = __DIR__.'/../../Fixtures/Console/runtime-fixture.php';
        $machine = new Process([PHP_BINARY, $fixture, 'short-spinner', '--json', '--renderer-fixture']);
        $machine->run();
        expect($machine->getExitCode())->toBe(0)
            ->and(json_decode($machine->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe(['fixture_status' => 0, 'callbacks' => 1])
            ->and($machine->getErrorOutput())->toBe('');

        $plain = new Process([PHP_BINARY, $fixture, 'short-spinner', '--plain']);
        $plain->run();
        expect($plain->getExitCode())->toBe(0)
            ->and($plain->getOutput())->toBe("Waiting for response...\nWait finished.\n")
            ->and($plain->getErrorOutput())->toBe('');
    });

    it('refuses work when renderer startup or IPC fails and reaps the exact child', function (string $fault): void {
        $trace = tempnam(sys_get_temp_dir(), 'orbit-ux-fault-');

        try {
            $process = new Process([PHP_BINARY, __DIR__.'/../../Fixtures/Console/runtime-fixture.php', 'spinner', '--renderer-fixture'],
                env: ['ORBIT_UX_TRACE' => $trace, 'ORBIT_UX_FAULT' => $fault]);
            $process->setTimeout(8);
            $process->run();
            $events = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                file($trace, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
            $finish = $events[array_key_last($events)];

            expect($process->getExitCode())->toBe(7)
                ->and($finish['callbacks'])->toBe($fault === 'renderer-exit' ? 1 : 0)
                ->and($finish['async_restored'])->toBeTrue()
                ->and($finish['handler_restored'])->toBeTrue()
                ->and($process->getOutput())->not->toContain('Wait finished.');

            foreach ($events as $event) {
                if ($event['event'] === 'renderer' && function_exists('posix_kill')) {
                    expect(posix_kill($event['pid'], 0))->toBeFalse();
                }
            }
        } finally {
            unlink($trace);
        }
    })->with(['startup', 'ipc', 'renderer-exit']);

    it('preserves abrupt callback status without inventing spinner completion', function (): void {
        $process = new Process([PHP_BINARY, __DIR__.'/../../Fixtures/Console/runtime-fixture.php', 'spinner-exit', '--renderer-fixture']);
        $process->setTimeout(8);
        $process->run();

        expect($process->getExitCode())->toBe(23)
            ->and($process->getOutput())->toContain('Wait interrupted.', "\e[?25h")
            ->not->toContain('Wait finished.');
    });
});
