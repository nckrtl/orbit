<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

/**
 * The state of one required check on one commit, by the green-commit rules: every run of the name must
 * come from GitHub Actions and pass on exactly that commit, and at least one must exist (ADR 0203).
 */
enum RequiredCheckState: string
{
    /** The only App whose check runs count. A run of the required name from any other App disqualifies the commit. */
    public const string CheckApp = 'github-actions';

    case Passed = 'passed';
    case Pending = 'pending';
    case Missing = 'missing';
    case Failed = 'failed';
    case Unreadable = 'unreadable';

    /** @param list<GitHubCheckRun> $runs */
    public static function of(array $runs, string $sha, string $checkName): self
    {
        $required = array_values(array_filter($runs, static fn (GitHubCheckRun $run): bool => $run->name === $checkName));
        if ($required === []) {
            return self::Missing;
        }
        if (! array_all($required, static fn (GitHubCheckRun $run): bool => $run->appSlug === self::CheckApp && $run->headSha === $sha)) {
            return self::Failed;
        }
        if (array_any($required, static fn (GitHubCheckRun $run): bool => $run->status !== 'completed')) {
            return self::Pending;
        }

        return array_all($required, static fn (GitHubCheckRun $run): bool => $run->passedOn($sha)) ? self::Passed : self::Failed;
    }
}
