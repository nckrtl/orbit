<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubReview;
use App\Domain\GitHub\GitHubReviewState;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\CoderSettleNotifier;
use App\Domain\Tasks\TaskBaseBranchFetcher;
use App\Domain\Tasks\TaskBranchUpdate;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGitHubReviewFeedback;
use App\Domain\Tasks\TaskGitHubReviewObservations;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPullRequestCheck;
use App\Domain\Tasks\TaskPullRequestHealth;
use App\Domain\Tasks\TaskPullRequestReviewWatcher;
use App\Domain\Tasks\TaskPullRequestUpdater;
use App\Domain\Tasks\TaskPullRequestWatcher;
use App\Domain\Tasks\TaskReviewReadStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Infrastructure\Tasks\HttpTaskPullRequestWatcher;
use App\Models\Node;
use App\Models\Task;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Domain\Tasks\ApprovalObservationFixtures as Fixtures;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Support\FakeTaskPullRequestReviewWatcher;

use function Pest\Laravel\mock;

/** @param list<GitHubReview>|null $reviews
 * @return array{Task, FakeTaskPullRequestReviewWatcher}
 */
function consumption_group(?array $reviews = null, ?TaskPullRequestHealth $health = null): array
{
    config(['orbit.tasks.github_reviewers' => ['acme/orbit' => [42, 7]]]);
    $group = Fixtures::group();
    $group->project->update(['task_check' => 'composer check']);
    Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'Operator work', 'brief' => 'Done.', 'status' => TaskStatus::Completed]);
    $source = new FakeTaskPullRequestReviewWatcher(Fixtures::observation($reviews ?? [Fixtures::review(state: GitHubReviewState::ChangesRequested)]), DB::transactionLevel());
    app()->instance(TaskPullRequestReviewWatcher::class, $source);
    mock(TaskPullRequestWatcher::class)->shouldReceive('health')->andReturn($health ?? new TaskPullRequestHealth('open', headSha: 'abc123'));
    // Fail after the atomic append. A later tick must retry this same todo, not consume again.
    mock(TaskBaseBranchFetcher::class)->shouldReceive('fetchForTurn')->andThrow(new RuntimeException('Disposable workspace unavailable.'));
    app(TaskExtensionState::class)->enable();

    return [$group, $source];
}

function consumption_fixup(Task $group, string $identity, ?string $head = null): Task
{
    return Task::query()->create(['parent_id' => $group->id, 'position' => ((int) $group->tasks()->max('position')) + 1,
        'title' => 'Earlier repair', 'brief' => 'Done.', 'status' => TaskStatus::Cancelled,
        'fixup_problem' => $identity, 'fixup_head_sha' => $head]);
}

describe('once-only GitHub review consumption', function (): void {
    it('atomically snapshots one checked fixup and retries its stranded todo after a restart', function (): void {
        [$group, $source] = consumption_group();
        app(TaskScheduler::class)->tick();
        $fixup = $group->tasks()->whereNotNull('fixup_problem')->sole();
        $row = DB::table('task_github_review_consumptions')->sole();
        expect($fixup->status)->toBe(TaskStatus::Todo)
            ->and($fixup->fixup_problem)->toBe('review:42')
            ->and(array_column($fixup->deliverables, 'id'))->toBe(['review-findings', 'project-check'])
            ->and($row->fixup_id)->toBe($fixup->id)
            ->and($row->packet)->toBe($fixup->brief)
            ->and($row->digest)->toHaveLength(64);
        app()->forgetInstance(TaskScheduler::class);
        app(TaskScheduler::class)->tick();
        expect($group->tasks()->whereNotNull('fixup_problem')->count())->toBe(1)
            ->and(DB::table('task_github_review_consumptions')->count())->toBe(1)
            ->and($source->candidates)->toBe(1);
    });

    it('rejects API deletion outside backlog with HTTP 409 and retains cancelled consumption', function (): void {
        [$group] = consumption_group();
        app(TaskScheduler::class)->tick();
        $fixup = $group->tasks()->whereNotNull('fixup_problem')->sole();
        $fixup->update(['status' => TaskStatus::Cancelled]);
        $gateway = $this->markAsGateway(Node::query()->create([
            'name' => 'consumption-gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
            'public_ssh_host' => '192.0.2.91', 'wireguard_ip' => '10.44.0.91',
        ]));
        $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
        $this->deleteJson("/api/v1/task-groups/{$group->id}/tasks/{$fixup->id}")
            ->assertStatus(409)->assertJsonPath('error.code', 'tasks.not_in_backlog');
        expect($fixup->fresh()->status)->toBe(TaskStatus::Cancelled)
            ->and(DB::table('task_github_review_consumptions')->value('fixup_id'))->toBe($fixup->id);
    });

    it('rolls back both writes on a pre-commit crash and retries without losing findings', function (): void {
        [$group] = consumption_group();
        DB::unprepared("CREATE TEMP TRIGGER reject_consumption BEFORE INSERT ON task_github_review_consumptions BEGIN SELECT RAISE(ABORT, 'fixture crash'); END");
        try {
            expect(fn () => app(TaskScheduler::class)->tick())->toThrow(QueryException::class);
            expect($group->tasks()->whereNotNull('fixup_problem')->exists())->toBeFalse()
                ->and(DB::table('task_github_review_consumptions')->exists())->toBeFalse();
        } finally {
            DB::unprepared('DROP TRIGGER reject_consumption');
        }
        app(TaskScheduler::class)->tick();
        expect(DB::table('task_github_review_consumptions')->count())->toBe(1);
    });

    it('deduplicates two interleaved ticks that both read the eligible source', function (): void {
        [$group, $source] = consumption_group();
        $source->beforeAppend = fn () => app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();
        expect($source->candidates)->toBe(2)
            ->and($group->tasks()->whereNotNull('fixup_problem')->count())->toBe(1)
            ->and(DB::table('task_github_review_consumptions')->count())->toBe(1);
    });

    it('rechecks local eligibility after final external reads', function (string $change): void {
        [$group, $source] = consumption_group();
        $source->beforeAppend = function () use ($change, $group): void {
            match ($change) {
                'trust' => config(['orbit.tasks.github_reviewers' => ['acme/orbit' => [7]]]),
                'status' => $group->update(['status' => TaskGroupStatus::Cancelled]),
                'todo' => Task::query()->create(['parent_id' => $group->id, 'position' => 2, 'title' => 'Human override', 'brief' => 'Do this.', 'status' => TaskStatus::Todo]),
                'cap' => [consumption_fixup($group, 'review:42'), consumption_fixup($group, 'review:42')],
                'head' => consumption_fixup($group, 'check:Gateway', 'abc123'),
                default => $group->update(['pr_url' => 'https://github.com/acme/orbit/pull/43']),
            };
        };
        app(TaskScheduler::class)->tick();
        expect(DB::table('task_github_review_consumptions')->exists())->toBeFalse();
    })->with(['trust', 'status', 'todo', 'cap', 'head', 'target']);

    it('does not consume a source changed during revalidation', function (): void {
        [, $source] = consumption_group();
        $source->beforeAppend = function () use ($source): void {
            $source->candidateStatus = TaskReviewReadStatus::Changed;
        };
        app(TaskScheduler::class)->tick();
        expect(DB::table('task_github_review_consumptions')->exists())->toBeFalse();
    });

    it('retains consumption through cancellation edits dismissal renames head changes and operator resets', function (string $change): void {
        [$group, $source] = consumption_group();
        app(TaskScheduler::class)->tick();
        $fixup = $group->tasks()->whereNotNull('fixup_problem')->sole();
        $packet = DB::table('task_github_review_consumptions')->value('packet');
        $fixup->update(['status' => TaskStatus::Cancelled, 'brief' => 'Authorized human override.']);
        if ($change === 'reset') {
            Task::query()->create(['parent_id' => $group->id, 'position' => 3, 'title' => 'Operator reset', 'brief' => 'Done.', 'status' => TaskStatus::Completed]);
        }
        $source->observation = Fixtures::observation([Fixtures::review(state: $change === 'dismissal' ? GitHubReviewState::Dismissed : GitHubReviewState::ChangesRequested,
            head: $change === 'head' ? 'next-head' : 'abc123', login: $change === 'rename' ? 'renamed' : 'source-name')], head: $change === 'head' ? 'next-head' : 'abc123');
        app(TaskScheduler::class)->tick();
        expect(DB::table('task_github_review_consumptions')->count())->toBe(1)
            ->and(DB::table('task_github_review_consumptions')->value('packet'))->toBe($packet)
            ->and($group->tasks()->whereNotNull('fixup_problem')->count())->toBe(1)
            ->and($fixup->fresh()->brief)->toBe('Authorized human override.');
    })->with(['cancel', 'edit', 'dismissal', 'rename', 'head', 'reset']);

    it('retains an orphan charge without double counting extant work and resets only its window', function (): void {
        [$group, $source] = consumption_group();
        app(TaskScheduler::class)->tick();
        $fixup = $group->tasks()->whereNotNull('fixup_problem')->sole();
        $fixup->delete(); // Defensive unsupported cleanup, not the API deletion flow.
        expect(DB::table('task_github_review_consumptions')->value('fixup_id'))->toBeNull();
        consumption_fixup($group, 'review:42');
        $source->observation = Fixtures::observation([Fixtures::review(102, GitHubReviewState::ChangesRequested)]);
        app(TaskScheduler::class)->tick();
        expect(DB::table('task_github_review_consumptions')->count())->toBe(1)
            ->and($group->fresh()->assistance_reason)->toContain('cap');
        Task::query()->create(['parent_id' => $group->id, 'position' => 4, 'title' => 'Operator reset', 'brief' => 'Done.', 'status' => TaskStatus::Completed]);
        app(TaskScheduler::class)->tick();
        expect(DB::table('task_github_review_consumptions')->count())->toBe(2);
    });

    it('skips a capped reviewer and shares the total budget with CI and conflict work', function (): void {
        [$group] = consumption_group([Fixtures::review(state: GitHubReviewState::ChangesRequested), Fixtures::review(102, GitHubReviewState::ChangesRequested, reviewer: 7)]);
        consumption_fixup($group, 'review:42');
        consumption_fixup($group, 'review:42');
        app(TaskScheduler::class)->tick();
        expect($group->tasks()->where('fixup_problem', 'review:7')->count())->toBe(1)
            ->and(DB::table('task_github_review_consumptions')->count())->toBe(1);
    });

    it('waits for pending and infrastructure checks but gives conflict and genuine CI priority', function (string $problem, ?string $expected): void {
        mock(TaskPullRequestUpdater::class)->shouldReceive('updateBranch')->andReturn(TaskBranchUpdate::Conflict);
        $health = match ($problem) {
            'conflict' => new TaskPullRequestHealth('open', ['Conflict'], baseRef: 'main', conflicts: true, headSha: 'abc123'),
            'failure' => new TaskPullRequestHealth('open', ['Failure'], failedChecks: [new TaskPullRequestCheck('Gateway', null)], headSha: 'abc123'),
            'pending' => new TaskPullRequestHealth('open', headSha: 'abc123', checksPending: true, checksYoungPending: true),
            default => new TaskPullRequestHealth('open', ['Cancelled'], headSha: 'abc123', infrastructureChecks: [new TaskPullRequestCheck('Gateway', null)]),
        };
        [$group] = consumption_group(health: $health);
        app(TaskScheduler::class)->tick();
        expect(DB::table('task_github_review_consumptions')->exists())->toBeFalse()
            ->and($group->tasks()->whereNotNull('fixup_problem')->value('fixup_problem'))->toBe($expected);
    })->with([['conflict', 'conflict:main'], ['failure', 'check:Gateway'], ['pending', null], ['infrastructure', null]]);

    it('backs off review reads then recovers only its own assistance', function (): void {
        $this->freezeTime();
        [$group, $source] = consumption_group();
        $source->observation = Fixtures::observation(read: TaskReviewReadStatus::Unreadable);
        foreach ([1, 2, 5, 10, 30] as $minutes) {
            app(TaskScheduler::class)->tick();
            $reads = $source->reads;
            app(TaskScheduler::class)->tick();
            expect($source->reads)->toBe($reads);
            $this->travel($minutes)->minutes();
        }
        app(TaskScheduler::class)->tick();
        expect($group->fresh()->assistance_reason)->toContain('source remains unreadable');
        $this->travel(30)->minutes();
        $source->observation = Fixtures::observation([Fixtures::review()]);
        app(TaskScheduler::class)->tick();
        expect($group->fresh()->assistance_requested)->toBeFalse()
            ->and((new TaskGitHubReviewObservations)->report($group)['records'])->toHaveCount(1)
            ->and($group->fresh()->status)->toBe(TaskGroupStatus::Settling)
            ->and(DB::table('task_github_review_consumptions')->exists())->toBeFalse();
        $group->update(['assistance_requested' => true, 'assistance_kind' => 'failure', 'assistance_reason' => 'Unrelated operator assistance.']);
        app(TaskScheduler::class)->tick();
        expect($group->fresh()->assistance_reason)->toBe('Unrelated operator assistance.');
    });

    it('preserves findings and revalidation failure history across complete list observations', function (string $operation): void {
        $this->freezeTime();
        [$group, $source] = consumption_group();
        if ($operation === 'findings') {
            $source->candidateStatus = TaskReviewReadStatus::Unreadable;
        } else {
            $source->validationStatus = TaskReviewReadStatus::Unreadable;
        }
        foreach ([1, 2, 5, 10, 30] as $minutes) {
            app(TaskScheduler::class)->tick();
            $attempts = $source->candidates;
            app(TaskScheduler::class)->tick();
            expect($source->candidates)->toBe($attempts);
            $this->travel($minutes)->minutes();
        }
        app(TaskScheduler::class)->tick();
        expect($source->candidates)->toBe(6)
            ->and($group->fresh()->assistance_reason)->toContain('source remains unreadable (review:101:'.$operation.')')
            ->and(DB::table('task_github_review_consumptions')->exists())->toBeFalse()
            ->and((new TaskGitHubReviewObservations)->report($group)['scan']['read_status'])->toBe('complete');
        $reason = $group->fresh()->assistance_reason;
        app(TaskScheduler::class)->tick(); // Another successful list is not findings recovery.
        expect($group->fresh()->assistance_reason)->toBe($reason);
        $this->travel(30)->minutes();
        $source->candidateStatus = TaskReviewReadStatus::Complete;
        $source->validationStatus = TaskReviewReadStatus::Complete;
        app(TaskScheduler::class)->tick();
        expect($group->fresh()->assistance_requested)->toBeFalse()
            ->and(app(TaskGitHubReviewFeedback::class)->causes($group))->toBe([])
            ->and(DB::table('task_github_review_consumptions')->count())->toBe(1);
    })->with(['findings', 'validation']);

    it('reports a missing consumed fixup once on a healthy PR without cap pressure', function (): void {
        [$group, $source] = consumption_group();
        app(TaskScheduler::class)->tick();
        $group->tasks()->whereNotNull('fixup_problem')->sole()->delete();
        $source->observation = Fixtures::observation([Fixtures::review()]);
        mock(CoderSettleNotifier::class)->shouldReceive('assistance')->once();
        app(TaskScheduler::class)->tick();
        $reason = $group->fresh()->assistance_reason;
        expect($reason)->toContain('acme/orbit PR #42 review #101 is missing')
            ->and(DB::table('task_github_review_consumptions')->count())->toBe(1)
            ->and($group->tasks()->whereNotNull('fixup_problem')->exists())->toBeFalse();
        app(TaskScheduler::class)->tick();
        expect($group->fresh()->assistance_reason)->toBe($reason);
    });

    it('retains missing-fixup evidence behind unrelated assistance and presents it after that request resolves', function (): void {
        [$group, $source] = consumption_group();
        app(TaskScheduler::class)->tick();
        $group->tasks()->whereNotNull('fixup_problem')->sole()->delete();
        $source->observation = Fixtures::observation([]);
        $group->update(['assistance_requested' => true, 'assistance_kind' => 'direction', 'assistance_reason' => 'An unrelated operator question.', 'assistance_question' => 'Which scope?']);
        app(TaskScheduler::class)->tick();
        expect($group->fresh()->assistance_reason)->toBe('An unrelated operator question.')
            ->and($group->fresh()->assistance_question)->toBe('Which scope?')
            ->and(array_values(app(TaskGitHubReviewFeedback::class)->causes($group))[0])->toContain('review #101 is missing');
        $group->update(['assistance_requested' => false, 'assistance_reason' => null, 'assistance_question' => null, 'assistance_kind' => null]);
        app(TaskScheduler::class)->tick();
        expect($group->fresh()->assistance_reason)->toContain('review #101 is missing')
            ->and(DB::table('task_github_review_consumptions')->count())->toBe(1);
    });

    it('recovers one review source without deleting another unresolved cause', function (): void {
        [$group, $source] = consumption_group();
        app(TaskScheduler::class)->tick();
        $group->tasks()->whereNotNull('fixup_problem')->sole()->delete();
        app(TaskGitHubReviewFeedback::class)->change($group, ['list' => 'The trusted review source remains unreadable (list). Restore access.']);
        $source->observation = Fixtures::observation([]);
        app(TaskScheduler::class)->tick();
        expect($group->fresh()->assistance_reason)->toContain('review #101 is missing')
            ->and($group->fresh()->assistance_reason)->not->toContain('source remains unreadable');
    });

    it('asks immediately for invalid trust empty findings and oversized packets without consuming', function (string $problem): void {
        $this->freezeTime();
        $review = Fixtures::review(state: GitHubReviewState::ChangesRequested);
        if ($problem !== 'trust') {
            $review = new GitHubReview($review->id, $review->reviewerId, $review->reviewerLogin, $review->state,
                $review->commitId, $review->submittedAt, $review->url, $problem === 'empty' ? ' ' : str_repeat('x', 65_536));
        }
        [$group, $source] = consumption_group([$review]);
        if ($problem === 'trust') {
            $source->observation = Fixtures::observation(read: TaskReviewReadStatus::InvalidTrust);
        }
        app(TaskScheduler::class)->tick();
        expect($group->fresh()->assistance_reason)->toStartWith('GitHub review feedback: ')
            ->and(DB::table('task_github_review_consumptions')->exists())->toBeFalse();
        $reason = $group->fresh()->assistance_reason;
        app(TaskScheduler::class)->tick();
        expect($group->fresh()->assistance_reason)->toBe($reason);
    })->with(['trust', 'empty', 'oversize']);

    it('repairs CI despite persistent review-read assistance and retains unrelated assistance on recovery', function (): void {
        $this->freezeTime();
        [$group, $source] = consumption_group(health: new TaskPullRequestHealth('open', ['Failure'], failedChecks: [new TaskPullRequestCheck('Gateway', null)], headSha: 'abc123'));
        $group->update(['assistance_requested' => true, 'assistance_kind' => 'failure', 'assistance_reason' => 'GitHub review feedback: The trusted review source remains unreadable. Restore review-read access.']);
        $source->observation = Fixtures::observation(read: TaskReviewReadStatus::Unreadable);
        app(TaskScheduler::class)->tick();
        expect($group->tasks()->where('fixup_problem', 'check:Gateway')->count())->toBe(1)
            ->and(DB::table('task_github_review_consumptions')->exists())->toBeFalse();
    });

    it('asks immediately for bounded review-list or comment pagination overflow without partial consumption', function (string $operation): void {
        $review = array_replace(GitHubTestSupport::review(), ['state' => 'CHANGES_REQUESTED']);
        [$group] = consumption_group(health: new TaskPullRequestHealth('open', headSha: $review['commit_id']));
        GitHubTestSupport::storeApp();
        app()->bind(TaskPullRequestReviewWatcher::class, HttpTaskPullRequestWatcher::class);
        $comment = GitHubTestSupport::comment();
        $path = 'https://api.github.com/repos/acme/orbit/pulls/42/reviews';
        Http::preventStrayRequests();
        Http::fake([
            'https://api.github.com/repos/acme/orbit/installation' => Http::response(['id' => 9]),
            'https://api.github.com/app/installations/9/access_tokens' => Http::response(['token' => 'ghs_read'], 201),
            'https://api.github.com/repos/acme/orbit/pulls/42' => Http::response([
                'state' => 'open', 'merged' => false, 'mergeable' => true, 'head' => ['sha' => $review['commit_id']], 'base' => ['ref' => 'main'],
            ]),
            $path.'?*' => function ($request) use ($operation, $review, $path) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $page = (int) $query['page'];

                return Http::response([array_replace($review, ['id' => $operation === 'list' ? 100 + $page : 101])], 200,
                    $operation === 'list' ? ['Link' => '<'.$path.'?per_page=100&page='.($page + 1).'>; rel="next"'] : []);
            },
            $path.'/101' => Http::response($review),
            $path.'/101/comments?*' => function ($request) use ($comment, $path) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $page = (int) $query['page'];

                return Http::response([array_replace($comment, ['id' => 1000 + $page])], 200,
                    ['Link' => '<'.$path.'/101/comments?per_page=100&page='.($page + 1).'>; rel="next"']);
            },
        ]);
        app(TaskScheduler::class)->tick();
        expect($group->fresh()->assistance_reason)->toContain('pagination limit')
            ->and(DB::table('task_github_review_consumptions')->exists())->toBeFalse()
            ->and($group->tasks()->whereNotNull('fixup_problem')->exists())->toBeFalse();
        $causes = app(TaskGitHubReviewFeedback::class)->causes($group);
        expect(array_keys($causes))->toBe([$operation === 'list' ? 'list' : 'review:101:findings']);
        if ($operation === 'list') {
            expect((new TaskGitHubReviewObservations)->report($group)['scan']['reason'])->toBe('overflow');
        }
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), $operation === 'list' ? 'page=11' : 'page=6'));
        Http::assertNotSent(fn ($request): bool => $request->method() !== 'GET' && ! str_ends_with($request->url(), '/access_tokens'));
    })->with(['list', 'comments']);

    it('shares the group budget including orphan charges with CI work', function (): void {
        [$group, $source] = consumption_group();
        app(TaskScheduler::class)->tick();
        $group->tasks()->whereNotNull('fixup_problem')->sole()->delete();
        consumption_fixup($group, 'check:Gateway');
        consumption_fixup($group, 'conflict:main');
        $source->observation = Fixtures::observation([Fixtures::review(102, GitHubReviewState::ChangesRequested, reviewer: 7)]);
        app(TaskScheduler::class)->tick();
        expect(DB::table('task_github_review_consumptions')->count())->toBe(1)
            ->and($group->tasks()->where('fixup_problem', 'review:7')->exists())->toBeFalse();
    });

    it('does not double count extant consumed tasks against a different reviewer', function (): void {
        [$group, $source] = consumption_group();
        app(TaskScheduler::class)->tick();
        $group->tasks()->whereNotNull('fixup_problem')->sole()->update(['status' => TaskStatus::Cancelled, 'fixup_head_sha' => 'old-head']);
        consumption_fixup($group, 'check:Gateway');
        $source->observation = Fixtures::observation([Fixtures::review(102, GitHubReviewState::ChangesRequested, reviewer: 7)]);
        app(TaskScheduler::class)->tick();
        expect(DB::table('task_github_review_consumptions')->count())->toBe(2);
    });

    it('does not append on a closed or merged PR', function (string $state): void {
        [$group] = consumption_group(health: new TaskPullRequestHealth($state, headSha: 'abc123'));
        app(TaskScheduler::class)->tick();
        expect(DB::table('task_github_review_consumptions')->exists())->toBeFalse();
    })->with(['closed', 'merged']);
});
