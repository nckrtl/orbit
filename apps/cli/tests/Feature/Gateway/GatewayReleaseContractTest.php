<?php

declare(strict_types=1);

use App\Commands\Gateway\GatewayReleaseFollowCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\GatewayReleases\DeployGatewayReleaseRequest;
use Orbit\Sdk\Requests\GatewayReleases\RollbackGatewayReleaseRequest;
use Orbit\Sdk\Requests\GatewayReleases\ShowGatewayReleaseRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

const GATEWAY_RELEASE_TARGET = 'fedcba9876543210fedcba9876543210fedcba98';

/**
 * Replays a queued release and then the record reads that follow it, one fixture per read.
 *
 * @param  list<string>  $reads  the recorded record reads, in order
 */
function gateway_release_follow(string $queued, array $reads, string $command, array $arguments, string $expected, int $exitCode): MockClient
{
    $queue = gateway_fixture($queued);
    $bodies = array_map(static fn (string $read): array => gateway_fixture($read), $reads);

    MockClient::destroyGlobal();
    $mock = MockClient::global([
        ...gateway_fixture_mock(),
        $queue['request'] => MockResponse::make($queue['body'], $queue['status'], ['Content-Type' => 'application/json']),
        ShowGatewayReleaseRequest::class => static function () use (&$bodies): MockResponse {
            $read = count($bodies) > 1 ? array_shift($bodies) : $bodies[0];

            return MockResponse::make($read['body'], $read['status'], ['Content-Type' => 'application/json']);
        },
    ]);

    expect(Artisan::call($command, $arguments))->toBe($exitCode);
    expect_output(Artisan::output(), $expected);

    return $mock;
}

beforeEach(function (): void {
    $this->originalColumns = getenv('COLUMNS');
    putenv('COLUMNS=120');
    Sleep::fake();
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    putenv($this->originalColumns === false ? 'COLUMNS' : 'COLUMNS='.$this->originalColumns);
    MockClient::destroyGlobal();
    Sleep::fake(false);
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe('gateway release contract', function (): void {
    it('renders gateway:release:list from the recorded response', function (): void {
        run_contract('gateway-releases/gateway-release-list/default', 'gateway:release:list', [], 'gateway-releases/gateway-release-list/default.human.txt', 0);
        run_contract('gateway-releases/gateway-release-list/default', 'gateway:release:list', ['--json' => true], 'gateway-releases/gateway-release-list/default.json', 0);
    });

    it('renders gateway:release:show for a live, a switched-back, and a missing record', function (): void {
        run_contract('gateway-releases/gateway-release-show/verified', 'gateway:release:show', ['release' => '1'], 'gateway-releases/gateway-release-show/verified.human.txt', 0);
        run_contract('gateway-releases/gateway-release-show/verified', 'gateway:release:show', ['release' => '1', '--json' => true], 'gateway-releases/gateway-release-show/verified.json', 0);
        run_contract('gateway-releases/gateway-release-show/switched-back', 'gateway:release:show', ['release' => 'fedcba987654'], 'gateway-releases/gateway-release-show/switched-back.human.txt', 0);
        run_contract('gateway-releases/gateway-release-show/not-found', 'gateway:release:show', ['release' => '99'], 'gateway-releases/gateway-release-show/not-found.human.txt', 1);
        run_contract('gateway-releases/gateway-release-show/not-found', 'gateway:release:show', ['release' => '99', '--json' => true], 'gateway-releases/gateway-release-show/not-found.json', 1);
    });

    it('queues a deploy and follows its record until the release is live', function (): void {
        $mock = gateway_release_follow('gateway-releases/gateway-release-deploy/queued', ['gateway-releases/gateway-release-show/running', 'gateway-releases/gateway-release-show/verified'], 'gateway:release:deploy', ['commit' => GATEWAY_RELEASE_TARGET], 'gateway-releases/gateway-release-deploy/verified.human.txt', 0);
        gateway_release_follow('gateway-releases/gateway-release-deploy/queued', ['gateway-releases/gateway-release-show/running', 'gateway-releases/gateway-release-show/verified'], 'gateway:release:deploy', ['commit' => GATEWAY_RELEASE_TARGET, '--json' => true], 'gateway-releases/gateway-release-deploy/verified.json', 0);

        $mock->assertSent(static fn ($request): bool => $request instanceof DeployGatewayReleaseRequest && $request->body()->all() === ['commit' => GATEWAY_RELEASE_TARGET]);
        $mock->assertSent(static fn ($request): bool => $request instanceof ShowGatewayReleaseRequest && $request->resolveEndpoint() === '/api/v1/gateway/releases/1');
    });

    it('fails when the followed release switched back or paused', function (): void {
        gateway_release_follow('gateway-releases/gateway-release-deploy/queued', ['gateway-releases/gateway-release-show/running', 'gateway-releases/gateway-release-show/switched-back'], 'gateway:release:deploy', ['commit' => GATEWAY_RELEASE_TARGET], 'gateway-releases/gateway-release-deploy/switched-back.human.txt', 1);
        gateway_release_follow('gateway-releases/gateway-release-deploy/queued', ['gateway-releases/gateway-release-show/switched-back'], 'gateway:release:deploy', ['commit' => GATEWAY_RELEASE_TARGET, '--json' => true], 'gateway-releases/gateway-release-deploy/switched-back.json', 1);
        gateway_release_follow('gateway-releases/gateway-release-deploy/queued', ['gateway-releases/gateway-release-show/paused'], 'gateway:release:deploy', ['commit' => GATEWAY_RELEASE_TARGET], 'gateway-releases/gateway-release-deploy/paused.human.txt', 1);
    });

    it('keeps following through a Gateway that reloads, and gives up on a refused read', function (): void {
        $queued = gateway_fixture('gateway-releases/gateway-release-deploy/queued');
        $verified = gateway_fixture('gateway-releases/gateway-release-show/verified');
        $reads = [MockResponse::make('<html>Bad Gateway</html>', 502), MockResponse::make($verified['body'], 200, ['Content-Type' => 'application/json'])];
        MockClient::global([
            ...gateway_fixture_mock(),
            DeployGatewayReleaseRequest::class => MockResponse::make($queued['body'], 202, ['Content-Type' => 'application/json']),
            ShowGatewayReleaseRequest::class => static function () use (&$reads): MockResponse {
                return array_shift($reads);
            },
        ]);

        expect(Artisan::call('gateway:release:deploy', ['commit' => GATEWAY_RELEASE_TARGET, '--json' => true]))->toBe(0)
            ->and(json_decode(Artisan::output(), true)['outcome'])->toBe('verified');

        MockClient::destroyGlobal();
        MockClient::global([
            ...gateway_fixture_mock(),
            DeployGatewayReleaseRequest::class => MockResponse::make($queued['body'], 202, ['Content-Type' => 'application/json']),
            ShowGatewayReleaseRequest::class => MockResponse::make(['error' => ['code' => 'node_access.required', 'message' => 'Node access is required.']], 403, ['Content-Type' => 'application/json']),
        ]);

        expect(Artisan::call('gateway:release:deploy', ['commit' => GATEWAY_RELEASE_TARGET, '--json' => true]))->toBe(1)
            ->and(json_decode(Artisan::output(), true)['error']['code'])->toBe('gateway.release_follow_failed');
    });

    it('stops following a record that never finishes once the deadline passes', function (): void {
        Sleep::fake(syncWithCarbon: true);
        $this->travelTo(now());
        $queued = gateway_fixture('gateway-releases/gateway-release-deploy/queued');
        $running = gateway_fixture('gateway-releases/gateway-release-show/running');
        $reads = 0;
        MockClient::global([
            ...gateway_fixture_mock(),
            DeployGatewayReleaseRequest::class => MockResponse::make($queued['body'], 202, ['Content-Type' => 'application/json']),
            ShowGatewayReleaseRequest::class => static function () use ($running, &$reads): MockResponse {
                $reads++;

                return MockResponse::make($running['body'], 200, ['Content-Type' => 'application/json']);
            },
        ]);

        expect(Artisan::call('gateway:release:deploy', ['commit' => GATEWAY_RELEASE_TARGET, '--json' => true]))->toBe(1)
            ->and(json_decode(Artisan::output(), true)['error']['code'])->toBe('gateway.release_follow_failed')
            ->and($reads)->toBe(intdiv(GatewayReleaseFollowCommand::FollowSeconds, GatewayReleaseFollowCommand::PollSeconds));
    });

    it('reports a deploy the Gateway refuses while another release runs', function (): void {
        run_contract('gateway-releases/gateway-release-deploy/in-progress', 'gateway:release:deploy', ['commit' => GATEWAY_RELEASE_TARGET], 'gateway-releases/gateway-release-deploy/in-progress.human.txt', 1);
        run_contract('gateway-releases/gateway-release-deploy/in-progress', 'gateway:release:deploy', ['commit' => GATEWAY_RELEASE_TARGET, '--json' => true], 'gateway-releases/gateway-release-deploy/in-progress.json', 1);
    });

    it('refuses a branch name before it contacts the Gateway', function (): void {
        MockClient::global([]);

        expect(Artisan::call('gateway:release:deploy', ['commit' => 'main', '--json' => true]))->toBe(1)
            ->and(json_decode(Artisan::output(), true)['error']['code'])->toBe('gateway.release_commit_invalid');
    });

    it('rolls back with consent for --force and follows the rollback', function (): void {
        $mock = gateway_release_follow('gateway-releases/gateway-release-rollback/queued', ['gateway-releases/gateway-release-show/rolled-back'], 'gateway:release:rollback', ['release' => 'fedcba987654', '--force' => true, '--yes' => true], 'gateway-releases/gateway-release-rollback/rolled-back.human.txt', 0);

        $mock->assertSent(static fn ($request): bool => $request instanceof RollbackGatewayReleaseRequest && $request->body()->all() === ['force' => true]);
    });

    it('requires --yes for a forced rollback without a terminal and asks nothing of the Gateway', function (): void {
        $mock = MockClient::global([]);

        expect(Artisan::call('gateway:release:rollback', ['release' => 'fedcba987654', '--force' => true, '--json' => true]))->toBe(1)
            ->and(json_decode(Artisan::output(), true)['error']['code'])->toBe('input.confirmation_required');
        $mock->assertNothingSent();
    });

    it('reports a rollback that would cross a migration', function (): void {
        run_contract('gateway-releases/gateway-release-rollback/migration-crossed', 'gateway:release:rollback', ['release' => 'fedcba987654'], 'gateway-releases/gateway-release-rollback/migration-crossed.human.txt', 1);
        run_contract('gateway-releases/gateway-release-rollback/migration-crossed', 'gateway:release:rollback', ['release' => 'fedcba987654', '--json' => true], 'gateway-releases/gateway-release-rollback/migration-crossed.json', 1);
    });

    it('renders the automatic release state and its changes', function (): void {
        run_contract('gateway-releases/gateway-release-auto-status/paused', 'gateway:release:auto:status', [], 'gateway-releases/gateway-release-auto-status/paused.human.txt', 0);
        run_contract('gateway-releases/gateway-release-auto-status/paused', 'gateway:release:auto:status', ['--json' => true], 'gateway-releases/gateway-release-auto-status/paused.json', 0);
        run_contract('gateway-releases/gateway-release-auto-status/disabled', 'gateway:release:auto:status', [], 'gateway-releases/gateway-release-auto-status/disabled.human.txt', 0);
        run_contract('gateway-releases/gateway-release-auto-enable/enabled', 'gateway:release:auto:enable', [], 'gateway-releases/gateway-release-auto-enable/enabled.human.txt', 0);
        run_contract('gateway-releases/gateway-release-auto-disable/disabled', 'gateway:release:auto:disable', ['--json' => true], 'gateway-releases/gateway-release-auto-disable/disabled.json', 0);
        run_contract('gateway-releases/gateway-release-auto-resume/resumed', 'gateway:release:auto:resume', [], 'gateway-releases/gateway-release-auto-resume/resumed.human.txt', 0);
        run_contract('gateway-releases/gateway-release-auto-resume/not-paused', 'gateway:release:auto:resume', [], 'gateway-releases/gateway-release-auto-resume/not-paused.human.txt', 1);
        run_contract('gateway-releases/gateway-release-auto-resume/not-paused', 'gateway:release:auto:resume', ['--json' => true], 'gateway-releases/gateway-release-auto-resume/not-paused.json', 1);
    });
});
