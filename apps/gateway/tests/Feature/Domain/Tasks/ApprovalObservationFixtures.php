<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Tasks;

use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubPullRequestState;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitHubReview;
use App\Domain\GitHub\GitHubReviewState;
use App\Domain\Tasks\TaskReviewObservation;
use App\Domain\Tasks\TaskReviewReadStatus;
use App\Domain\Tasks\TaskReviewSelection;
use App\Domain\Tasks\TaskReviewTrust;
use App\Models\Project;
use App\Models\Task;
use DateTimeImmutable;
use Tests\Feature\GitHub\GitHubTestSupport;

final class ApprovalObservationFixtures
{
    public static function group(): Task
    {
        $project = Project::query()->firstOrCreate(['repository_url' => 'https://github.com/acme/orbit.git'], [
            'name' => 'Approval evidence', 'slug' => 'approval-evidence-'.fake()->uuid(),
            'repository_url' => 'https://github.com/acme/orbit.git', 'default_branch' => 'main',
        ]);

        return Task::topLevel()->create([
            'project_id' => $project->id, 'title' => 'Observe approvals', 'brief' => 'Read only.',
            'status' => 'settling', 'pr_url' => 'https://github.com/acme/orbit/pull/42',
        ])->load('project');
    }

    public static function review(int $id = 101, GitHubReviewState $state = GitHubReviewState::Approved, string $head = 'abc123', string $login = 'source-name', int $reviewer = 42): GitHubReview
    {
        $source = GitHubTestSupport::review();

        return new GitHubReview($id, $reviewer, $login, $state, $head,
            new DateTimeImmutable($source['submitted_at']), $source['html_url'], $source['body']);
    }

    /** @param list<GitHubReview> $reviews
     * @param  list<int>  $accounts
     */
    public static function observation(array $reviews = [], string $head = 'abc123', array $accounts = [42, 7], GitHubPullRequestState $state = GitHubPullRequestState::Open, TaskReviewReadStatus $read = TaskReviewReadStatus::Complete, string $repository = 'acme/orbit', int $number = 42): TaskReviewObservation
    {
        $repo = GitHubRepository::fromOrigin('https://github.com/'.$repository.'.git');
        $trust = TaskReviewTrust::fromConfig($repo, [$repository => $accounts]);
        $pr = new GitHubPullRequest($state, true, 'clean', $head, 'main');

        return new TaskReviewObservation($read, $repo, $number, $trust, $pr,
            $read === TaskReviewReadStatus::Complete ? $reviews : [],
            $read === TaskReviewReadStatus::Complete ? TaskReviewSelection::select($reviews, $trust, $head, $state === GitHubPullRequestState::Open) : null);
    }
}
