<?php

declare(strict_types=1);

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessCancelledException;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessOutput;
use App\Infrastructure\Processes\ProcessOutputStream;
use App\Infrastructure\Processes\ProtectedInput;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * Run `php NativeProcessRunnerTest.php negative-controls` for the bounded corruption check.
 * Run `php NativeProcessRunnerTest.php observe-native` for the strict 50-attempt native observation.
 */
$runningVerification = realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__;

if ($runningVerification) {
    require dirname(__DIR__, 4).'/vendor/autoload.php';
}

final class ProcessOutputControlledProcess extends SymfonyProcess
{
    private int $iteration = 0;

    private bool $started = false;

    /**
     * @param  non-empty-list<string>  $stdoutReads
     * @param  non-empty-list<string>  $stderrReads
     */
    public function __construct(
        private readonly array $stdoutReads,
        private readonly array $stderrReads,
    ) {
        parent::__construct(['true']);
    }

    #[Override]
    public function start(?callable $callback = null, array $env = []): void
    {
        $this->started = true;
    }

    #[Override]
    public function isRunning(): bool
    {
        return $this->started && $this->iteration < count($this->stdoutReads) - 1;
    }

    #[Override]
    public function getIncrementalOutput(): string
    {
        return $this->stdoutReads[$this->iteration];
    }

    #[Override]
    public function getIncrementalErrorOutput(): string
    {
        $output = $this->stderrReads[$this->iteration];
        $this->iteration++;

        return $output;
    }

    #[Override]
    public function getExitCode(): ?int
    {
        return 0;
    }
}

if ($runningVerification) {

    exit(match ($argv[1] ?? null) {
        'negative-controls' => runProcessOutputNegativeControls(),
        'observe-native' => runNativeProcessOutputObservation(),
        default => throw new InvalidArgumentException('Expected negative-controls or observe-native.'),
    });
}

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

it('emits bounded ordered stream events at the exact byte boundary', function (int $bytes): void {
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

    ProcessOutputContract::assertMatches(
        $events,
        stdout: str_repeat('A', $bytes),
        stderr: str_repeat('B', $bytes),
    );

    expect($result->succeeded())
        ->toBeTrue()
        ->and($result->stdout)
        ->toBe(str_repeat('A', $bytes))
        ->and($result->stderr)
        ->toBe(str_repeat('B', $bytes))
        ->and($result->truncated)
        ->toBeFalse();
})->with([
    'exact event limit' => [16_384],
    'one byte above event limit' => [16_385],
]);

it('splits controlled process reads without losing order or stream attribution', function (): void {
    $observation = ProcessOutputFixture::observeControlledRunner();

    ProcessOutputContract::assertMatches(
        $observation->events,
        $observation->expectedStdout,
        $observation->expectedStderr,
    );

    expect($observation->result->succeeded())
        ->toBeTrue()
        ->and($observation->result->stdout)
        ->toBe($observation->expectedStdout)
        ->and($observation->result->stderr)
        ->toBe($observation->expectedStderr)
        ->and($observation->result->truncated)
        ->toBeFalse();
});

it('rejects corrupted controlled process output events', function (string $corruption, string $message): void {
    $observation = ProcessOutputFixture::observeControlledRunner();
    $events = ProcessOutputFixture::corrupt($observation->events, $corruption);

    expect(fn () => ProcessOutputContract::assertMatches(
        $events,
        $observation->expectedStdout,
        $observation->expectedStderr,
    ))->toThrow(UnexpectedValueException::class, $message);
})->with(ProcessOutputFixture::corruptionCases());

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

final class ProcessOutputContract
{
    public const int MaxEventBytes = 16_384;

    /** @param list<ProcessOutput> $events */
    public static function assertMatches(array $events, string $stdout, string $stderr): void
    {
        $actual = [
            ProcessOutputStream::Stdout->value => '',
            ProcessOutputStream::Stderr->value => '',
        ];

        foreach ($events as $event) {
            if ($event->value === '') {
                throw new UnexpectedValueException('Output events must not be empty.');
            }

            if (strlen($event->value) > self::MaxEventBytes) {
                throw new UnexpectedValueException('Output events must not exceed 16,384 bytes.');
            }

            $actual[$event->stream->value] .= $event->value;
        }

        if ($actual[ProcessOutputStream::Stdout->value] !== $stdout) {
            throw new UnexpectedValueException('Stdout bytes differ from the expected ordered stream.');
        }

        if ($actual[ProcessOutputStream::Stderr->value] !== $stderr) {
            throw new UnexpectedValueException('Stderr bytes differ from the expected ordered stream.');
        }
    }
}

final readonly class ProcessOutputObservation
{
    /** @param list<ProcessOutput> $events */
    public function __construct(
        public array $events,
        public string $expectedStdout,
        public string $expectedStderr,
        public CommandResult $result,
    ) {}
}

final class ProcessOutputFixture
{
    public const string LostByte = 'lost-byte';

    public const string ReorderedByte = 'reordered-byte';

    public const string WrongStream = 'wrong-stream';

    public const string OversizedEvent = 'oversized-event';

    public static function observeControlledRunner(): ProcessOutputObservation
    {
        $stdoutReads = [
            self::orderedBytes('stdout', ProcessOutputContract::MaxEventBytes + 1),
            '|stdout-tail|',
        ];
        $stderrReads = [
            '|stderr-head|',
            self::orderedBytes('stderr', ProcessOutputContract::MaxEventBytes + 1),
        ];
        $events = [];
        $process = new ProcessOutputControlledProcess($stdoutReads, $stderrReads);
        $runner = new NativeProcessRunner(
            processFactory: static fn (array $arguments): SymfonyProcess => $process,
        );

        $result = $runner->run(new ProcessInvocation(
            arguments: ['controlled-process'],
            timeout: 5.0,
            output: static function (ProcessOutput $output) use (&$events): void {
                $events[] = $output;
            },
        ));

        return new ProcessOutputObservation(
            events: $events,
            expectedStdout: implode('', $stdoutReads),
            expectedStderr: implode('', $stderrReads),
            result: $result,
        );
    }

    /** @return array<string, array{string, string}> */
    public static function corruptionCases(): array
    {
        return [
            'lost byte' => [self::LostByte, 'Stdout bytes differ from the expected ordered stream.'],
            'reordered byte' => [self::ReorderedByte, 'Stdout bytes differ from the expected ordered stream.'],
            'wrong stream attribution' => [self::WrongStream, 'Stdout bytes differ from the expected ordered stream.'],
            'oversized event' => [self::OversizedEvent, 'Output events must not exceed 16,384 bytes.'],
        ];
    }

    /**
     * @param  list<ProcessOutput>  $events
     * @return list<ProcessOutput>
     */
    public static function corrupt(array $events, string $corruption): array
    {
        $index = self::firstStdoutEvent($events);
        $event = $events[$index];

        $events[$index] = match ($corruption) {
            self::LostByte => new ProcessOutput($event->stream, substr($event->value, 1)),
            self::ReorderedByte => new ProcessOutput(
                $event->stream,
                $event->value[1].$event->value[0].substr($event->value, 2),
            ),
            self::WrongStream => new ProcessOutput(ProcessOutputStream::Stderr, $event->value),
            self::OversizedEvent => new ProcessOutput(
                $event->stream,
                $event->value.str_repeat('!', ProcessOutputContract::MaxEventBytes + 1 - strlen($event->value)),
            ),
            default => throw new UnexpectedValueException("Unknown output corruption: {$corruption}"),
        };

        return $events;
    }

    private static function orderedBytes(string $prefix, int $length): string
    {
        $alphabet = "{$prefix}:0123456789abcdefghijklmnopqrstuvwxyz|";

        return substr(str_repeat($alphabet, (int) ceil($length / strlen($alphabet))), 0, $length);
    }

    /** @param list<ProcessOutput> $events */
    private static function firstStdoutEvent(array $events): int
    {
        foreach ($events as $index => $event) {
            if ($event->stream === ProcessOutputStream::Stdout && strlen($event->value) >= 2) {
                return $index;
            }
        }

        throw new UnexpectedValueException('The controlled observation has no usable stdout event.');
    }
}

function runProcessOutputNegativeControls(): int
{
    $observation = ProcessOutputFixture::observeControlledRunner();
    ProcessOutputContract::assertMatches(
        $observation->events,
        $observation->expectedStdout,
        $observation->expectedStderr,
    );
    $rejected = 0;

    foreach (ProcessOutputFixture::corruptionCases() as $name => [$corruption, $expectedMessage]) {
        $events = ProcessOutputFixture::corrupt($observation->events, $corruption);

        try {
            ProcessOutputContract::assertMatches(
                $events,
                $observation->expectedStdout,
                $observation->expectedStderr,
            );
        } catch (UnexpectedValueException $exception) {
            if ($exception->getMessage() !== $expectedMessage) {
                throw $exception;
            }

            $rejected++;
            echo json_encode([
                'control' => $name,
                'rejected' => true,
                'reason' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR), PHP_EOL;

            continue;
        }

        throw new RuntimeException("Negative control was accepted: {$name}");
    }

    echo json_encode([
        'baseline_valid' => true,
        'controls' => count(ProcessOutputFixture::corruptionCases()),
        'rejected' => $rejected,
    ], JSON_THROW_ON_ERROR), PHP_EOL;

    return 0;
}

function runNativeProcessOutputObservation(): int
{
    $attempts = 50;
    $validAttempts = 0;
    $expected = [
        ProcessOutputStream::Stdout->value => str_repeat('A', 16_385),
        ProcessOutputStream::Stderr->value => str_repeat('B', 16_385),
    ];

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $events = [];

        try {
            $result = new NativeProcessRunner()->run(new ProcessInvocation(
                arguments: [
                    PHP_BINARY,
                    '-r',
                    'fwrite(STDOUT, str_repeat("A", 16385)); fwrite(STDERR, str_repeat("B", 16385));',
                ],
                timeout: 5.0,
                output: static function (ProcessOutput $output) use (&$events): void {
                    $events[] = $output;
                },
            ));
            $streams = [];

            foreach (ProcessOutputStream::cases() as $stream) {
                $values = array_map(
                    static fn (ProcessOutput $event): string => $event->value,
                    array_values(array_filter(
                        $events,
                        static fn (ProcessOutput $event): bool => $event->stream === $stream,
                    )),
                );
                $actual = implode('', $values);
                $streams[$stream->value] = [
                    'partitions' => array_map(strlen(...), $values),
                    'bytes' => strlen($actual),
                    'sha256' => hash('sha256', $actual),
                    'expected_sha256' => hash('sha256', $expected[$stream->value]),
                    'bytes_preserved' => $actual === $expected[$stream->value],
                    'events_nonempty' => ! in_array('', $values, true),
                    'events_bounded' => array_all(
                        $values,
                        static fn (string $value): bool => strlen($value) <= ProcessOutputContract::MaxEventBytes,
                    ),
                ];
            }

            $contractError = null;

            try {
                ProcessOutputContract::assertMatches(
                    $events,
                    $expected[ProcessOutputStream::Stdout->value],
                    $expected[ProcessOutputStream::Stderr->value],
                );
            } catch (UnexpectedValueException $exception) {
                $contractError = $exception->getMessage();
            }

            $valid = $contractError === null
                && $result->exitCode === 0
                && $result->stdout === $expected[ProcessOutputStream::Stdout->value]
                && $result->stderr === $expected[ProcessOutputStream::Stderr->value]
                && ! $result->truncated;
            $validAttempts += (int) $valid;

            echo json_encode([
                'attempt' => $attempt,
                'valid' => $valid,
                'exit_code' => $result->exitCode,
                'truncated' => $result->truncated,
                'contract_error' => $contractError,
                'streams' => $streams,
            ], JSON_THROW_ON_ERROR), PHP_EOL;
        } catch (Throwable $exception) {
            echo json_encode([
                'attempt' => $attempt,
                'valid' => false,
                'error' => $exception::class,
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR), PHP_EOL;
        }
    }

    $valid = $validAttempts === $attempts;

    echo json_encode([
        'attempts' => $attempts,
        'valid_attempts' => $validAttempts,
        'failures' => $attempts - $validAttempts,
        'valid' => $valid,
    ], JSON_THROW_ON_ERROR), PHP_EOL;

    return $valid ? 0 : 1;
}
