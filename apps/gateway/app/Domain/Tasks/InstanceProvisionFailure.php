<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Shared\ResourceOperationException;
use Throwable;

final readonly class InstanceProvisionFailure
{
    public function __construct(public string $cause) {}

    public static function fromException(Throwable $exception): self
    {
        $step = match (true) {
            $exception instanceof RuntimeConvergenceException => $exception->step,
            $exception instanceof ResourceOperationException => $exception->errorCode,
            default => null,
        };

        return new self($exception::class.($step === null ? '' : ' ['.$step.']').': '.$exception->getMessage());
    }
}
