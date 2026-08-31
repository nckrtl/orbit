<?php

declare(strict_types=1);

namespace App\Domain\Nodes\Storage;

final readonly class LegacyNodeSettings
{
    public function __construct(
        public ?string $instancePath = null,
        public ?string $worktreePath = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->instancePath === null && $this->worktreePath === null;
    }
}
