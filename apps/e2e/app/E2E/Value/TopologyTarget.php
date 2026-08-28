<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

final readonly class TopologyTarget
{
    public function __construct(
        public string $issue,
        private bool $standby = false,
    ) {
        if (! $standby && preg_match('/\A[A-Z][A-Z0-9]{1,9}-[1-9][0-9]{0,8}\z/D', $issue) !== 1) {
            throw new InvalidArgumentException('The Linear issue ID is invalid.');
        }

        if ($standby && $issue !== 'standby') {
            throw new InvalidArgumentException('The standby target identity is invalid.');
        }
    }

    public static function standby(): self
    {
        return new self('standby', true);
    }

    public function isStandby(): bool
    {
        return $this->standby;
    }

    public function network(): string
    {
        return $this->standby ? 'oe-standby' : 'oe-'.substr(hash('sha256', $this->issue), 0, 12);
    }

    public function instance(string $role): string
    {
        if (! in_array($role, TopologyProfile::ROLES, true)) {
            throw new InvalidArgumentException('The topology role is invalid.');
        }

        return 'orbit-e2e-'.strtolower($this->issue).'-'.$role;
    }
}
