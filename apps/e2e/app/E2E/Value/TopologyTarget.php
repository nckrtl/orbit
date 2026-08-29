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

    public function matchesBranch(string $branch): bool
    {
        if ($this->standby) {
            return false;
        }

        return (
            preg_match(
                '/(?:\A|[^a-z0-9])'.preg_quote($this->issue, '/').'(?=\z|[^a-z0-9])/i',
                $branch,
            ) === 1
        );
    }

    public function network(): string
    {
        return $this->standby ? 'oe-standby' : 'oe-'.substr(hash('sha256', $this->issue), 0, 12);
    }

    public function instance(string $role): string
    {
        $this->validateRole($role);

        return 'orbit-e2e-'.strtolower($this->issue).'-'.$role;
    }

    public function mac(string $role): string
    {
        $this->validateRole($role);

        return self::macFor($this->network(), $role);
    }

    public static function macFor(string $topology, string $role): string
    {
        if (
            preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\z/D', $topology) !== 1
            || ! in_array($role, TopologyProfile::ROLES, true)
        ) {
            throw new InvalidArgumentException('The topology network identity is invalid.');
        }

        $hash = substr(sha1($topology.':'.$role), 0, 6);

        return '00:16:3e:'.implode(':', str_split($hash, 2));
    }

    private function validateRole(string $role): void
    {
        if (! in_array($role, TopologyProfile::ROLES, true)) {
            throw new InvalidArgumentException('The topology role is invalid.');
        }
    }
}
