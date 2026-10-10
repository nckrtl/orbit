<?php

declare(strict_types=1);

use App\Domain\Fleet\DesiredFleetState;
use Illuminate\Support\Facades\Http;
use Orbit\Sdk\Requests\Gateway\ShowDesiredFleetStateRequest;
use Orbit\Sdk\Requests\Gateway\ShowGatewayStatusRequest;

/**
 * Records the desired fleet state (ADR 0202) responses that the CLI replays for `self-update` and
 * `gateway:status`. The caller is an operator machine at 10.44.0.7, GitHub serves the fixture release
 * `cli-v0.4681.0`, and the request id is fixed.
 */
describe('gateway response fixtures', function (): void {
    beforeEach(function (): void {
        config()->set('app.version', CLI_RELEASE_FIXTURE_COMMIT);
        fake_release_history();
        $this->withHeader('X-Orbit-Request-Id', fixture_request_id());
    });

    it('records the desired state fixtures with a published CLI release', function (): void {
        desired_fleet_state_peer();
        fake_cli_release_github();

        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.7'])
            ->getJson('/api/v1/gateway/desired-fleet-state')
            ->assertOk()
            ->assertJsonPath('data.commit', CLI_RELEASE_FIXTURE_COMMIT)
            ->assertJsonPath('data.cli.status', 'available')
            ->assertJsonPath('data.cli.version', '0.4681.0')
            ->assertJsonPath('data.cli.assets.0.platform', 'linux-x86_64')
            ->assertJsonPath('data.agent.assets.1.platform', 'linux-aarch64');

        record_fixture($response, 'gateway/self-update/available', ShowDesiredFleetStateRequest::class, 'GET /api/v1/gateway/desired-fleet-state');
    });

    it('records the desired state fixtures while the CLI release is pending', function (): void {
        desired_fleet_state_peer();
        fake_cli_release_github(['/repos/nckrtl/orbit/git/ref/tags/cli-v0.4681.0' => Http::response(['message' => 'Not Found'], 404)]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.7'])
            ->getJson('/api/v1/gateway/desired-fleet-state')
            ->assertOk()
            ->assertJsonPath('data.cli', [
                'status' => 'pending',
                'reason' => 'release_missing',
                'version' => '0.4681.0',
                'tag' => 'cli-v0.4681.0',
                'commit' => CLI_RELEASE_FIXTURE_COMMIT,
                'checksums_url' => null,
                'assets' => [],
            ]);

        record_fixture($response, 'gateway/self-update/pending', ShowDesiredFleetStateRequest::class, 'GET /api/v1/gateway/desired-fleet-state');
    });

    it('records the desired state fixtures while the CLI falls back to an ancestor release', function (): void {
        desired_fleet_state_peer();
        $commit = str_repeat('a', 40);
        config()->set('app.version', $commit);
        // The Gateway's commit has no release; its parent has the fixture release cli-v0.4681.0.
        fake_release_history(commit: $commit, count: 4682, ancestors: [CLI_RELEASE_FIXTURE_COMMIT => 4681]);
        $published = [4681 => CLI_RELEASE_FIXTURE_COMMIT];
        fake_cli_releases($published);
        // The first lookup starts the wait for the commit's own release.
        app(DesiredFleetState::class)->current();
        $this->travel(DesiredFleetState::FallbackAfterSeconds + 1)->seconds();

        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.7'])
            ->getJson('/api/v1/gateway/desired-fleet-state')
            ->assertOk()
            ->assertJsonPath('data.commit', $commit)
            ->assertJsonPath('data.cli.status', 'available')
            ->assertJsonPath('data.cli.reason', 'release_missing')
            ->assertJsonPath('data.cli.version', '0.4681.0')
            ->assertJsonPath('data.cli.commit', CLI_RELEASE_FIXTURE_COMMIT);

        record_fixture($response, 'gateway/self-update/fallback', ShowDesiredFleetStateRequest::class, 'GET /api/v1/gateway/desired-fleet-state');
    });

    it('records the Gateway status fixtures with the desired state for an active peer', function (): void {
        desired_fleet_state_peer();
        fake_cli_release_github();
        // The scheduler resolves the state; the status request reads it from the cache.
        app(DesiredFleetState::class)->current();

        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.7'])
            ->getJson('/api/v1/gateway/status')
            ->assertOk()
            ->assertJsonPath('data.version', CLI_RELEASE_FIXTURE_COMMIT)
            ->assertJsonPath('data.desired_fleet_state.cli.version', '0.4681.0');
        $body = $response->json();
        // PHP and Laravel versions change with every upgrade; the fixture keeps stable example values.
        $body['data']['php_version'] = '8.5.0';
        $body['data']['laravel_version'] = '13.0.0';
        $response->setContent(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        record_fixture($response, 'gateway/gateway-status/peer', ShowGatewayStatusRequest::class, 'GET /api/v1/gateway/status');
    });

});
