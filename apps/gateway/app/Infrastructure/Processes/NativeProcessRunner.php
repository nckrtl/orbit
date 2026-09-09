<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use Symfony\Component\Process\Process as SymfonyProcess;

final readonly class NativeProcessRunner implements ProcessRunner
{
    public function __construct(
        private int $maxOutputBytes = 65_536,
        private ?CommandDeadline $deadline = null,
    ) {}

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $protectedInput = $invocation->protectedInput;

        try {
            $process = new SymfonyProcess($invocation->arguments);
            $process->setTimeout($this->deadline?->cap($invocation->timeout) ?? $invocation->timeout);
            $process->setInput($protectedInput?->stream() ?? $invocation->input);

            $stdout = '';
            $stderr = '';
            $truncated = false;
            $maxOutputBytes = $invocation->maxOutputBytes ?? $this->maxOutputBytes;
            $startedAt = microtime(true);

            $exitCode = $process->run(function (string $type, string $buffer) use (
                &$stdout,
                &$stderr,
                &$truncated,
                $maxOutputBytes,
            ): void {
                if ($type === SymfonyProcess::OUT) {
                    $stdout = $this->appendBounded($stdout, $buffer, $truncated, $maxOutputBytes);

                    return;
                }

                $stderr = $this->appendBounded($stderr, $buffer, $truncated, $maxOutputBytes);
            });

            return new CommandResult(
                exitCode: $exitCode,
                stdout: $stdout,
                stderr: $stderr,
                durationMs: (int) round((microtime(true) - $startedAt) * 1_000),
                truncated: $truncated,
            );
        } finally {
            $protectedInput?->close();
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
