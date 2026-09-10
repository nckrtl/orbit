<?php

declare(strict_types=1);

namespace App\E2E\Value;

final readonly class ColdTopologyCleanupResult
{
    /**
     * @param  list<string>  $removed
     * @param  list<string>  $absent
     * @param  list<string>  $refused
     * @param  list<string>  $remaining
     */
    public function __construct(
        public array $removed,
        public array $absent,
        public array $refused,
        public array $remaining = [],
        public ?string $recoveryCommand = null,
    ) {}

    public function successful(): bool
    {
        return $this->refused === [] && $this->remaining === [];
    }

    /** @return array{removed:list<string>,absent:list<string>,refused:list<string>,remaining:list<string>,recovery_command:?string} */
    public function toArray(): array
    {
        return [
            'removed' => $this->removed,
            'absent' => $this->absent,
            'refused' => $this->refused,
            'remaining' => $this->remaining,
            'recovery_command' => $this->recoveryCommand,
        ];
    }
}
