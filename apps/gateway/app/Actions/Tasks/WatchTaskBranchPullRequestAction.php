<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Tasks\TaskStatus;
use App\Models\Task;
use Illuminate\Support\Facades\Cache;
use Throwable;

final readonly class WatchTaskBranchPullRequestAction
{
    public function __construct(private RepositoryPullRequestAccess $access, private GitHubApi $github) {}

    public function execute(Task $group): void
    {
        if (RequestEndedPullRequestAssistanceAction::isReason($group->assistance_reason)) {
            return;
        }
        $group->loadMissing(['project', 'tasks']);
        if (! $group->tasks->contains(static fn (Task $task): bool => in_array($task->status, [TaskStatus::Todo, TaskStatus::Running, TaskStatus::Reviewing], true))) {
            return;
        }
        $repository = GitHubRepository::fromOrigin($group->project->repository_url ?? '');
        if ($repository === null) {
            return;
        }
        try {
            if (! Cache::add('tasks:branch-pull-request:'.$group->id, true, 60)) {
                return;
            }
            $pullRequests = $this->github->pullRequestsByHead(
                $this->access->cachedReadToken($repository), $repository, 'task-'.$group->id,
            );
        } catch (Throwable) {
            return;
        }
        $selected = $pullRequests[0] ?? null;
        foreach ($pullRequests as $pullRequest) {
            if ($pullRequest->state === GitHubPullRequestState::Open) {
                $selected = $pullRequest;
                break;
            }
        }
        if ($selected !== null) {
            $group->update([
                'watched_pr_url' => $selected->url,
                'watched_pr_number' => $selected->number,
                'watched_pr_state' => $selected->state->value,
            ]);
        }
    }
}
