<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use RuntimeException;

final class ToolOperationException extends RuntimeException
{
    /** @mago-expect lint:excessive-parameter-list Stable operation failures expose each required field directly. */
    public function __construct(
        public readonly string $step,
        public readonly string $errorCode,
        public readonly ToolOutcome $outcome,
        public readonly int $status,
        public readonly int $nodeId,
        public readonly string $manager,
        public readonly string $package,
        public readonly ?string $versionConstraint,
        string $message,
        ?ToolManagerException $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /** @return array{message: string, step: string, errorCode: string, outcome: string, status: int, nodeId: int, manager: string, package: string, versionConstraint: string|null, previous: array{message: string, step: string, result: array{exitCode: int, durationMs: int, truncated: bool}|null}|null} */
    public function __debugInfo(): array
    {
        $previous = $this->getPrevious();

        return [
            'message' => $this->getMessage(),
            'step' => $this->step,
            'errorCode' => $this->errorCode,
            'outcome' => $this->outcome->value,
            'status' => $this->status,
            'nodeId' => $this->nodeId,
            'manager' => $this->manager,
            'package' => $this->package,
            'versionConstraint' => $this->versionConstraint,
            'previous' => $previous instanceof ToolManagerException
                ? $previous->__debugInfo()
                : null,
        ];
    }
}
