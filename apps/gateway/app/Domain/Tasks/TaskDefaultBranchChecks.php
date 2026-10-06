<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Models\Task;

final readonly class TaskDefaultBranchChecks
{
    public function __construct(private GitHubApi $github, private RepositoryPullRequestAccess $access) {}

    public function green(Task $group, string $tip): bool
    {
        $group->loadMissing('project');
        $repository = GitHubRepository::fromOrigin((string) $group->project->repository_url);
        if (! $repository instanceof GitHubRepository) {
            return false;
        }
        $token = $this->access->checksToken($repository);
        if ($token === null) {
            return false;
        }
        try {
            $runs = $this->github->checkRuns($token, $repository, $tip);
        } catch (GitHubApiException) {
            return false;
        }
        $success = false;
        foreach ($runs as $run) {
            if (in_array($run->name, TaskPullRequestCheck::ROLLUP_NAMES, true)) {
                continue;
            }
            if ($run->pending() || (! $run->infrastructure() && ! in_array($run->conclusion, ['success', 'neutral', 'skipped'], true))) {
                return false;
            }
            $success = $success || $run->conclusion === 'success';
        }

        return $success;
    }
}
