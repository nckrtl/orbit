<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/** @mago-expect lint:too-many-methods One identity boundary derives every attempt-scoped resource name. */
final readonly class TopologyTarget
{
    private const string ISSUE_PATTERN = '/\A[A-Z][A-Z0-9]{1,9}-[1-9][0-9]{0,8}\z/D';

    private function __construct(
        public string $issue,
        public ?AttemptId $attempt,
        private bool $standby = false,
    ) {}

    public static function feature(string $issue, AttemptId $attempt): self
    {
        self::assertIssue($issue);

        return new self($issue, $attempt);
    }

    public static function standby(): self
    {
        return new self('standby', null, true);
    }

    public static function assertIssue(string $issue): void
    {
        if (preg_match(self::ISSUE_PATTERN, $issue) !== 1) {
            throw new InvalidArgumentException('The Linear issue ID is invalid.');
        }
    }

    public static function ipv4For(int $slot, string $role): string
    {
        if ($slot < 1 || $slot > 200) {
            throw new InvalidArgumentException('The topology slot is invalid.');
        }

        $roleIndex = array_search($role, TopologyProfile::ROLES, true);

        if ($roleIndex === false) {
            throw new InvalidArgumentException('The topology role is invalid.');
        }

        return '10.232.'.$slot.'.'.(10 + $roleIndex);
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

        return self::issueMatchesBranch($this->issue, $branch);
    }

    public static function issueMatchesBranch(string $issue, string $branch): bool
    {
        self::assertIssue($issue);

        return (
            preg_match(
                '/(?:\A|[^a-z0-9])'.preg_quote($issue, '/').'(?=\z|[^a-z0-9])/i',
                $branch,
            ) === 1
        );
    }

    public function network(): string
    {
        if ($this->standby) {
            return 'oe-standby';
        }

        return 'oe-'.substr(hash('sha256', $this->issue.':'.$this->requireAttempt()->value), 0, 12);
    }

    public function instance(string $role): string
    {
        $this->validateRole($role);

        if ($this->standby) {
            return 'orbit-e2e-standby-'.$role;
        }

        return 'orbit-e2e-'.strtolower($this->issue).'-'.$this->requireAttempt()->short().'-'.$role;
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

    /** The attempt identity of a feature target; standby has none. */
    public function requireAttempt(): AttemptId
    {
        return $this->attempt ?? throw new InvalidArgumentException('The feature target has no attempt identity.');
    }

    private function validateRole(string $role): void
    {
        if (! in_array($role, TopologyProfile::ROLES, true)) {
            throw new InvalidArgumentException('The topology role is invalid.');
        }
    }
}
