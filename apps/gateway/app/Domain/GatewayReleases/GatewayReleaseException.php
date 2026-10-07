<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\CommandResult;
use Throwable;

/**
 * A Gateway release step that refused or failed. `step` names the release phase, so the release
 * record and the command output say where it stopped.
 */
final class GatewayReleaseException extends ResourceOperationException
{
    public function __construct(
        public readonly string $step,
        string $errorCode,
        string $message,
        int $status = 409,
        ?Throwable $previous = null,
        public readonly ?CommandResult $result = null,
        public readonly ?string $sha = null,
    ) {
        parent::__construct($errorCode, $message, $status, $previous);
    }

    /**
     * Any failure during a release step as a release failure of that step. An exception the
     * release code did not expect keeps its class and message, so the record says what broke.
     */
    public static function fromThrowable(Throwable $exception, string $step, ?string $sha = null): self
    {
        if ($exception instanceof self) {
            return $sha !== null && $exception->sha === null ? $exception->withSha($sha) : $exception;
        }

        return new self(
            step: $step,
            errorCode: 'gateway.release_unexpected_failure',
            message: sprintf('Release step [%s] failed unexpectedly: %s: %s', $step, $exception::class, $exception->getMessage()),
            status: 500,
            previous: $exception,
            sha: $sha,
        );
    }

    /** The same failure, naming the commit it belongs to once that commit is known. */
    public function withSha(string $sha): self
    {
        return new self(
            step: $this->step,
            errorCode: $this->errorCode,
            message: $this->getMessage(),
            status: $this->status,
            previous: $this,
            result: $this->result,
            sha: $sha,
        );
    }
}
