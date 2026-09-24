<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

/**
 * One check run on a commit, as the checks API reports it.
 */
final readonly class GitHubCheckRun
{
    private const array FAILED_CONCLUSIONS = ['failure', 'timed_out', 'cancelled', 'startup_failure', 'action_required'];

    /**
     * @param  string|null  $conclusion  null until the check run completes
     * @param  string|null  $url  the check run page, or the details page of the check's own service
     */
    public function __construct(
        public string $name,
        public ?string $conclusion,
        public ?string $url,
    ) {}

    /** Whether the check run completed without passing. Neutral and skipped runs do not fail. */
    public function failed(): bool
    {
        return in_array($this->conclusion, self::FAILED_CONCLUSIONS, true);
    }
}
