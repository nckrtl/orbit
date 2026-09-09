<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\Gateway\NativeGatewayFpmConverger;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;

require '/home/orbit/orbit/apps/gateway/vendor/autoload.php';

final class Orb157CoordinatedProcessRunner implements ProcessRunner
{
    public function __construct(
        private readonly string $lane,
        private readonly string $workingDirectory,
        private readonly bool $pauseBeforeValidation,
        private readonly ProcessRunner $processes = new NativeProcessRunner,
    ) {}

    public function run(ProcessInvocation $invocation): CommandResult
    {
        file_put_contents(
            $this->workingDirectory."/{$this->lane}.commands.jsonl",
            json_encode($invocation->arguments, JSON_THROW_ON_ERROR)."\n",
            FILE_APPEND | LOCK_EX,
        );

        if (
            array_slice(array: $invocation->arguments, offset: 0, length: 4) === [
                'sudo',
                'bash',
                '-seu',
                '--',
            ]
            && count($invocation->arguments) === 8
        ) {
            file_put_contents(
                $this->workingDirectory."/{$this->lane}.paths",
                implode("\n", array_slice($invocation->arguments, 5, 3))."\n",
                LOCK_EX,
            );
        }

        if ($this->pauseBeforeValidation && $this->isValidation($invocation)) {
            touch($this->workingDirectory."/{$this->lane}.ready");
            $deadline = microtime(true) + 60;

            while (! is_file($this->workingDirectory."/{$this->lane}.release")) {
                if (microtime(true) >= $deadline) {
                    return new CommandResult(124, '', 'Timed out waiting for the proof release marker.', 60_000, false);
                }

                usleep(10_000);
            }
        }

        return $this->processes->run($invocation);
    }

    private function isValidation(ProcessInvocation $invocation): bool
    {
        return array_slice(array: $invocation->arguments, offset: 0, length: 4) === [
            'sudo',
            'php-fpm8.5',
            '--test',
            '--fpm-config',
        ];
    }
}

[$script, $lane, $generatedPool, $workingDirectory, $mode] = $argv;
$expectedFailure = $mode === 'failure';
$processes = new Orb157CoordinatedProcessRunner(
    lane: $lane,
    workingDirectory: $workingDirectory,
    pauseBeforeValidation: ! $expectedFailure,
);

try {
    new NativeGatewayFpmConverger($processes)->converge($generatedPool);
    file_put_contents(
        $workingDirectory."/{$lane}.outcome.json",
        json_encode(['status' => 'published'], JSON_THROW_ON_ERROR),
        LOCK_EX,
    );

    exit($expectedFailure ? 2 : 0);
} catch (NodeProvisioningException $exception) {
    file_put_contents(
        $workingDirectory."/{$lane}.outcome.json",
        json_encode([
            'status' => 'failed',
            'step' => $exception->step,
            'error_code' => $exception->errorCode,
            'stderr' => $exception->result?->stderr,
        ], JSON_THROW_ON_ERROR),
        LOCK_EX,
    );

    exit($expectedFailure ? 0 : 1);
}
