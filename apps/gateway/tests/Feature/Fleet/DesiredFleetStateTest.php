<?php

declare(strict_types=1);

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
