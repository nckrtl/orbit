<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Throwable;

final readonly class NativeProcessRunner implements ProcessRunner
{
    private const int MaxEventBytes = 16_384;

    public function __construct(
        private int $maxOutputBytes = 65_536,
        private ?CommandDeadline $deadline = null,
    ) {}

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $protectedInput = $invocation->protectedInput;

        try {
            $process = new SymfonyProcess(['setsid', '--', ...$invocation->arguments]);
            $timeout = $this->deadline?->cap($invocation->timeout) ?? $invocation->timeout;
            $process->setTimeout($timeout);
            $process->setInput($protectedInput?->stream() ?? $invocation->input);

            $stdout = '';
            $stderr = '';
            $truncated = false;
            $maxOutputBytes = $invocation->maxOutputBytes ?? $this->maxOutputBytes;
            $startedAt = microtime(true);
            /** @var list<ProcessOutput> $pendingOutput */
            $pendingOutput = [];

            $consume = function (ProcessOutputStream $stream, string $buffer) use (
                &$stdout,
                &$stderr,
                &$truncated,
                &$pendingOutput,
                $maxOutputBytes,
                $invocation,
            ): void {
                if ($invocation->output !== null) {
                    $this->bufferOutput($pendingOutput, $stream, $buffer);
                }

                if ($stream === ProcessOutputStream::Stdout) {
                    $stdout = $this->appendBounded($stdout, $buffer, $truncated, $maxOutputBytes);

                    return;
                }

                $stderr = $this->appendBounded($stderr, $buffer, $truncated, $maxOutputBytes);
            };

            $process->start();

            try {
                do {
                    $running = $process->isRunning();
                    $consume(ProcessOutputStream::Stdout, $process->getIncrementalOutput());
                    $consume(ProcessOutputStream::Stderr, $process->getIncrementalErrorOutput());
                    $this->emitPendingOutput($pendingOutput, $invocation->output);

                    if ($running && ($invocation->cancelled) !== null && ($invocation->cancelled)()) {
                        throw new ProcessCancelledException;
                    }

                    if ($running) {
                        if (microtime(true) - $startedAt > $timeout) {
                            throw new ProcessTimedOutException($process, ProcessTimedOutException::TYPE_GENERAL);
                        }

                        usleep(10_000);
                    }
                } while ($running);
            } catch (Throwable $exception) {
                $this->terminateProcessGroup($process);

                throw $exception;
            }

            return new CommandResult(
                exitCode: $process->getExitCode() ?? 1,
                stdout: $stdout,
                stderr: $stderr,
                durationMs: (int) round((microtime(true) - $startedAt) * 1_000),
                truncated: $truncated,
            );
        } finally {
            $protectedInput?->close();
        }
    }

    /** @param list<ProcessOutput> $pendingOutput */
    private function bufferOutput(array &$pendingOutput, ProcessOutputStream $stream, string $buffer): void
    {
        while ($buffer !== '') {
            $last = array_key_last($pendingOutput);

            if ($last !== null && $pendingOutput[$last]->stream === $stream) {
                $available = self::MaxEventBytes - strlen($pendingOutput[$last]->value);

                if ($available > 0) {
                    $value = substr($buffer, 0, $available);
                    $pendingOutput[$last] = new ProcessOutput(
                        $stream,
                        $pendingOutput[$last]->value.$value,
                    );
                    $buffer = substr($buffer, strlen($value));

                    continue;
                }
            }

            $value = substr($buffer, 0, self::MaxEventBytes);
            $pendingOutput[] = new ProcessOutput($stream, $value);
            $buffer = substr($buffer, strlen($value));
        }
    }

    /**
     * @param  list<ProcessOutput>  $pendingOutput
     * @param  (\Closure(ProcessOutput): void)|null  $sink
     */
    private function emitPendingOutput(array &$pendingOutput, ?\Closure $sink): void
    {
        if ($sink === null) {
            return;
        }

        foreach ($pendingOutput as $output) {
            $sink($output);
        }

        $pendingOutput = [];
    }

    private function terminateProcessGroup(SymfonyProcess $process): void
    {
        $pid = $process->getPid();

        if (is_int($pid) && $pid > 1 && function_exists('posix_kill')) {
            @posix_kill(-$pid, SIGTERM);
            usleep(100_000);
            @posix_kill(-$pid, SIGKILL);
        }

        if ($process->isRunning()) {
            $process->stop(0, SIGKILL);
        }
    }

    private function appendBounded(string $current, string $buffer, bool &$truncated, int $maxOutputBytes): string
    {
        $combined = $current.$buffer;

        if (strlen($combined) <= $maxOutputBytes) {
            return $combined;
        }

        $truncated = true;

        return substr($combined, -$maxOutputBytes);
    }
}
