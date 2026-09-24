<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * The state of a settling group's pull request, and what keeps an open one from merging
 * ([ADR 0140](/decisions/0140-watch-settling-pull-requests-for-conflicts-and-failed-checks)).
 */
final readonly class TaskPullRequestHealth
{
    /** Starts every assistance reason that this health writes, so the scheduler clears only its own requests. */
    public const string REASON_PREFIX = 'The pull request needs attention: ';

    /**
     * @param  'merged'|'closed'|'open'  $state
     * @param  list<string>  $problems  one plain sentence per problem of an open pull request
     */
    public function __construct(public string $state, public array $problems = []) {}

    public function reason(): string
    {
        return self::REASON_PREFIX.implode(' ', $this->problems);
    }

    public static function isReason(?string $reason): bool
    {
        return is_string($reason) && str_starts_with($reason, self::REASON_PREFIX);
    }
}
