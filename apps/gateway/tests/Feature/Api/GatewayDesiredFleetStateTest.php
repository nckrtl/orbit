<?php

declare(strict_types=1);

use App\Domain\Fleet\DesiredFleetState;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;

/**
 * The desired fleet state (ADR 0202) that any active WireGuard peer may read. The fixture tests record the
 * responses the CLI replays for `self-update` and `gateway:status`.
 */
describe('GET /api/v1/gateway/desired-fleet-state', function (): void {
    beforeEach(function (): void {
        config()->set('app.version', CLI_RELEASE_FIXTURE_COMMIT);
        fake_release_history();
        $this->withHeader('X-Orbit-Request-Id', fixture_request_id());
    });

    it('serves the same state to a managed Node and an operator machine', function (): void {
        $this->markAsGateway(Node::query()->create([
            'name' => 'gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
            'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1',
        ]));
        Node::query()->create([
            'name' => 'beast', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
            'public_ssh_host' => '192.0.2.3', 'wireguard_ip' => '10.44.0.3',
        ]);
        desired_fleet_state_peer();
        fake_cli_release_github();

        $fromNode = $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.3'])->getJson('/api/v1/gateway/desired-fleet-state')->assertOk()->json('data');
        $fromOperator = $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.7'])->getJson('/api/v1/gateway/desired-fleet-state')->assertOk()->json('data');

        expect($fromNode)->toBe($fromOperator);
    });

    it('refuses a caller that is not an active WireGuard peer', function (string $address, LifecycleStatus $status): void {
        Node::query()->create([
            'name' => 'retired', 'status' => $status, 'platform' => 'linux',
            'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9',
        ]);
        Http::preventStrayRequests();

        $this->withServerVariables(['REMOTE_ADDR' => $address])
            ->getJson('/api/v1/gateway/desired-fleet-state')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'peer.identity_unknown');
    })->with([
        'unknown address' => ['192.0.2.99', LifecycleStatus::Active],
        'inactive Node' => ['10.44.0.9', LifecycleStatus::Failed],
    ]);
});

describe('GET /api/v1/gateway/status desired fleet state', function (): void {
    it('leaves the desired state out for a caller that is not an active peer, without asking GitHub', function (): void {
        Http::preventStrayRequests();

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])
            ->getJson('/api/v1/gateway/status')
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.desired_fleet_state', null);
    });
});

describe('X-Orbit-Cli-Version', function (): void {
    beforeEach(function (): void {
        config()->set('app.version', CLI_RELEASE_FIXTURE_COMMIT);
        fake_release_history();
        desired_fleet_state_peer();
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.7']);
    });

    it('names the desired CLI release to a client that sends its version', function (): void {
        fake_cli_release_github();
        app(DesiredFleetState::class)->current();

        $this->withHeader('X-Orbit-Client-Version', '0.4600.0')
            ->getJson('/api/v1/gateway/desired-fleet-state')
            ->assertOk()
            ->assertHeader('X-Orbit-Cli-Version', '0.4681.0');
    });

    it('sends nothing to a client that does not send its version', function (): void {
        fake_cli_release_github();
        app(DesiredFleetState::class)->current();

        $this->getJson('/api/v1/gateway/desired-fleet-state')->assertOk()->assertHeaderMissing('X-Orbit-Cli-Version');
    });

    it('sends nothing to a caller that is not an active peer', function (): void {
        fake_cli_release_github();
        app(DesiredFleetState::class)->current();

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])
            ->withHeader('X-Orbit-Client-Version', '0.4600.0')
            ->getJson('/api/v1/gateway/status')
            ->assertOk()
            ->assertHeaderMissing('X-Orbit-Cli-Version');
    });

    it('never asks Git or GitHub while it answers a request', function (): void {
        fake_release_history(commit: null, count: null);
        Http::preventStrayRequests();

        $this->withHeader('X-Orbit-Client-Version', '0.4600.0')
            ->getJson('/api/v1/gateway/desired-fleet-state')
            ->assertOk()
            ->assertHeaderMissing('X-Orbit-Cli-Version');
        Http::assertNothingSent();
    });

    it('sends nothing while the release is pending', function (): void {
        fake_cli_release_github(['/repos/nckrtl/orbit/git/ref/tags/cli-v0.4681.0' => Http::response(['message' => 'Not Found'], 404)]);
        app(DesiredFleetState::class)->current();

        $this->withHeader('X-Orbit-Client-Version', '0.4600.0')
            ->getJson('/api/v1/gateway/desired-fleet-state')
            ->assertOk()
            ->assertHeaderMissing('X-Orbit-Cli-Version');
    });
});

describe('orbit:desired-fleet-state', function (): void {
    it('resolves the state, prints it, and runs every five minutes', function (): void {
        config()->set('app.version', CLI_RELEASE_FIXTURE_COMMIT);
        fake_release_history();
        fake_cli_release_github();

        $this->artisan('orbit:desired-fleet-state')->assertSuccessful()->expectsOutputToContain('"version":"0.4681.0"');

        expect(app(DesiredFleetState::class)->cached()?->cli->version)->toBe('0.4681.0');

        $event = collect(app(Schedule::class)->events())
            ->first(static fn ($event): bool => str_contains((string) $event->command, 'orbit:desired-fleet-state'));

        expect($event?->expression)->toBe('*/5 * * * *');
    });
});
