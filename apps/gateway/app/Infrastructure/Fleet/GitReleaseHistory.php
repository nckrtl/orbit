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

    public function ancestors(string $commit, int $limit): array
    {
        if (preg_match('/\A[0-9a-f]{40}\z/D', $commit) !== 1 || $limit < 1) {
            return [];
        }

        // Releases exist only for main's own commits, so the walk skips the commits of merged branches.
        $result = $this->git(['rev-list', '--first-parent', '--max-count='.($limit + 1), $commit]);

        if (! $result instanceof CommandResult || ! $result->succeeded()) {
            return [];
        }

        $commits = array_values(array_filter(
            explode("\n", trim($result->stdout)),
            static fn (string $line): bool => preg_match('/\A[0-9a-f]{40}\z/D', $line) === 1 && $line !== $commit,
        ));

        return array_slice($commits, 0, $limit);
    }

    public function unchanged(string $from, string $to, array $paths): bool
    {
        if (preg_match('/\A[0-9a-f]{40}\z/D', $from) !== 1 || preg_match('/\A[0-9a-f]{40}\z/D', $to) !== 1 || $paths === []) {
            return false;
        }

        $result = $this->git(['diff', '--quiet', $from, $to, '--', ...$paths]);

        // Exit status 1 means a difference; anything but 0 is not proof that the paths match.
        return $result instanceof CommandResult && $result->succeeded();
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
