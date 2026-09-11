<?php

declare(strict_types=1);

use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessCancelledException;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessOutput;
use App\Infrastructure\Processes\ProcessOutputStream;
use App\Infrastructure\Processes\ProtectedInput;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;

it('captures bounded command output and exit state', function (): void {
    $runner = new NativeProcessRunner(maxOutputBytes: 8);

    $result = $runner->run(
        new ProcessInvocation(
            [
                PHP_BINARY,
                '-r',
                'fwrite(STDOUT, "123456789"); fwrite(STDERR, "abcdefghi"); exit(7);',
            ],
            timeout: 5.0,
        ),
    );

    expect($result->exitCode)
        ->toBe(7)
        ->and($result->stdout)
        ->toBe('23456789')
        ->and($result->stderr)
        ->toBe('bcdefghi')
        ->and($result->truncated)
        ->toBeTrue()
        ->and($result->durationMs)
        ->toBeGreaterThanOrEqual(0);
});

it('streams protected input and removes its local file after the process exits', function (): void {
    $sensitiveValue = 'ALPHA=opaque-value';
    $input = ProtectedInput::fromString($sensitiveValue);
    $streamMetadata = stream_get_meta_data($input->stream());
    $path = $streamMetadata['uri'];
    $invocation = new ProcessInvocation(
        [PHP_BINARY, '-r', 'echo hash("sha256", stream_get_contents(STDIN));'],
        timeout: 5.0,
        protectedInput: $input,
    );

    expect($invocation->input)
        ->toBeNull()
        ->and($invocation->protectedInput)
        ->toBeInstanceOf(ProtectedInput::class);

    $result = new NativeProcessRunner()->run($invocation);

    expect($result->succeeded())
        ->toBeTrue()
        ->and($result->stdout)
        ->toBe(hash('sha256', $sensitiveValue))
        ->and(is_string($path))
        ->toBeTrue()
        ->and(file_exists((string) $path))
        ->toBeFalse();
});

it('emits ordered single-stream events at the exact byte boundary', function (int $bytes, array $lengths): void {
    $events = [];
    $runner = new NativeProcessRunner;

    $result = $runner->run(new ProcessInvocation(
        arguments: [
            PHP_BINARY,
            '-r',
            "fwrite(STDOUT, str_repeat('A', {$bytes})); fwrite(STDERR, str_repeat('B', {$bytes}));",
        ],
        timeout: 5.0,
        output: static function (ProcessOutput $output) use (&$events): void {
            $events[] = $output;
        },
    ));

    $stdout = array_values(array_filter(
        $events,
        static fn (ProcessOutput $output): bool => $output->stream === ProcessOutputStream::Stdout,
    ));
    $stderr = array_values(array_filter(
        $events,
        static fn (ProcessOutput $output): bool => $output->stream === ProcessOutputStream::Stderr,
    ));

    expect(array_map(static fn (ProcessOutput $output): int => strlen($output->value), $stdout))
        ->toBe($lengths)
        ->and(array_map(static fn (ProcessOutput $output): int => strlen($output->value), $stderr))
        ->toBe($lengths)
        ->and(implode('', array_map(static fn (ProcessOutput $output): string => $output->value, $stdout)))
        ->toBe(str_repeat('A', $bytes))
        ->and(implode('', array_map(static fn (ProcessOutput $output): string => $output->value, $stderr)))
        ->toBe(str_repeat('B', $bytes))
        ->and($result->truncated)
        ->toBeFalse();
})->with([
    'exact event limit' => [16_384, [16_384]],
    'one byte above event limit' => [16_385, [16_384, 1]],
]);

it('retains independent output tails at the exact result boundary', function (int $bytes, bool $truncated): void {
    $runner = new NativeProcessRunner;

    $result = $runner->run(new ProcessInvocation(
        arguments: [
            PHP_BINARY,
            '-r',
            "fwrite(STDOUT, str_repeat('A', {$bytes})); fwrite(STDERR, str_repeat('B', {$bytes}));",
        ],
        timeout: 5.0,
    ));

    expect($result->stdout)
        ->toBe(str_repeat('A', min($bytes, 65_536)))
        ->and($result->stderr)
        ->toBe(str_repeat('B', min($bytes, 65_536)))
        ->and($result->truncated)
        ->toBe($truncated);
})->with([
    'exact result limit' => [65_536, false],
    'one byte above result limit' => [65_537, true],
]);

it('discards only older bytes from the stream that exceeds the result limit', function (): void {
    $runner = new NativeProcessRunner;

    $result = $runner->run(new ProcessInvocation(
        arguments: [
            PHP_BINARY,
            '-r',
            'fwrite(STDOUT, "O".str_repeat("A", 65536)); fwrite(STDERR, str_repeat("B", 65536));',
        ],
        timeout: 5.0,
    ));

    expect($result->stdout)
        ->toBe(str_repeat('A', 65_536))
        ->and($result->stderr)
        ->toBe(str_repeat('B', 65_536))
        ->and($result->truncated)
        ->toBeTrue();
});

it('terminates its complete process group when cancellation is requested', function (): void {
    $childPid = null;
    $cancel = false;
    $runner = new NativeProcessRunner;

    try {
        $runner->run(new ProcessInvocation(
            arguments: [
                'bash',
                '-c',
                'sleep 30 & child=$!; printf "%s\\n" "$child"; wait "$child"',
            ],
            timeout: 5.0,
            output: static function (ProcessOutput $output) use (&$childPid, &$cancel): void {
                if ($output->stream !== ProcessOutputStream::Stdout) {
                    return;
                }

                $childPid = (int) trim($output->value);
                $cancel = true;
            },
            cancelled: static function () use (&$cancel): bool {
                return $cancel;
            },
        ));

        $this->fail('The cancelled process unexpectedly completed.');
    } catch (ProcessCancelledException) {
        expect($childPid)->toBeInt()->toBeGreaterThan(1);
    }

    $deadline = microtime(true) + 2.0;

    while (is_int($childPid) && @posix_kill($childPid, 0) && microtime(true) < $deadline) {
        usleep(10_000);
    }

    expect(is_int($childPid) && @posix_kill($childPid, 0))->toBeFalse();
});

it('retains a completed command result when final output requests cancellation', function (): void {
    $process = new NativeProcessRunnerCompletedProcess;
    $constructedArguments = null;
    $cancellationChecks = 0;
    $runner = new NativeProcessRunner(processFactory: static function (array $arguments) use (
        $process,
        &$constructedArguments,
    ): SymfonyProcess {
        $constructedArguments = $arguments;

        return $process;
    });

    $result = $runner->run(new ProcessInvocation(
        arguments: ['ignored-command'],
        timeout: 5.0,
        output: static function (ProcessOutput $output) use ($process): void {
            $process->calls[] = "output:{$output->value}";
        },
        cancelled: static function () use (&$cancellationChecks): bool {
            $cancellationChecks++;

            return true;
        },
    ));

    expect($constructedArguments)
        ->toBe(['setsid', '--', 'ignored-command'])
        ->and($process->calls)
        ->toBe([
            'start',
            'is-running:false',
            'stdout:completed',
            'stderr:empty',
            'output:completed',
            'exit-code:0',
        ])
        ->and($cancellationChecks)
        ->toBe(0)
        ->and($result->succeeded())
        ->toBeTrue()
        ->and($result->stdout)
        ->toBe('completed');
});

it('terminates its complete process group when the timeout expires', function (): void {
    $childPid = null;
    $runner = new NativeProcessRunner;

    try {
        $runner->run(new ProcessInvocation(
            arguments: [
                'bash',
                '-c',
                'sleep 30 & child=$!; printf "%s\\n" "$child"; wait "$child"',
            ],
            timeout: 0.2,
            output: static function (ProcessOutput $output) use (&$childPid): void {
                if ($output->stream === ProcessOutputStream::Stdout) {
                    $childPid = (int) trim($output->value);
                }
            },
        ));

        $this->fail('The timed-out process unexpectedly completed.');
    } catch (ProcessTimedOutException) {
        expect($childPid)->toBeInt()->toBeGreaterThan(1);
    }

    $deadline = microtime(true) + 2.0;

    while (is_int($childPid) && @posix_kill($childPid, 0) && microtime(true) < $deadline) {
        usleep(10_000);
    }

    expect(is_int($childPid) && @posix_kill($childPid, 0))->toBeFalse();
});

it('terminates its complete process group when the output sink fails', function (): void {
    $childPid = null;
    $runner = new NativeProcessRunner;

    try {
        $runner->run(new ProcessInvocation(
            arguments: [
                'bash',
                '-c',
                'sleep 30 & child=$!; printf "%s\\n" "$child"; wait "$child"',
            ],
            timeout: 5.0,
            output: static function (ProcessOutput $output) use (&$childPid): void {
                if ($output->stream !== ProcessOutputStream::Stdout) {
                    return;
                }

                $childPid = (int) trim($output->value);

                throw new RuntimeException('Injected output sink failure.');
            },
        ));

        $this->fail('The process with a failed sink unexpectedly completed.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Injected output sink failure.');
    }

    $deadline = microtime(true) + 2.0;

    while (is_int($childPid) && @posix_kill($childPid, 0) && microtime(true) < $deadline) {
        usleep(10_000);
    }

    expect(is_int($childPid) && @posix_kill($childPid, 0))->toBeFalse();
});

final class NativeProcessRunnerCompletedProcess extends SymfonyProcess
{
    /** @var list<string> */
    public array $calls = [];

    private bool $started = false;

    public function __construct()
    {
        parent::__construct(['true']);
    }

    #[Override]
    public function start(?callable $callback = null, array $env = []): void
    {
        $this->started = true;
        $this->calls[] = 'start';
    }

    #[Override]
    public function isRunning(): bool
    {
        if (! $this->started) {
            return false;
        }

        $this->calls[] = 'is-running:false';

        return false;
    }

    #[Override]
    public function getIncrementalOutput(): string
    {
        $this->calls[] = 'stdout:completed';

        return 'completed';
    }

    #[Override]
    public function getIncrementalErrorOutput(): string
    {
        $this->calls[] = 'stderr:empty';

        return '';
    }

    #[Override]
    public function getExitCode(): ?int
    {
        $this->calls[] = 'exit-code:0';

        return 0;
    }
}
