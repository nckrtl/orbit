<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitHubReviewEvent;
use App\Domain\GitHub\RequiredCheckState;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->repository = GitHubRepository::fromOrigin('https://github.com/nckrtl/orbit.git');
});

/** @return array<string, mixed> */
function review_merge_pull(int $number, array $overrides = []): array
{
    return array_replace_recursive([
        'number' => $number,
        'html_url' => 'https://github.com/nckrtl/orbit/pull/'.$number,
        'title' => 'Change '.$number,
        'body' => 'Why '.$number,
        'draft' => false,
        'user' => ['id' => 1234, 'login' => 'nckrtl'],
        'head' => ['ref' => 'cursor/change-'.$number, 'sha' => str_repeat('a', 40), 'repo' => ['full_name' => 'nckrtl/orbit']],
        'base' => ['ref' => 'main'],
    ], $overrides);
}

describe('HttpGitHubApi review and merge', function (): void {
    it('lists open pull requests oldest first and maps the author, head, and draft state', function (): void {
        Http::fake(['https://api.github.com/repos/nckrtl/orbit/pulls*' => Http::response([
            review_merge_pull(7),
            review_merge_pull(8, ['draft' => true, 'head' => ['repo' => ['full_name' => 'fork/orbit']]]),
        ])]);

        $pulls = app(GitHubApi::class)->openPullRequests('ghs_read', $this->repository);

        expect($pulls)->toHaveCount(2)
            ->and($pulls[0]->number)->toBe(7)
            ->and($pulls[0]->authorId)->toBe(1234)
            ->and($pulls[0]->authorLogin)->toBe('nckrtl')
            ->and($pulls[0]->headRef)->toBe('cursor/change-7')
            ->and($pulls[0]->headSha)->toBe(str_repeat('a', 40))
            ->and($pulls[0]->headRepository)->toBe('nckrtl/orbit')
            ->and($pulls[0]->baseRef)->toBe('main')
            ->and($pulls[0]->draft)->toBeFalse()
            ->and($pulls[1]->draft)->toBeTrue()
            ->and($pulls[1]->headRepository)->toBe('fork/orbit');
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), 'state=open')
            && str_contains($request->url(), 'direction=asc') && $request->hasHeader('Authorization', 'Bearer ghs_read'));
    });

    it('reads at most three full pages', function (): void {
        Http::fake(['https://api.github.com/repos/nckrtl/orbit/pulls*' => Http::response(array_map(static fn (int $number): array => review_merge_pull($number), range(1, 100)))]);

        expect(app(GitHubApi::class)->openPullRequests('ghs_read', $this->repository))->toHaveCount(300);
        Http::assertSentCount(3);
    });

    it('fails the whole list on a malformed pull request', function (): void {
        Http::fake(['https://api.github.com/repos/nckrtl/orbit/pulls*' => Http::response([review_merge_pull(7, ['html_url' => 'https://github.com/other/repo/pull/7'])])]);

        expect(fn () => app(GitHubApi::class)->openPullRequests('ghs_read', $this->repository))->toThrow(GitHubApiException::class);
    });

    it('submits a review for the exact commit and returns its id', function (): void {
        Http::fake(['https://api.github.com/repos/nckrtl/orbit/pulls/7/reviews' => Http::response(['id' => 991], 200)]);

        expect(app(GitHubApi::class)->submitReview('ghs_write', $this->repository, 7, str_repeat('b', 40), GitHubReviewEvent::Approve, 'Reviewed.'))->toBe(991);
        Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
            && $request['commit_id'] === str_repeat('b', 40) && $request['event'] === 'APPROVE' && $request['body'] === 'Reviewed.');
    });

    it('names GitHub\'s refusal of a review', function (): void {
        Http::fake(['https://api.github.com/repos/nckrtl/orbit/pulls/7/reviews' => Http::response(['message' => 'Can not approve your own pull request'], 422)]);

        expect(fn () => app(GitHubApi::class)->submitReview('ghs_write', $this->repository, 7, str_repeat('b', 40), GitHubReviewEvent::Approve, 'Reviewed.'))
            ->toThrow(GitHubApiException::class, 'GitHub refused the review (422): Can not approve your own pull request.');
    });

    it('merges with a merge commit only while the head is the reviewed commit', function (): void {
        Http::fake(['https://api.github.com/repos/nckrtl/orbit/pulls/7/merge' => Http::response(['merged' => true, 'sha' => str_repeat('c', 40), 'message' => 'Pull Request successfully merged'])]);

        $result = app(GitHubApi::class)->mergePullRequest('ghs_write', $this->repository, 7, str_repeat('b', 40));

        expect($result->merged)->toBeTrue()->and($result->sha)->toBe(str_repeat('c', 40));
        Http::assertSent(static fn (Request $request): bool => $request->method() === 'PUT'
            && $request['sha'] === str_repeat('b', 40) && $request['merge_method'] === 'merge');
    });

    it('returns a refused merge as a result and fails on a server error', function (): void {
        Http::fake(['https://api.github.com/repos/nckrtl/orbit/pulls/7/merge' => Http::sequence()
            ->push(['message' => 'Head branch was modified. Review and try the merge again.'], 409)
            ->push(['message' => 'Server Error'], 502)]);

        $refused = app(GitHubApi::class)->mergePullRequest('ghs_write', $this->repository, 7, str_repeat('b', 40));

        expect($refused->merged)->toBeFalse()
            ->and($refused->status)->toBe(409)
            ->and($refused->message)->toBe('Head branch was modified. Review and try the merge again')
            ->and(fn () => app(GitHubApi::class)->mergePullRequest('ghs_write', $this->repository, 7, str_repeat('b', 40)))->toThrow(GitHubApiException::class);
    });
});

describe('RequiredCheckState', function (): void {
    $sha = str_repeat('a', 40);
    $run = static fn (array $overrides = []): GitHubCheckRun => new GitHubCheckRun(...[
        'name' => 'Required checks', 'conclusion' => 'success', 'url' => null, 'status' => 'completed', 'headSha' => $sha, 'appSlug' => 'github-actions', ...$overrides,
    ]);

    it('applies the green-commit rules to one head', function (array $runs, RequiredCheckState $state) use ($sha): void {
        expect(RequiredCheckState::of($runs, $sha, 'Required checks'))->toBe($state);
    })->with([
        'passed' => fn () => [[$run()], RequiredCheckState::Passed],
        'other checks do not count' => fn () => [[$run(['name' => 'Lint'])], RequiredCheckState::Missing],
        'none' => fn () => [[], RequiredCheckState::Missing],
        'running' => fn () => [[$run(['status' => 'in_progress', 'conclusion' => null])], RequiredCheckState::Pending],
        'failed' => fn () => [[$run(['conclusion' => 'failure'])], RequiredCheckState::Failed],
        'another App' => fn () => [[$run(), $run(['appSlug' => 'impostor'])], RequiredCheckState::Failed],
        'another head' => fn () => [[$run(['headSha' => str_repeat('b', 40)])], RequiredCheckState::Failed],
    ]);
});
