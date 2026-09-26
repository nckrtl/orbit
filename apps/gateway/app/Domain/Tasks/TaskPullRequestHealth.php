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
     * @param  list<TaskPullRequestCheck>  $failedChecks  genuine failed runs in the order GitHub returned them
     * @param  list<TaskPullRequestCheck>  $infrastructureChecks  cancelled runs, runs that could not start, and runs pending for more than 60 minutes
     * @param  bool  $checksPending  whether a check run on the head has not completed yet
     * @param  bool  $checksYoungPending  whether a run on the head has been pending for 60 minutes or less
     */
    public function __construct(
        public string $state,
        public array $problems = [],
        public ?string $baseRef = null,
        public bool $conflicts = false,
        public array $failedChecks = [],
        public ?string $headSha = null,
        public array $infrastructureChecks = [],
        public bool $checksPending = false,
        public bool $checksYoungPending = false,
    ) {}

    public function reason(?string $extra = null): string
    {
        return self::REASON_PREFIX.implode(' ', $this->problems).($extra !== null ? ' '.$extra : '');
    }

    public static function isReason(?string $reason): bool
    {
        return is_string($reason) && str_starts_with($reason, self::REASON_PREFIX);
    }
}
