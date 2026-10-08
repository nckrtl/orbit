<?php

declare(strict_types=1);

namespace App\Infrastructure\Fleet;

use App\Domain\Fleet\ReleaseHistory;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use Throwable;

/**
 * Reads the Gateway's own Git history: the in-place checkout, or the linked worktree of a Gateway release.
 * A shallow clone has the wrong commit count, so it reports no count, as `bin/orbit-cli-release-version` does.
 */
final readonly class GitReleaseHistory implements ReleaseHistory
{
    private const float TimeoutSeconds = 10.0;

    public function __construct(
        private ProcessRunner $processes,
        private string $directory,
    ) {}

    public function commit(string $revision): ?string
    {
        if (preg_match('/\A[0-9a-f]{7,40}\z/D', $revision) !== 1) {
            return null;
        }

        $result = $this->git(['rev-parse', '--verify', '--quiet', $revision.'^{commit}']);
        $commit = $result instanceof CommandResult && $result->succeeded() ? trim($result->stdout) : '';

        return preg_match('/\A[0-9a-f]{40}\z/D', $commit) === 1 && str_starts_with($commit, $revision) ? $commit : null;
    }

    public function count(string $commit): ?int
    {
        if (preg_match('/\A[0-9a-f]{40}\z/D', $commit) !== 1) {
            return null;
        }

        $shallow = $this->git(['rev-parse', '--is-shallow-repository']);

        if (! $shallow instanceof CommandResult || ! $shallow->succeeded() || trim($shallow->stdout) !== 'false') {
            return null;
        }

        $count = $this->git(['rev-list', '--count', $commit]);
        $value = $count instanceof CommandResult && $count->succeeded() ? trim($count->stdout) : '';

        return preg_match('/\A[1-9][0-9]{0,9}\z/D', $value) === 1 ? (int) $value : null;
    }

    /** @param  list<string>  $arguments */
    private function git(array $arguments): ?CommandResult
    {
        try {
            return $this->processes->run(new ProcessInvocation(
                arguments: ['git', '-C', $this->directory, ...$arguments],
                timeout: self::TimeoutSeconds,
                maxOutputBytes: 4096,
            ));
        } catch (Throwable) {
            return null;
        }
    }
}
