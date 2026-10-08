<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\GitHub\GitHubListedPullRequest;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\TaskAgentDefaults;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskFinalReview;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestException;
use App\Domain\Tasks\TaskPullRequestMerger;
use App\Domain\Tasks\TaskReviewTrust;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskType;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ADR 0203: at most once a minute for each review-and-merge Project, lists the open pull requests and creates
 * a review task for each one a listed author opened from the same repository against the default branch.
 * A draft, a fork, another author, and a pull request that already has a task that did not fail are skipped.
 */
final readonly class WatchIncomingPullRequestsAction
{
    private const int BriefBodyLimit = 6000;

    private const int TitleLimit = 160;

    public function __construct(
        private TaskPullRequestMerger $merger,
        private TaskExtensionState $extension,
        private AgentDriverRegistry $drivers,
    ) {}

    /** @return list<Task> the tasks created */
    public function execute(): array
    {
        if (! $this->extension->enabled()) {
            return [];
        }
        $created = [];
        foreach (Project::query()->where('review_and_merge', true)->orderBy('id')->get() as $project) {
            if (! $project->reviewsAndMerges()) {
                continue;
            }
            $repository = GitHubRepository::fromOrigin((string) $project->repository_url);
            if (! $repository instanceof GitHubRepository) {
                continue;
            }
            $authors = TaskReviewTrust::fromConfig($repository, config('orbit.tasks.pull_request_authors', []));
            if (! $authors->valid || $authors->accountIds === [] || ! Cache::add('tasks.incoming-pull-requests.'.$project->id, true, 60)) {
                continue;
            }
            try {
                $pullRequests = $this->merger->openPullRequests($project);
            } catch (TaskPullRequestException $exception) {
                Log::warning('Orbit could not list the open pull requests of a review-and-merge Project.', [
                    'project_id' => $project->id, 'reason' => $exception->getMessage(),
                ]);

                continue;
            }
            foreach ($pullRequests as $pullRequest) {
                if ($this->eligible($project, $repository, $authors, $pullRequest)) {
                    $task = $this->create($project, $pullRequest);
                    if ($task instanceof Task) {
                        $created[] = $task;
                    }
                }
            }
        }

        return $created;
    }

    private function eligible(Project $project, GitHubRepository $repository, TaskReviewTrust $authors, GitHubListedPullRequest $pullRequest): bool
    {
        $default = $project->default_branch;

        return in_array($pullRequest->authorId, $authors->accountIds, true)
            && ! $pullRequest->draft
            && is_string($pullRequest->headRepository)
            && strtolower($pullRequest->headRepository) === strtolower($repository->owner.'/'.$repository->name)
            && is_string($default) && $pullRequest->baseRef === $default
            && $pullRequest->headRef !== $default
            && ! str_starts_with($pullRequest->headRef, 'task-')
            && GitBranchName::isValid($pullRequest->headRef);
    }

    private function create(Project $project, GitHubListedPullRequest $pullRequest): ?Task
    {
        try {
            $implementerDriver = $this->drivers->get($this->configured('orbit.tasks.implementer_agent_driver', 'pi'))->key();
            $reviewerDriver = $this->drivers->get($this->configured('orbit.tasks.reviewer_agent_driver', 'pi'))->key();
        } catch (AgentDriverException) {
            return null;
        }

        $task = DB::transaction(function () use ($project, $pullRequest, $implementerDriver, $reviewerDriver): ?Task {
            $exists = Task::topLevel()->where('project_id', $project->id)->where('pr_url', $pullRequest->url)
                ->where('status', '!=', TaskGroupStatus::Failed->value)->lockForUpdate()->exists();
            if ($exists) {
                return null;
            }
            $group = Task::topLevel()->create([
                'project_id' => $project->id,
                'implementer_agent_driver' => $implementerDriver,
                'reviewer_agent_driver' => $reviewerDriver,
                'title' => mb_substr('Review #'.$pullRequest->number.': '.$pullRequest->title, 0, self::TitleLimit),
                'brief' => $this->brief($pullRequest),
                'status' => TaskGroupStatus::Todo,
                'pr_url' => $pullRequest->url,
                'pr_branch' => $pullRequest->headRef,
                'implementer_model' => $this->configured('orbit.tasks.implementer_model', TaskAgentDefaults::ImplementerModel),
                'reviewer_model' => $this->configured('orbit.tasks.reviewer_model', TaskAgentDefaults::ReviewerModel),
            ]);
            $group->setRelation('project', $project);
            Task::query()->create([
                'parent_id' => $group->id,
                'position' => 1,
                'type' => TaskType::FinalReview,
                'title' => TaskFinalReview::Title,
                'brief' => TaskFinalReview::brief($group),
                'deliverables' => TaskFinalReview::deliverables(),
                'fixup_head_sha' => $pullRequest->headSha,
                'status' => TaskStatus::Todo,
            ]);

            return $group;
        });
        if ($task instanceof Task) {
            TaskFinalReview::log($task, 'incoming pull request task created', [
                'pull_request' => $pullRequest->url, 'author' => $pullRequest->authorLogin, 'head_sha' => $pullRequest->headSha,
            ]);
        }

        return $task;
    }

    private function brief(GitHubListedPullRequest $pullRequest): string
    {
        $body = trim((string) $pullRequest->body);
        if (mb_strlen($body) > self::BriefBodyLimit) {
            $body = mb_substr($body, 0, self::BriefBodyLimit)."\n\n[Cut. Read the full description on GitHub.]";
        }

        return 'Orbit reviews pull request '.$pullRequest->url.' by '.$pullRequest->authorLogin.' from branch '.$pullRequest->headRef.'. '
            .'Orbit applies any changes it requests itself, on that branch, and merges the pull request when its reviewed head passes CI.'
            ."\n\nTitle: ".$pullRequest->title
            ."\n\nDescription:\n\n".($body !== '' ? $body : '(none)');
    }

    private function configured(string $key, string $default): string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
