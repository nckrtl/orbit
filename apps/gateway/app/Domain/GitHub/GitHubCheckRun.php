<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

/**
 * One check run on a commit, as the checks API reports it.
 */
final readonly class GitHubCheckRun
{
    private const array FAILED_CONCLUSIONS = ['failure', 'timed_out', 'cancelled', 'startup_failure', 'action_required'];

    /** Conclusions that mean the run did not run to completion, such as a GitHub Actions outage, rather than a failed check. */
    private const array INFRASTRUCTURE_CONCLUSIONS = ['cancelled', 'startup_failure'];

    /**
     * @param  string|null  $conclusion  null until the check run completes
     * @param  string|null  $url  the check run page, or the details page of the check's own service
     * @param  int|null  $id  the check run id, when GitHub sent one
     * @param  string|null  $startedAt  when the run started, when GitHub sent `started_at`
     */
    public function __construct(
        public string $name,
        public ?string $conclusion,
        public ?string $url,
        public ?int $id = null,
        public ?string $startedAt = null,
    ) {}

    /** Whether the check run completed without passing. Neutral and skipped runs do not fail. */
    public function failed(): bool
    {
        return in_array($this->conclusion, self::FAILED_CONCLUSIONS, true);
    }

    /** Whether the run failed because it was cancelled or could not start, not because the check found a problem. */
    public function infrastructure(): bool
    {
        return in_array($this->conclusion, self::INFRASTRUCTURE_CONCLUSIONS, true);
    }

    /** Whether the run has not completed yet. */
    public function pending(): bool
    {
        return $this->conclusion === null;
    }
}
