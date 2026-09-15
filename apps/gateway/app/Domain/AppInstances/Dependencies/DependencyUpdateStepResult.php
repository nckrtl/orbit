<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Dependencies;

use InvalidArgumentException;

final readonly class DependencyUpdateStepResult
{
    private function __construct(
        public DependencyEcosystem $ecosystem,
        public DependencyUpdateStepStatus $status,
        /** True allows partial or successful mutation; it does not assert that files changed. */
        public bool $mayHaveMutated,
        public ?string $errorCode,
    ) {}

    public static function succeeded(DependencyEcosystem $ecosystem): self
    {
        return new self($ecosystem, DependencyUpdateStepStatus::Succeeded, true, null);
    }

    public static function absent(DependencyEcosystem $ecosystem): self
    {
        return new self($ecosystem, DependencyUpdateStepStatus::Absent, false, null);
    }

    public static function notRun(DependencyEcosystem $ecosystem): self
    {
        return new self($ecosystem, DependencyUpdateStepStatus::NotRun, false, null);
    }

    public static function failed(DependencyEcosystem $ecosystem, string $errorCode, bool $mayHaveMutated): self
    {
        if ($errorCode === '') {
            throw new InvalidArgumentException('A failed update step requires an error code.');
        }

        return new self($ecosystem, DependencyUpdateStepStatus::Failed, $mayHaveMutated, $errorCode);
    }

    public function completed(): bool
    {
        return in_array($this->status, [DependencyUpdateStepStatus::Succeeded, DependencyUpdateStepStatus::Absent], true);
    }
}
