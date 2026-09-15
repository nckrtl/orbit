<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

final readonly class DoctorInspectionScope
{
    /** @param array<int, DoctorNodeContext> $contexts */
    public function __construct(
        private array $contexts,
    ) {}

    public function has(int $nodeId): bool
    {
        return array_key_exists($nodeId, $this->contexts);
    }

    public function context(int $nodeId): ?DoctorNodeContext
    {
        return $this->contexts[$nodeId] ?? null;
    }
}
