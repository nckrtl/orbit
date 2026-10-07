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
    ) {
        parent::__construct($errorCode, $message, $status, $previous);
    }
}
