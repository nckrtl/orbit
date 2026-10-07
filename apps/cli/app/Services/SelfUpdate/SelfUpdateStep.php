<?php

declare(strict_types=1);

namespace App\Services\SelfUpdate;

/** The verified outcome of one self-update step. */
final readonly class SelfUpdateStep
{
    public function __construct(
        public string $step,
        public SelfUpdateOutcome $outcome,
        public ?string $path = null,
        public ?InstalledVersion $before = null,
        public ?InstalledVersion $after = null,
        public ?string $reason = null,
        public ?SelfUpdateFailure $failure = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'step' => $this->step,
            'outcome' => $this->outcome->value,
            'reason' => $this->reason,
            'path' => $this->path,
            'before' => $this->before?->toArray(),
            'after' => $this->after?->toArray(),
            'error' => $this->failure instanceof SelfUpdateFailure
                ? ['code' => $this->failure->errorCode, 'message' => $this->failure->getMessage()]
                : null,
        ];
    }
}
