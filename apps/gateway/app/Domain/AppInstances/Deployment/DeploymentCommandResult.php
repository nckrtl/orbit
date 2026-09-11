<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Deployment;

use App\Infrastructure\Processes\CommandResult;

final readonly class DeploymentCommandResult
{
    public function __construct(
        public string $step,
        public CommandResult $result,
    ) {}

    /** @return array{step: string, result: array{exit_code: int, stdout: string, stderr: string, duration_ms: int, truncated: bool}} */
    public function __debugInfo(): array
    {
        return [
            'step' => $this->step,
            'result' => [
                'exit_code' => $this->result->exitCode,
                'stdout' => '[OUTPUT]',
                'stderr' => '[OUTPUT]',
                'duration_ms' => $this->result->durationMs,
                'truncated' => $this->result->truncated,
            ],
        ];
    }
}
