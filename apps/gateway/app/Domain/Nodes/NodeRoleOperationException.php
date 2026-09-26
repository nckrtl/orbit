<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Infrastructure\Processes\CommandResult;
use RuntimeException;
use Throwable;

final class NodeRoleOperationException extends RuntimeException
{
    public readonly ?CommandResult $result;

    public function __construct(
        public readonly string $step,
        public readonly string $errorCode,
        public readonly string $underlyingErrorCode,
        string $message,
        ?CommandResult $result = null,
        ?Throwable $previous = null,
    ) {
        $this->result = $result === null
            ? null
            : new CommandResult(
                exitCode: $result->exitCode,
                stdout: '',
                stderr: '',
                durationMs: $result->durationMs,
                truncated: $result->truncated,
            );

        parent::__construct($message, previous: $previous);
    }

    /**
     * The specific error code of the first role operation failure in the exception chain, as API error
     * details: `error_code`, such as `node_role.node_busy` or `metrics.image_pull_failed`. The top-level
     * code stays the operation's (`node_role.convergence_failed`, `node_role.remove_failed`).
     *
     * @return array{error_code?: string}
     */
    public static function detailsIn(?Throwable $exception): array
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof self) {
                return ['error_code' => $current->underlyingErrorCode];
            }
        }

        return [];
    }

    /** @return array{message: string, step: string, errorCode: string, underlyingErrorCode: string} */
    public function __debugInfo(): array
    {
        return [
            'message' => $this->getMessage(),
            'step' => $this->step,
            'errorCode' => $this->errorCode,
            'underlyingErrorCode' => $this->underlyingErrorCode,
        ];
    }
}
