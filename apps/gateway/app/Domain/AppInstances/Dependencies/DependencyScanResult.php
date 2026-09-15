<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Dependencies;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DependencyScanResult
{
    private function __construct(
        public DependencyEcosystem $ecosystem,
        public DateTimeImmutable $attemptedAt,
        /** Latest successful observation, including a retained observation after failure. */
        public ?DependencySnapshot $snapshot,
        /** Stable error code; never raw process output or source contents. */
        public ?string $errorCode,
    ) {}

    public static function refreshed(DependencySnapshot $snapshot): self
    {
        return new self($snapshot->ecosystem, $snapshot->observedAt, $snapshot, null);
    }

    public static function failed(
        DependencyEcosystem $ecosystem,
        DateTimeImmutable $attemptedAt,
        string $errorCode,
        ?DependencySnapshot $previous = null,
    ): self {
        if ($errorCode === '') {
            throw new InvalidArgumentException('A failed scan requires an error code.');
        }

        if ($previous !== null && $previous->ecosystem !== $ecosystem) {
            throw new InvalidArgumentException('A retained snapshot must belong to the scanned ecosystem.');
        }

        return new self($ecosystem, $attemptedAt, $previous, $errorCode);
    }

    public function succeeded(): bool
    {
        return $this->errorCode === null;
    }

    public function state(): DependencyInventoryState
    {
        if ($this->snapshot === null) {
            return DependencyInventoryState::Unknown;
        }

        if (! $this->succeeded()) {
            return DependencyInventoryState::Stale;
        }

        return $this->snapshot->graph === null
            ? DependencyInventoryState::Absent
            : DependencyInventoryState::Present;
    }
}
