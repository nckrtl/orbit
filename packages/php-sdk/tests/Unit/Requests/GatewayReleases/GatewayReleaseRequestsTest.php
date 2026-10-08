<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Gateway\ShowGatewayStatusRequest;
use Orbit\Sdk\Requests\GatewayReleases\DeployGatewayReleaseRequest;
use Orbit\Sdk\Requests\GatewayReleases\DisableGatewayReleaseAutomationRequest;
use Orbit\Sdk\Requests\GatewayReleases\EnableGatewayReleaseAutomationRequest;
use Orbit\Sdk\Requests\GatewayReleases\ListGatewayReleasesRequest;
use Orbit\Sdk\Requests\GatewayReleases\ResumeGatewayReleaseAutomationRequest;
use Orbit\Sdk\Requests\GatewayReleases\RollbackGatewayReleaseRequest;
use Orbit\Sdk\Requests\GatewayReleases\ShowGatewayReleaseAutomationRequest;
use Orbit\Sdk\Requests\GatewayReleases\ShowGatewayReleaseRequest;
use Orbit\Sdk\Requests\GatewayReleases\SmokeGatewayReleaseRequest;
use Orbit\Sdk\Responses\GatewayReleases\GatewayReleaseAutomationResponse;
use Orbit\Sdk\Responses\GatewayReleases\GatewayReleaseResponse;
use Orbit\Sdk\Responses\GatewayReleases\GatewayReleaseSmokeResponse;
use Orbit\Sdk\Responses\GatewayReleases\GatewayReleasesResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;

/** Replays a recorded Gateway release response through the request and returns the mock client. */
function gateway_release_fixture(Request $request, string $name): MockClient
{
    $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 4)."/fixtures/gateway-releases/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);

    return new MockClient([$request::class => MockResponse::make($fixture['body'], $fixture['status'])]);
}

function gateway_release_send(Request $request, string $fixture): mixed
{
    $connector = new GatewayConnector('https://10.70.0.1');
    $connector->withMockClient(gateway_release_fixture($request, $fixture));

    return $connector->send($request)->dto();
}

describe('Gateway release requests', function (): void {
    it('maps each operation to its route, method, and body', function (Request $request, Method $method, string $path, ?array $body): void {
        expect($request->getMethod())->toBe($method)
            ->and($request->resolveEndpoint())->toBe($path)
            ->and(method_exists($request, 'body') ? $request->body()->all() : null)->toBe($body);
    })->with([
        'list' => [new ListGatewayReleasesRequest, Method::GET, '/api/v1/gateway/releases', null],
        'show record' => [new ShowGatewayReleaseRequest('42'), Method::GET, '/api/v1/gateway/releases/42', null],
        'show commit' => [new ShowGatewayReleaseRequest('fedcba9'), Method::GET, '/api/v1/gateway/releases/fedcba9', null],
        'deploy' => [new DeployGatewayReleaseRequest('fedcba9'), Method::POST, '/api/v1/gateway/releases', ['commit' => 'fedcba9']],
        'rollback' => [new RollbackGatewayReleaseRequest('fedcba987654'), Method::POST, '/api/v1/gateway/releases/fedcba987654/rollback', ['force' => false]],
        'forced rollback' => [new RollbackGatewayReleaseRequest('fedcba987654', force: true), Method::POST, '/api/v1/gateway/releases/fedcba987654/rollback', ['force' => true]],
        'status' => [new ShowGatewayReleaseAutomationRequest, Method::GET, '/api/v1/gateway/release-automation', null],
        'enable' => [new EnableGatewayReleaseAutomationRequest, Method::POST, '/api/v1/gateway/release-automation/enable', null],
        'disable' => [new DisableGatewayReleaseAutomationRequest, Method::POST, '/api/v1/gateway/release-automation/disable', null],
        'resume' => [new ResumeGatewayReleaseAutomationRequest, Method::POST, '/api/v1/gateway/release-automation/resume', null],
        'smoke' => [new SmokeGatewayReleaseRequest, Method::POST, '/api/v1/gateway/release-smoke', ['commit' => null, 'since' => null]],
        'smoke commit' => [new SmokeGatewayReleaseRequest('fedcba9', '2026-10-07T06:00:00Z'), Method::POST, '/api/v1/gateway/release-smoke', ['commit' => 'fedcba9', 'since' => '2026-10-07T06:00:00Z']],
    ]);

    it('refuses selectors the Gateway would refuse', function (Closure $request): void {
        expect($request)->toThrow(InvalidArgumentException::class);
    })->with([
        'branch deploy' => [static fn () => new DeployGatewayReleaseRequest('main')],
        'short deploy' => [static fn () => new DeployGatewayReleaseRequest('abc12')],
        'path show' => [static fn () => new ShowGatewayReleaseRequest('../status')],
        'short rollback' => [static fn () => new RollbackGatewayReleaseRequest('fedcba9')],
        'branch smoke' => [static fn () => new SmokeGatewayReleaseRequest('main')],
    ]);

    it('reads a passed and a failed smoke run from recorded responses', function (): void {
        $passed = gateway_release_send(new SmokeGatewayReleaseRequest, 'gateway-release-smoke/passed');
        $failed = gateway_release_send(new SmokeGatewayReleaseRequest, 'gateway-release-smoke/failed');

        expect($passed)->toBeInstanceOf(GatewayReleaseSmokeResponse::class)
            ->and($passed->passed())->toBeTrue()
            ->and($passed->release)->toBe('0123456789ab')
            ->and(array_keys($passed->checks()))->toBe(['deploy_verify', 'node_list', 'tasks_list', 'web', 'scheduler', 'tasks_tick', 'agent_view', 'documents'])
            ->and($failed->passed())->toBeFalse()
            ->and($failed->checks()['web'])->toMatchArray(['status' => 'failed', 'error' => 'web_release_mismatch'])
            ->and($failed->report['failed_checks'] ?? null)->toBe(['web', 'scheduler'])
            ->and($failed->toArray()['request_id'])->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a844');
    });

    it('reads a queued deploy and a finished record from recorded responses', function (): void {
        $queued = gateway_release_send(new DeployGatewayReleaseRequest(str_repeat('fedcba9876543210', 2).'fedcba98'), 'gateway-release-deploy/queued');
        $verified = gateway_release_send(new ShowGatewayReleaseRequest('1'), 'gateway-release-show/verified');

        expect($queued)->toBeInstanceOf(GatewayReleaseResponse::class)
            ->and($queued->outcome)->toBe('queued')
            ->and($queued->finished)->toBeFalse()
            ->and($queued->phases)->toBe([])
            ->and(json_encode($queued->toArray()['phases']))->toBe('{}')
            ->and($queued->requestId)->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a844')
            ->and($verified->succeeded())->toBeTrue()
            ->and(array_keys($verified->phases))->toBe(['prepare', 'guard', 'configuration', 'snapshot', 'migrate', 'switch', 'handoff', 'verify', 'scheduler', 'web', 'smoke', 'tick']);
    });

    it('lists records newest first', function (): void {
        $list = gateway_release_send(new ListGatewayReleasesRequest, 'gateway-release-list/default');

        expect($list)->toBeInstanceOf(GatewayReleasesResponse::class)
            ->and(array_map(static fn (GatewayReleaseResponse $release): int => $release->id, $list->releases))->toBe([4, 3, 2, 1])
            ->and($list->releases[1]->sha)->toBeNull()
            ->and($list->releases[1]->requested)->toBe('fedcba9')
            ->and($list->releases[2]->alert['kind'] ?? null)->toBe('release_failed');
    });

    it('reads the automatic release state with its pause and last tick', function (): void {
        $paused = gateway_release_send(new ShowGatewayReleaseAutomationRequest, 'gateway-release-auto-status/paused');

        expect($paused)->toBeInstanceOf(GatewayReleaseAutomationResponse::class)
            ->and($paused->enabled)->toBeTrue()
            ->and($paused->paused)->toBeTrue()
            ->and($paused->pause['reason'] ?? null)->toBe('migration_failure')
            ->and($paused->lastTick['result'] ?? null)->toBe('paused')
            ->and($paused->currentRelease)->toBe('0123456789ab')
            ->and($paused->tickConfirmation['outcome'] ?? null)->toBe('pending')
            ->and($paused->toArray()['tick_confirmation']['deadline'] ?? null)->toBe('2026-10-07T11:04:10Z');
    });

    it('raises the Gateway error code of a refused request', function (): void {
        $failure = null;

        try {
            gateway_release_send(new RollbackGatewayReleaseRequest('fedcba987654'), 'gateway-release-rollback/migration-crossed');
        } catch (GatewayApiException $exception) {
            $failure = $exception;
        }

        expect($failure?->errorCode())->toBe('gateway.release_migration_crossed');
    });

    it('rejects a release record without an id or an outcome', function (): void {
        $connector = new GatewayConnector('https://10.70.0.1');
        $connector->withMockClient(new MockClient([ShowGatewayReleaseRequest::class => MockResponse::make([
            'data' => ['id' => '1', 'outcome' => 'verified', 'trigger' => 'deploy', 'finished' => true],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ])]));

        expect(fn () => $connector->send(new ShowGatewayReleaseRequest('1'))->dto())->toThrow(InvalidArgumentException::class);
    });

    it('reads the release and automatic release state from Gateway status', function (): void {
        $connector = new GatewayConnector('https://10.70.0.1');
        $connector->withMockClient(new MockClient([ShowGatewayStatusRequest::class => MockResponse::make([
            'data' => [
                'name' => 'orbit-gateway',
                'status' => 'ok',
                'version' => '0123456789ab0123456789ab0123456789ab0123',
                'php_version' => '8.5.8',
                'laravel_version' => '13.26.1',
                'release' => '0123456789ab',
                'release_sha' => '0123456789ab0123456789ab0123456789ab0123',
                'auto_release' => ['enabled' => true, 'paused' => false, 'last_checked_at' => '2026-10-07T12:00:00Z', 'last_result' => 'up_to_date'],
            ],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ])]));

        $status = $connector->send(new ShowGatewayStatusRequest)->dto();

        expect($status->release)->toBe('0123456789ab')
            ->and($status->releaseSha)->toBe('0123456789ab0123456789ab0123456789ab0123')
            ->and($status->autoRelease)->toBe(['enabled' => true, 'paused' => false, 'last_checked_at' => '2026-10-07T12:00:00Z', 'last_result' => 'up_to_date']);
    });
});
