<?php

declare(strict_types=1);

use App\Data\Fleet\DesiredFleetStateData;
use App\Domain\Fleet\CliReleaseCatalog;
use App\Domain\Fleet\DesiredFleetState;
use App\Domain\Fleet\ReleaseHistory;
use App\Infrastructure\Nodes\NodeAgentFootprint;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe(DesiredFleetState::class, function (): void {
    beforeEach(function (): void {
        config()->set('app.version', CLI_RELEASE_FIXTURE_COMMIT);
    });

    it('names the commit, the CLI release, and the pinned agent', function (): void {
        fake_release_history();
        fake_cli_release_github();

        $state = app(DesiredFleetState::class)->current()->toArray();

        expect($state['commit'])->toBe(CLI_RELEASE_FIXTURE_COMMIT)
            ->and($state['cli']['version'])->toBe('0.4681.0')
            ->and($state['agent'])->toBe([
                'version' => NodeAgentFootprint::Version,
                'assets' => [
                    [
                        'platform' => 'linux-x86_64',
                        'name' => 'orbit-agent-'.NodeAgentFootprint::Version.'-linux-x86_64',
                        'url' => NodeAgentFootprint::downloadUrl('x86_64'),
                        'sha256' => NodeAgentFootprint::checksum('x86_64'),
                    ],
                    [
                        'platform' => 'linux-aarch64',
                        'name' => 'orbit-agent-'.NodeAgentFootprint::Version.'-linux-aarch64',
                        'url' => NodeAgentFootprint::downloadUrl('aarch64'),
                        'sha256' => NodeAgentFootprint::checksum('aarch64'),
                    ],
                ],
            ]);
    });

    it('resolves a short Gateway version to the full commit', function (): void {
        config()->set('app.version', substr(CLI_RELEASE_FIXTURE_COMMIT, 0, 12));
        fake_release_history();
        fake_cli_release_github();

        expect(app(DesiredFleetState::class)->current()->commit)->toBe(CLI_RELEASE_FIXTURE_COMMIT);
    });

    it('keeps a confirmed release for its commit without asking GitHub again', function (): void {
        fake_release_history();
        fake_cli_release_github();
        $state = app(DesiredFleetState::class);

        $first = $state->current()->toArray();
        Http::assertSentCount(3);
        $this->travel(DesiredFleetState::AvailableSeconds - 60)->seconds();

        expect($state->current()->toArray())->toBe($first)
            ->and($state->cached()?->toArray())->toBe($first);
        Http::assertSentCount(3);
    });

    it('asks again after a minute while the release is missing', function (): void {
        fake_release_history();
        $published = false;
        Http::fake(static function (Request $request) use (&$published) {
            return match ((string) parse_url($request->url(), PHP_URL_PATH)) {
                '/repos/nckrtl/orbit/git/ref/tags/cli-v0.4681.0' => $published ? Http::response(cli_release_fixture_json('tag-ref.json')) : Http::response(['message' => 'Not Found'], 404),
                '/repos/nckrtl/orbit/releases/tags/cli-v0.4681.0' => Http::response(cli_release_fixture_json('release.json')),
                default => Http::response(cli_release_fixture('SHA256SUMS')),
            };
        });
        $state = app(DesiredFleetState::class);

        expect($state->current()->cli->reason?->value)->toBe('release_missing')
            ->and($state->current()->cli->reason?->value)->toBe('release_missing');
        Http::assertSentCount(1);

        $published = true;
        $this->travel(DesiredFleetState::UnavailableSeconds + 1)->seconds();

        expect($state->current()->cli->version)->toBe('0.4681.0');
    });

    describe('fallback', function (): void {
        beforeEach(function (): void {
            $this->commit = str_repeat('a', 40);
            $this->middle = str_repeat('b', 40);
            config()->set('app.version', $this->commit);
            // The commit and its parent have no release; the grandparent has the fixture release cli-v0.4681.0.
            fake_release_history(commit: $this->commit, count: 4683, ancestors: [$this->middle => 4682, CLI_RELEASE_FIXTURE_COMMIT => 4681]);
            $this->published = [4681 => CLI_RELEASE_FIXTURE_COMMIT];
            $this->responses = [];
            fake_cli_releases($this->published, $this->responses);
            $this->state = app(DesiredFleetState::class);
            // The first lookup starts the 30 minutes.
            $this->state->current();
        });

        it('names the newest published release of an ancestor once the commit\'s own release is missing for 30 minutes', function (): void {
            expect($this->state->current()->cli->toArray())->toMatchArray(['status' => 'pending', 'reason' => 'release_missing', 'version' => '0.4683.0', 'commit' => $this->commit]);

            $this->travel(DesiredFleetState::FallbackAfterSeconds - 60)->seconds();
            expect($this->state->current()->cli->status->value)->toBe('pending');

            $this->travel(61)->seconds();
            $fallback = $this->state->current();

            expect($fallback->cliFallback())->toBeTrue()
                ->and($fallback->commit)->toBe($this->commit)
                ->and($fallback->cli->toArray())->toMatchArray(['status' => 'available', 'reason' => null, 'version' => '0.4681.0', 'commit' => CLI_RELEASE_FIXTURE_COMMIT])
                ->and($this->state->cached()?->cliFallback())->toBeTrue();
            // Two pending answers ask for the commit's own tag. The fallback asks for it again, for the parent's
            // missing tag, then for the fixture's tag, release, and SHA256SUMS.
            Http::assertSentCount(2 + 1 + 1 + 3);
        });

        it('takes the highest release number, not the order of the history', function (): void {
            fake_release_history(commit: $this->commit, count: 4683, ancestors: [CLI_RELEASE_FIXTURE_COMMIT => 4681, $this->middle => 4682]);
            $this->published[4682] = $this->middle;
            $this->travel(DesiredFleetState::FallbackAfterSeconds + 1)->seconds();

            expect(app(DesiredFleetState::class)->current()->cli->toArray())->toMatchArray(['version' => '0.4682.0', 'commit' => $this->middle]);
        });

        it('skips an ancestor whose CLI build inputs differ, before it asks GitHub', function (): void {
            fake_release_history(commit: $this->commit, count: 4683, ancestors: [$this->middle => 4682, CLI_RELEASE_FIXTURE_COMMIT => 4681], cliChanged: [$this->middle]);
            $this->published[4682] = $this->middle;
            $this->travel(DesiredFleetState::FallbackAfterSeconds + 1)->seconds();

            expect(app(DesiredFleetState::class)->current()->cli->version)->toBe('0.4681.0');
            Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), 'cli-v0.4682.0'));
        });

        it('skips an ancestor whose release number tags another commit', function (): void {
            // A side commit merged into main can reach the same count as a main commit and own its tag.
            $this->published[4682] = str_repeat('c', 40);
            $this->travel(DesiredFleetState::FallbackAfterSeconds + 1)->seconds();

            expect($this->state->current()->cli->toArray())->toMatchArray(['version' => '0.4681.0', 'commit' => CLI_RELEASE_FIXTURE_COMMIT]);
        });

        it('falls back when the commit\'s own release tags another commit or is incomplete', function (string $problem): void {
            if ($problem === 'mismatch') {
                $this->published[4683] = str_repeat('d', 40);
            } else {
                $this->published[4683] = $this->commit;
                $this->responses['/repos/nckrtl/orbit/releases/tags/cli-v0.4683.0'] = Http::response(['tag_name' => 'cli-v0.4683.0', 'draft' => true, 'assets' => []]);
            }

            $this->travel(DesiredFleetState::FallbackAfterSeconds + 1)->seconds();

            expect($this->state->current()->cli->toArray())->toMatchArray(['status' => 'available', 'version' => '0.4681.0']);
        })->with(['mismatch', 'incomplete']);

        it('replaces the fallback with the commit\'s own release once it appears', function (): void {
            $this->travel(DesiredFleetState::FallbackAfterSeconds + 1)->seconds();
            expect($this->state->current()->cli->version)->toBe('0.4681.0');

            $this->published[4683] = $this->commit;
            $this->travel(DesiredFleetState::FallbackSeconds - 30)->seconds();
            expect($this->state->current()->cli->version)->toBe('0.4681.0');

            $this->travel(31)->seconds();
            $own = $this->state->current();

            expect($own->cliFallback())->toBeFalse()
                ->and($own->cli->toArray())->toMatchArray(['status' => 'available', 'version' => '0.4683.0', 'commit' => $this->commit]);
        });

        it('keeps the fallback while GitHub fails, without searching again', function (): void {
            $this->travel(DesiredFleetState::FallbackAfterSeconds + 1)->seconds();
            expect($this->state->current()->cli->version)->toBe('0.4681.0');

            $this->responses['/repos/nckrtl/orbit/git/ref/tags/cli-v0.4683.0'] = Http::response(['message' => 'Server Error'], 500);
            $this->responses['/repos/nckrtl/orbit/git/ref/tags/cli-v0.4681.0'] = Http::response(['message' => 'Server Error'], 500);
            $this->travel(DesiredFleetState::FallbackSeconds + 1)->seconds();
            $sent = count(Http::recorded());

            expect($this->state->current()->cli->toArray())->toMatchArray(['status' => 'available', 'version' => '0.4681.0'])
                ->and(count(Http::recorded()) - $sent)->toBe(1);
        });

        it('stops the search and names no release while GitHub cannot answer for an ancestor', function (): void {
            $this->responses['/repos/nckrtl/orbit/git/ref/tags/cli-v0.4682.0'] = Http::response(['message' => 'Server Error'], 500);
            $this->travel(DesiredFleetState::FallbackAfterSeconds + 1)->seconds();

            expect($this->state->current()->cli->toArray())->toMatchArray(['status' => 'pending', 'version' => '0.4683.0']);
            Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), 'cli-v0.4681.0'));
        });

        it('searches again only after 5 minutes when no ancestor has a release', function (): void {
            unset($this->published[4681]);
            $this->travel(DesiredFleetState::FallbackAfterSeconds + 1)->seconds();
            expect($this->state->current()->cli->status->value)->toBe('pending');
            $sent = count(Http::recorded());

            $this->travel(DesiredFleetState::UnavailableSeconds + 1)->seconds();
            expect($this->state->current()->cli->status->value)->toBe('pending')
                ->and(count(Http::recorded()))->toBe($sent);

            $this->published[4681] = CLI_RELEASE_FIXTURE_COMMIT;
            $this->travel(DesiredFleetState::FallbackSeconds)->seconds();
            expect($this->state->current()->cli->version)->toBe('0.4681.0');
        });

        it('never falls back while GitHub cannot answer for the commit itself', function (): void {
            $this->responses['/repos/nckrtl/orbit/git/ref/tags/cli-v0.4683.0'] = Http::response(['message' => 'Server Error'], 500);
            $this->travel(DesiredFleetState::FallbackAfterSeconds + 1)->seconds();

            expect($this->state->current()->cli->toArray()['reason'])->toBe('github_unavailable');
            Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), 'cli-v0.4681.0'));
        });

        it('reads the start of the 30 minutes back from a cache that returns numbers as strings', function (): void {
            // Redis stores the number as text; the first lookup in beforeEach already stored it.
            $key = 'orbit:desired-fleet-state:v1:missing-since:'.hash('sha256', $this->commit);
            cache()->put($key, (string) cache()->get($key), DesiredFleetState::AvailableSeconds);
            $this->travel(DesiredFleetState::FallbackAfterSeconds + 1)->seconds();

            expect($this->state->current()->cli->version)->toBe('0.4681.0');
        });
    });

    it('reads a stored state from before releases named their commit', function (): void {
        $stored = DesiredFleetStateData::fromArray([
            'commit' => CLI_RELEASE_FIXTURE_COMMIT,
            'cli' => ['status' => 'available', 'reason' => null, 'version' => '0.4681.0', 'tag' => 'cli-v0.4681.0', 'checksums_url' => 'https://github.com/nckrtl/orbit/releases/download/cli-v0.4681.0/SHA256SUMS', 'assets' => [
                ['platform' => 'linux-x86_64', 'name' => 'orbit-0.4681.0-linux-x86_64', 'url' => 'https://github.com/nckrtl/orbit/releases/download/cli-v0.4681.0/orbit-0.4681.0-linux-x86_64', 'sha256' => str_repeat('c', 64)],
            ]],
            'agent' => ['version' => NodeAgentFootprint::Version, 'assets' => []],
        ]);

        expect($stored?->cli->commit)->toBeNull()
            ->and($stored?->cliFallback())->toBeFalse();
    });

    it('reports a Gateway version that is not a commit without asking Git or GitHub', function (string $version): void {
        config()->set('app.version', $version);
        fake_release_history(commit: null, count: null);
        Http::preventStrayRequests();

        $state = app(DesiredFleetState::class)->current();

        expect($state->commit)->toBeNull()
            ->and($state->cli->toArray()['reason'])->toBe('gateway_commit_unknown')
            ->and($state->agent->version)->toBe(NodeAgentFootprint::Version);
    })->with(['dev', '0.1.0', '']);

    it('keeps a full commit the history does not know and reports it unknown', function (): void {
        fake_release_history(commit: null);
        Http::preventStrayRequests();

        $state = app(DesiredFleetState::class)->current();

        expect($state->commit)->toBe(CLI_RELEASE_FIXTURE_COMMIT)
            ->and($state->cli->toArray()['reason'])->toBe('gateway_commit_unknown');
    });

    it('reports a history it cannot count', function (): void {
        fake_release_history(count: null);
        Http::preventStrayRequests();

        expect(app(DesiredFleetState::class)->current()->cli->toArray()['reason'])->toBe('history_unavailable');
    });

    it('resolves a cold commit under a lock and reads the answer another caller stored while it waited', function (): void {
        fake_release_history();
        Http::preventStrayRequests();
        $store = new ArrayStore;
        $cache = new Repository($store);
        $lock = mock(Lock::class);
        // The other caller stores its answer while this one waits for the lock.
        $lock->shouldReceive('block')->once()->with(DesiredFleetState::LockWaitSeconds)->andReturnUsing(static function () use ($cache): bool {
            $cache->put('orbit:desired-fleet-state:v1:'.hash('sha256', CLI_RELEASE_FIXTURE_COMMIT), [
                'commit' => CLI_RELEASE_FIXTURE_COMMIT,
                'cli' => ['status' => 'pending', 'reason' => 'release_missing', 'version' => '0.4681.0', 'tag' => 'cli-v0.4681.0', 'checksums_url' => null, 'assets' => []],
            ], 60);

            return true;
        });
        $lock->shouldReceive('release')->once();
        $locks = Mockery::mock(ArrayStore::class)->makePartial();
        $locks->shouldReceive('lock')->once()->andReturn($lock);
        $locks->shouldReceive('get')->andReturnUsing(static fn (string $key): mixed => $store->get($key));

        $state = new DesiredFleetState(app(ReleaseHistory::class), app(CliReleaseCatalog::class), new Repository($locks));

        expect($state->current()->cli->status->value)->toBe('pending');
        Http::assertNothingSent();
    });

    it('resolves after the lock wait when the holder never answers', function (): void {
        fake_release_history();
        fake_cli_release_github();
        $lock = mock(Lock::class);
        $lock->shouldReceive('block')->once()->andThrow(new LockTimeoutException);
        $lock->shouldNotReceive('release');
        $store = Mockery::mock(ArrayStore::class)->makePartial();
        $store->shouldReceive('lock')->once()->andReturn($lock);

        $state = new DesiredFleetState(app(ReleaseHistory::class), app(CliReleaseCatalog::class), new Repository($store));

        expect($state->current()->cli->version)->toBe('0.4681.0');
    });

    it('reads nothing but the cache for the cached state', function (): void {
        fake_release_history(commit: null, count: null);
        Http::preventStrayRequests();

        expect(app(DesiredFleetState::class)->cached())->toBeNull();
    });
});
