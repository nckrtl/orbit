<?php

declare(strict_types=1);

use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GreenCommit;
use App\Domain\GitHub\GreenCommitResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\GitHub\GitHubTestSupport;
use Tests\Feature\GitHub\GreenCommitFixtures;

/**
 * Serves the real main history. Check runs come from the captured response of each commit unless
 * `$checks` replaces it. Compare answers with the named captured relation, about the requested base.
 *
 * @param  array<string, array<string, mixed>>  $checks
 * @param  list<array<string, mixed>>|null  $commits
 */
function green_fake(array $checks = [], string $comparison = 'ahead', ?array $commits = null, bool $alignMergeBase = true): void
{
    Http::fake(static function (Request $request) use ($checks, $comparison, $commits, $alignMergeBase) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            $path === '/repos/nckrtl/orbit/installation' => Http::response(['id' => 9]),
            $path === '/app/installations/9/access_tokens' => Http::response(['token' => match ($request->data()['permissions']) {
                ['contents' => 'read'] => 'ghs_contents',
                ['checks' => 'read'] => 'ghs_checks',
            }], 201),
            $path === '/repos/nckrtl/orbit/commits' => Http::response($commits ?? GreenCommitFixtures::commits()),
            preg_match('#\A/repos/nckrtl/orbit/commits/([0-9a-f]{40})/check-runs\z#', $path, $match) === 1 => Http::response($checks[$match[1]] ?? GreenCommitFixtures::requiredChecks($match[1])),
            preg_match('#\A/repos/nckrtl/orbit/compare/([0-9a-f]{40})\.\.\.[0-9a-f]{40}\z#', $path, $match) === 1 => Http::response(array_replace_recursive(GreenCommitFixtures::comparison($comparison), ['base_commit' => ['sha' => $match[1]]]
                    + ($comparison === 'ahead' && $alignMergeBase ? ['merge_base_commit' => ['sha' => $match[1]]] : []))),
        };
    });
}

/** @param  list<string>  $failed */
function green_resolve(string $deployed, array $failed = [], string $branch = 'main', string $check = 'Required checks'): ?GreenCommit
{
    return app(GreenCommitResolver::class)->resolve(
        GitHubRepository::fromOrigin('https://github.com/nckrtl/orbit.git'), $branch, $check, $deployed, $failed,
    );
}

function green_check_reads(): int
{
    return Http::recorded(static fn (Request $request): bool => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/check-runs'))->count();
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    GitHubTestSupport::storeApp();
    [$this->tip, $this->second, $this->third, $this->fourth, $this->unchecked, $this->sixth] = GreenCommitFixtures::mainShas();
});

describe('GreenCommitResolver', function (): void {
    it('releases the newest green descendant and proves it with its Required checks run', function (): void {
        green_fake();

        $green = green_resolve($this->third);

        expect($green?->sha)->toBe($this->tip)
            ->and($green?->checkRun->name)->toBe('Required checks')
            ->and($green?->checkRun->headSha)->toBe($this->tip)
            ->and($green?->checkRun->url)->toStartWith('https://github.com/nckrtl/orbit/actions/runs/');
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/access_tokens')
            && $request->data() === ['repositories' => ['orbit'], 'permissions' => ['contents' => 'read']]);
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/access_tokens')
            && $request->data() === ['repositories' => ['orbit'], 'permissions' => ['checks' => 'read']]);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), "/commits/{$this->tip}/check-runs?")
            && GreenCommitFixtures::query($request)['check_name'] === 'Required checks' && $request->hasHeader('Authorization', 'Bearer ghs_checks'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), "/compare/{$this->third}...{$this->tip}?")
            && $request->hasHeader('Authorization', 'Bearer ghs_contents'));
        expect(green_check_reads())->toBe(1);
    });

    it('does nothing more when the branch head is deployed', function (): void {
        green_fake();

        expect(green_resolve($this->tip))->toBeNull()
            ->and(green_check_reads())->toBe(0);
        Http::assertNotSent(static fn (Request $request): bool => ($request->data()['permissions'] ?? null) === ['checks' => 'read']
            || str_contains($request->url(), '/compare/'));
    });

    it('passes over a newer commit whose Required checks did not pass', function (string $state): void {
        green_fake([$this->tip => GreenCommitFixtures::requiredChecksAs($state, $this->tip)]);

        expect(green_resolve($this->third)?->sha)->toBe($this->second)
            ->and(green_check_reads())->toBe(2);
    })->with([
        'queued' => 'queued',
        'still running' => 'in_progress',
        'failed' => 'failure',
        'cancelled upstream' => 'failure_after_cancel',
        'not started yet' => 'none',
    ]);

    it('ships a Required checks success even when GitHub reports its workflow run as cancelled', function (): void {
        green_fake([$this->tip => GreenCommitFixtures::requiredChecksAs('success_in_cancelled_workflow', $this->tip)]);

        expect(green_resolve($this->third)?->sha)->toBe($this->tip);
    });

    it('never ships a commit that has no Required checks run of its own', function (): void {
        green_fake();

        expect(green_resolve($this->sixth, [$this->tip, $this->second, $this->third, $this->fourth]))->toBeNull()
            ->and(green_check_reads())->toBe(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), "/commits/{$this->unchecked}/check-runs?"));
    });

    it('skips commits that already failed a release without reading their checks', function (): void {
        green_fake();

        expect(green_resolve($this->sixth, [$this->tip])?->sha)->toBe($this->second);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), "/commits/{$this->tip}/check-runs"));
    });

    it('refuses a run that checked another commit or a second run that has not passed', function (array $runs): void {
        $response = GreenCommitFixtures::requiredChecks($this->tip);
        $response['check_runs'] = array_map(static fn (array $changes): array => array_replace($response['check_runs'][0], $changes), $runs);
        $response['total_count'] = count($runs);
        green_fake([$this->tip => $response]);

        expect(green_resolve($this->third)?->sha)->toBe($this->second);
    })->with([
        'other head_sha' => [[['head_sha' => str_repeat('d', 40)]]],
        'rerun in progress' => [[[], ['id' => 1, 'status' => 'in_progress', 'conclusion' => null]]],
        'neutral' => [[['conclusion' => 'neutral']]],
    ]);

    it('trusts only Required checks runs that GitHub Actions created', function (array $runs): void {
        $response = GreenCommitFixtures::requiredChecks($this->tip);
        $response['check_runs'] = array_map(static fn (array $changes): array => array_replace($response['check_runs'][0], $changes), $runs);
        $response['total_count'] = count($runs);
        green_fake([$this->tip => $response]);

        expect(green_resolve($this->third)?->sha)->toBe($this->second);
    })->with([
        'a success from another App' => [[['app' => ['slug' => 'forged-checks']]]],
        'a success without an App' => [[['app' => null]]],
        'another App beside GitHub Actions' => [[[], ['id' => 1, 'app' => ['slug' => 'forged-checks']]]],
    ]);

    it('ignores runs of other checks', function (): void {
        $response = GreenCommitFixtures::requiredChecks($this->tip);
        $response['check_runs'][0]['name'] = 'Gateway';
        green_fake([$this->tip => $response]);

        expect(green_resolve($this->third)?->sha)->toBe($this->second);
    });

    it('never downgrades or crosses to history the deployed commit is not part of', function (string $comparison): void {
        green_fake(comparison: $comparison);

        expect(green_resolve(str_repeat('e', 40)))->toBeNull()
            ->and(green_check_reads())->toBe(1);
    })->with(['behind', 'diverged', 'identical']);

    it('refuses an ahead comparison whose merge base is not the deployed commit', function (): void {
        green_fake(alignMergeBase: false);

        expect(green_resolve(str_repeat('e', 40)))->toBeNull();
    });

    it('releases the newest green commit when the deployed commit is older than the commits page', function (): void {
        $deployed = str_repeat('e', 40);
        green_fake([
            $this->tip => GreenCommitFixtures::requiredChecksAs('failure', $this->tip),
            $this->second => GreenCommitFixtures::requiredChecksAs('in_progress', $this->second),
        ]);

        expect(green_resolve($deployed)?->sha)->toBe($this->third)
            ->and(green_check_reads())->toBe(3);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), "/compare/{$deployed}...{$this->third}?"));
        expect(Http::recorded(static fn (Request $request): bool => str_contains($request->url(), '/compare/'))->count())->toBe(1);
    });

    it('reads at most twenty candidates per resolution', function (): void {
        green_fake(array_fill_keys(GreenCommitFixtures::mainShas(), GreenCommitFixtures::requiredChecksAs('none', str_repeat('0', 40))));

        expect(count(GreenCommitFixtures::mainShas()))->toBeGreaterThan(20)
            ->and(green_resolve(str_repeat('e', 40)))->toBeNull()
            ->and(green_check_reads())->toBe(20);
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/compare/'));
    });

    it('follows only the first parent, so a merged side branch never ships', function (): void {
        $commits = GreenCommitFixtures::commits();
        $side = $commits[2];
        $commits[0]['parents'] = [['sha' => $commits[1]['sha']], ['sha' => $side['sha']]];
        $commits[1]['parents'] = [['sha' => $commits[3]['sha']]];
        $failing = GreenCommitFixtures::requiredChecksAs('failure', $commits[0]['sha']);
        green_fake([$commits[0]['sha'] => $failing, $commits[1]['sha'] => GreenCommitFixtures::requiredChecksAs('failure', $commits[1]['sha'])], commits: $commits);

        expect(green_resolve($commits[3]['sha']))->toBeNull();
        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), "/commits/{$side['sha']}/check-runs"));
    });

    it('validates its input before any GitHub call', function (string $deployed, array $failed, string $branch, string $check): void {
        expect(fn () => green_resolve($deployed, $failed, $branch, $check))->toThrow(InvalidArgumentException::class);
        Http::assertNothingSent();
    })->with([
        'short deployed sha' => ['c7f8ae627b0e', [], 'main', 'Required checks'],
        'empty deployed sha' => ['', [], 'main', 'Required checks'],
        'uppercase failed sha' => [str_repeat('e', 40), [strtoupper(str_repeat('a', 40))], 'main', 'Required checks'],
        'invalid branch' => [str_repeat('e', 40), [], 'main..x', 'Required checks'],
        'empty check name' => [str_repeat('e', 40), [], 'main', ''],
        'padded check name' => [str_repeat('e', 40), [], 'main', ' Required checks'],
    ]);

    it('fails closed when GitHub cannot answer', function (string $path): void {
        Http::fake(static fn (Request $request) => str_contains($request->url(), $path)
            ? Http::response(['message' => 'Server Error'], 502)
            : match (true) {
                str_ends_with($request->url(), '/installation') => Http::response(['id' => 9]),
                str_contains($request->url(), '/access_tokens') => Http::response(['token' => 'ghs_token'], 201),
                str_contains($request->url(), '/commits?') => Http::response(GreenCommitFixtures::commits()),
                str_contains($request->url(), '/check-runs') => Http::response(GreenCommitFixtures::requiredChecks(GreenCommitFixtures::mainShas()[0])),
            });

        expect(fn () => green_resolve(GreenCommitFixtures::mainShas()[2]))->toThrow(GitHubApiException::class);
    })->with(['/installation', '/access_tokens', '/commits?', '/check-runs', '/compare/']);

    it('fails without a GitHub call when the Gateway GitHub App is not registered', function (): void {
        app(GitHubAppStore::class)->delete();

        expect(fn () => green_resolve(str_repeat('e', 40)))->toThrow(GitHubApiException::class, 'The Gateway GitHub App is not registered.');
        Http::assertNothingSent();
    });

    it('fails when the Gateway GitHub App is not installed on the repository', function (): void {
        Http::fake(['https://api.github.com/repos/nckrtl/orbit/installation' => Http::response(['message' => 'Not Found'], 404)]);

        expect(fn () => green_resolve(str_repeat('e', 40)))->toThrow(GitHubApiException::class, 'The Gateway GitHub App is not installed on nckrtl/orbit.');
    });
});
