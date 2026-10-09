<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskMergeStatus;
use App\Domain\Tasks\TaskReviewedCommitSource;
use App\Domain\Tasks\TaskStatus;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskReviewedCommit;

beforeEach(function (): void {
    $gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'review-merge-gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.83', 'wireguard_ip' => '10.44.0.83',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    app(TaskExtensionState::class)->enable();
});

function review_merge_api_project(array $attributes = []): Project
{
    return Project::query()->create([
        'name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'https://github.com/acme/shop.git', 'default_branch' => 'main',
        ...$attributes,
        'apps' => fixture_apps(null),
    ]);
}

describe('Project review-and-merge settings', function (): void {
    it('switches the flow on with a merge check and reports both fields', function (): void {
        $project = review_merge_api_project();

        $this->patchJson('/api/v1/projects/'.$project->id, ['review_and_merge' => true, 'merge_check' => 'Required checks'])
            ->assertOk()
            ->assertJsonPath('data.review_and_merge', true)
            ->assertJsonPath('data.merge_check', 'Required checks');

        expect($project->fresh()?->reviewsAndMerges())->toBeTrue()
            ->and(Activity::query()->where('command', 'project:update')->latest('id')->first()?->properties['input'] ?? null)
            ->toMatchArray(['review_and_merge' => true, 'merge_check' => 'Required checks']);

        $this->patchJson('/api/v1/projects/'.$project->id, ['review_and_merge' => false])
            ->assertOk()
            ->assertJsonPath('data.review_and_merge', false);

        expect($project->fresh()?->reviewsAndMerges())->toBeFalse();
    });

    it('defaults to off', function (): void {
        $project = review_merge_api_project();

        $this->getJson('/api/v1/projects/'.$project->id)
            ->assertOk()
            ->assertJsonPath('data.review_and_merge', false)
            ->assertJsonPath('data.merge_check', null);
    });

    it('refuses to switch the flow on without what it needs', function (array $attributes, array $body, string $field): void {
        $project = review_merge_api_project($attributes);

        $this->patchJson('/api/v1/projects/'.$project->id, $body)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed')
            ->assertJsonStructure(['error' => ['details' => [$field]]]);

        expect($project->fresh()?->review_and_merge)->toBe($attributes['review_and_merge'] ?? false)
            ->and($project->fresh()?->merge_check)->toBe($attributes['merge_check'] ?? null);
    })->with([
        'no merge check' => [[], ['review_and_merge' => true], 'merge_check'],
        'GitHub CLI source access' => [['source_access' => 'gh_cli'], ['review_and_merge' => true, 'merge_check' => 'Required checks'], 'review_and_merge'],
        'VM compute' => [['task_compute' => 'vm'], ['review_and_merge' => true, 'merge_check' => 'Required checks'], 'review_and_merge'],
        'clearing the check of an enabled Project' => [['review_and_merge' => true, 'merge_check' => 'Required checks'], ['merge_check' => null], 'merge_check'],
    ]);
});

describe('review-and-merge state in tasks', function (): void {
    it('shows the reviewed commits and merge state on the task and in tasks:status', function (): void {
        $project = review_merge_api_project(['review_and_merge' => true, 'merge_check' => 'Required checks']);
        $group = Task::topLevel()->create([
            'project_id' => $project->id, 'title' => 'Review #7: Login', 'brief' => 'Review it.', 'status' => TaskGroupStatus::Settling,
            'pr_url' => 'https://github.com/acme/shop/pull/7', 'pr_branch' => 'feature/login',
            'merge_status' => TaskMergeStatus::Waiting, 'merge_reason' => 'Check Required checks is still running.',
        ]);
        $final = Task::query()->create([
            'parent_id' => $group->id, 'position' => 1, 'title' => 'Final review', 'brief' => 'Review it all.',
            'status' => TaskStatus::Completed, 'type' => 'final_review',
        ]);
        TaskReviewedCommit::query()->create([
            'task_id' => $group->id, 'sha' => str_repeat('a', 40), 'source' => TaskReviewedCommitSource::PullRequestReview,
            'review_task_id' => $final->id, 'github_review_id' => 991,
        ]);
        $other = Task::topLevel()->create(['project_id' => review_merge_api_project(['slug' => 'other', 'repository_url' => 'https://github.com/acme/other.git'])->id, 'title' => 'Plain', 'brief' => 'No flow.', 'status' => TaskGroupStatus::Settling]);

        $this->getJson('/api/v1/task-groups/'.$group->id)
            ->assertOk()
            ->assertJsonPath('data.review_and_merge.enabled', true)
            ->assertJsonPath('data.review_and_merge.pr_branch', 'feature/login')
            ->assertJsonPath('data.review_and_merge.merge_status', 'waiting')
            ->assertJsonPath('data.review_and_merge.merge_reason', 'Check Required checks is still running.')
            ->assertJsonPath('data.review_and_merge.reviewed_commits.0.sha', str_repeat('a', 40))
            ->assertJsonPath('data.review_and_merge.reviewed_commits.0.source', 'pull_request_review')
            ->assertJsonPath('data.review_and_merge.reviewed_commits.0.review_task_id', $final->id)
            ->assertJsonPath('data.review_and_merge.reviewed_commits.0.github_review_id', 991)
            ->assertJsonPath('data.tasks.0.type', 'final_review');
        $this->getJson('/api/v1/task-groups/'.$other->id)
            ->assertOk()
            ->assertJsonPath('data.review_and_merge', null);
        $this->getJson('/api/v1/tasks/status')
            ->assertOk()
            ->assertJsonPath('data.merges', [[
                'id' => $group->id, 'project_id' => $project->id, 'project' => 'shop', 'project_code' => $project->code,
                'title' => 'Review #7: Login', 'status' => 'settling', 'pr_url' => 'https://github.com/acme/shop/pull/7',
                'pr_branch' => 'feature/login', 'merge_status' => 'waiting', 'merge_reason' => 'Check Required checks is still running.',
            ]]);
    });
});
