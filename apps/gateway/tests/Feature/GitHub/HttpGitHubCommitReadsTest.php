<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubComparisonStatus;
use App\Domain\GitHub\GitHubRepository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GreenCommitFixtures;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->repository = GitHubRepository::fromOrigin('https://github.com/nckrtl/orbit.git');
});

describe('HttpGitHubApi check runs', function (): void {
    it('reads every page and maps status and head_sha', function (): void {
        $pages = GreenCommitFixtures::checkRunPages();
        $sha = $pages[0]['check_runs'][0]['head_sha'];
        Http::fake(["https://api.github.com/repos/nckrtl/orbit/commits/{$sha}/check-runs*" => Http::sequence()
            ->push($pages[0])->push($pages[1])->push($pages[2])]);

        $runs = app(GitHubApi::class)->checkRuns('ghs_checks', $this->repository, $sha);

        expect($runs)->toHaveCount(15)
            ->and(array_unique(array_map(static fn (GitHubCheckRun $run): ?string => $run->headSha, $runs)))->toBe([$sha])
            ->and($runs[0]->name)->toBe('Required checks')
            ->and($runs[0]->status)->toBe('completed')
            ->and($runs[0]->conclusion)->toBe('success')
            ->and($runs[0]->passedOn($sha))->toBeTrue()
            ->and($runs[1]->conclusion)->toBe('skipped');
        Http::assertSentCount(3);
        foreach ([1, 2, 3] as $page) {
            Http::assertSent(static fn (Request $request): bool => GreenCommitFixtures::query($request) === ['per_page' => '100', 'page' => (string) $page]
                && $request->hasHeader('Authorization', 'Bearer ghs_checks'));
        }
    });

    it('sends the check name filter and keeps only runs of that exact name', function (): void {
        $page = GreenCommitFixtures::checkRunPages()[0];
        $sha = $page['check_runs'][0]['head_sha'];
        Http::fake(["https://api.github.com/repos/nckrtl/orbit/commits/{$sha}/check-runs*" => Http::response(['total_count' => 5] + $page)]);

        $runs = app(GitHubApi::class)->checkRuns('ghs_checks', $this->repository, $sha, 'Required checks');

        expect($runs)->toHaveCount(1)->and($runs[0]->id)->toBe(112604299032);
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), 'check_name=Required%20checks'));
    });

    it('fails when the list moves or ends before total_count', function (array $second): void {
        $pages = GreenCommitFixtures::checkRunPages();
        $sha = $pages[0]['check_runs'][0]['head_sha'];
        Http::fake(["https://api.github.com/repos/nckrtl/orbit/commits/{$sha}/check-runs*" => Http::sequence()
            ->push($pages[0])->push($second)->push($pages[2])]);

        expect(fn () => app(GitHubApi::class)->checkRuns('ghs_checks', $this->repository, $sha))
            ->toThrow(GitHubApiException::class);
    })->with([
        'count changed' => [['total_count' => 16] + GreenCommitFixtures::checkRunPages()[1]],
        'early empty page' => [['total_count' => 15, 'check_runs' => []]],
        'more rows than counted' => [['total_count' => 15, 'check_runs' => [
            ...GreenCommitFixtures::checkRunPages()[1]['check_runs'],
            ...GreenCommitFixtures::checkRunPages()[2]['check_runs'],
            ...GreenCommitFixtures::checkRunPages()[2]['check_runs'],
        ]]],
    ]);

    it('fails instead of truncating more than ten pages', function (): void {
        $run = GreenCommitFixtures::checkRunPages()[0]['check_runs'][0];
        Http::fake(['https://api.github.com/repos/nckrtl/orbit/commits/*/check-runs*' => Http::response([
            'total_count' => 1001, 'check_runs' => array_fill(0, 100, $run),
        ])]);

        expect(fn () => app(GitHubApi::class)->checkRuns('ghs_checks', $this->repository, $run['head_sha']))
            ->toThrow(GitHubApiException::class);
        Http::assertSentCount(10);
    });

    it('fails on a refused or unknown commit', function (): void {
        Http::fake(['https://api.github.com/repos/nckrtl/orbit/commits/*/check-runs*' => Http::response([
            'message' => 'No commit found for SHA: 0000000000000000000000000000000000000001', 'status' => '422',
        ], 422)]);

        expect(fn () => app(GitHubApi::class)->checkRuns('ghs_checks', $this->repository, '0000000000000000000000000000000000000001'))
            ->toThrow(GitHubApiException::class);
    });
});

describe('HttpGitHubApi branch commits', function (): void {
    it('lists the branch head by its full ref with parents in order', function (): void {
        $commits = GreenCommitFixtures::commits();
        Http::fake(['https://api.github.com/repos/nckrtl/orbit/commits?*' => Http::response($commits)]);

        $listed = app(GitHubApi::class)->branchCommits('ghs_contents', $this->repository, 'main');

        expect($listed)->toHaveCount(count($commits))
            ->and($listed[0]->sha)->toBe($commits[0]['sha'])
            ->and($listed[0]->firstParent())->toBe($commits[1]['sha'])
            ->and($listed[0]->parents)->toBe([$commits[1]['sha']]);
        Http::assertSent(static fn (Request $request): bool => GreenCommitFixtures::query($request) === ['sha' => 'refs/heads/main', 'per_page' => '100']
            && $request->hasHeader('Authorization', 'Bearer ghs_contents'));
    });

    it('fails on a missing branch or a malformed commit', function (int $status, array $body): void {
        Http::fake(['https://api.github.com/repos/nckrtl/orbit/commits?*' => Http::response($body, $status)]);

        expect(fn () => app(GitHubApi::class)->branchCommits('ghs_contents', $this->repository, 'main'))
            ->toThrow(GitHubApiException::class);
    })->with([
        'missing branch' => [404, ['message' => 'Not Found', 'status' => '404']],
        'abbreviated sha' => [200, [['sha' => 'c7f8ae627b0e', 'parents' => []]]],
        'malformed parent' => [200, [['sha' => str_repeat('a', 40), 'parents' => [['sha' => null]]]]],
        'object instead of list' => [200, ['sha' => str_repeat('a', 40), 'parents' => []]],
    ]);
});

describe('HttpGitHubApi commit comparison', function (): void {
    it('reads each real comparison status', function (string $name, GitHubComparisonStatus $status, bool $descends): void {
        $comparison = GreenCommitFixtures::comparison($name);
        $base = $comparison['base_commit']['sha'];
        Http::fake(['https://api.github.com/repos/nckrtl/orbit/compare/*' => Http::response($comparison)]);

        $read = app(GitHubApi::class)->compareCommits('ghs_contents', $this->repository, $base, str_repeat('b', 40));

        expect($read->status)->toBe($status)
            ->and($read->baseSha)->toBe($base)
            ->and($read->mergeBaseSha)->toBe($comparison['merge_base_commit']['sha'])
            ->and($read->headDescendsFromBase())->toBe($descends);
        Http::assertSent(static fn (Request $request): bool => str_starts_with($request->url(), "https://api.github.com/repos/nckrtl/orbit/compare/{$base}...".str_repeat('b', 40).'?')
            && GreenCommitFixtures::query($request) === ['per_page' => '1']);
    })->with([
        ['ahead', GitHubComparisonStatus::Ahead, true],
        ['behind', GitHubComparisonStatus::Behind, false],
        ['identical', GitHubComparisonStatus::Identical, false],
        ['diverged', GitHubComparisonStatus::Diverged, false],
    ]);

    it('fails when GitHub answers about another base or an unknown status', function (array $changes): void {
        $comparison = array_replace_recursive(GreenCommitFixtures::comparison('ahead'), $changes);
        Http::fake(['https://api.github.com/repos/nckrtl/orbit/compare/*' => Http::response($comparison)]);
        $base = GreenCommitFixtures::comparison('ahead')['base_commit']['sha'];

        expect(fn () => app(GitHubApi::class)->compareCommits('ghs_contents', $this->repository, $base, str_repeat('b', 40)))
            ->toThrow(GitHubApiException::class);
    })->with([
        'other base' => [['base_commit' => ['sha' => str_repeat('c', 40)]]],
        'unknown status' => [['status' => 'unrelated']],
        'missing merge base' => [['merge_base_commit' => ['sha' => '']]],
    ]);

    it('fails when a commit is unknown', function (): void {
        Http::fake(['https://api.github.com/repos/nckrtl/orbit/compare/*' => Http::response(['message' => 'Not Found', 'status' => '404'], 404)]);

        expect(fn () => app(GitHubApi::class)->compareCommits('ghs_contents', $this->repository, str_repeat('a', 40), str_repeat('b', 40)))
            ->toThrow(GitHubApiException::class);
    });
});
