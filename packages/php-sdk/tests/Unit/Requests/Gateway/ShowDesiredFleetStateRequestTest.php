<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Gateway\ShowDesiredFleetStateRequest;
use Orbit\Sdk\Requests\Gateway\ShowGatewayStatusRequest;
use Orbit\Sdk\Responses\Gateway\DesiredFleetStateResponse;
use Orbit\Sdk\Responses\Gateway\GatewayStatusResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/** @return array<string, mixed> */
function desired_fleet_state_fixture_body(string $name): array
{
    $fixture = json_decode((string) file_get_contents(dirname(__DIR__, 4)."/fixtures/gateway/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);

    return $fixture['body'];
}

function send_desired_fleet_state(array $body, string $request = ShowDesiredFleetStateRequest::class): object
{
    $mockClient = new MockClient([$request => MockResponse::make($body)]);
    $connector = new GatewayConnector('https://10.70.0.1');
    $connector->withMockClient($mockClient);

    return $connector->send(new $request)->dto();
}

describe(ShowDesiredFleetStateRequest::class, function (): void {
    it('reads the recorded desired state with a published CLI release', function (): void {
        $body = desired_fleet_state_fixture_body('self-update/available');
        $mockClient = new MockClient([ShowDesiredFleetStateRequest::class => MockResponse::make($body)]);
        $connector = new GatewayConnector('https://10.70.0.1');
        $connector->withMockClient($mockClient);

        $state = $connector->send(new ShowDesiredFleetStateRequest)->dto();

        expect($mockClient->getLastRequest()?->resolveEndpoint())->toBe('/api/v1/gateway/desired-fleet-state')
            ->and($mockClient->getLastRequest()?->getMethod())->toBe(Method::GET)
            ->and($state)->toBeInstanceOf(DesiredFleetStateResponse::class)
            ->and($state->toArray())->toBe($body['data'])
            ->and($state->requestId)->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a844')
            ->and($state->cli->isAvailable())->toBeTrue()
            ->and($state->cli->asset('macos-arm64')?->name)->toBe('orbit-0.4681.0-macos-arm64')
            ->and($state->cli->asset('windows-x86_64'))->toBeNull()
            ->and($state->agent->asset('linux-aarch64')?->sha256)->toBe('84306df202904277c6f6cd78d4050fae97e54c3e515a2ad09585dd5a5e568811');
    });

    it('reads the recorded desired state while the CLI release is pending', function (): void {
        $body = desired_fleet_state_fixture_body('self-update/pending');

        $state = send_desired_fleet_state($body);

        expect($state->toArray())->toBe($body['data'])
            ->and($state->cli->isAvailable())->toBeFalse()
            ->and($state->cli->isPending())->toBeTrue()
            ->and($state->cli->version)->toBe('0.4681.0')
            ->and($state->cli->reason)->toBe('release_missing');
    });

    it('rejects a desired state it cannot trust', function (Closure $change): void {
        $body = desired_fleet_state_fixture_body('self-update/available');
        $body['data'] = $change($body['data']);

        expect(fn (): object => send_desired_fleet_state($body))->toThrow(InvalidArgumentException::class);
    })->with([
        'short commit' => [static fn (array $data): array => [...$data, 'commit' => 'abc1234']],
        'plain HTTP download' => [static function (array $data): array {
            $data['cli']['assets'][0]['url'] = 'http://github.com/orbit';

            return $data;
        }],
        'uppercase checksum' => [static function (array $data): array {
            $data['cli']['assets'][0]['sha256'] = strtoupper($data['cli']['assets'][0]['sha256']);

            return $data;
        }],
        'tag of another version' => [static function (array $data): array {
            $data['cli']['tag'] = 'cli-v0.1.0';

            return $data;
        }],
        'unavailable without a reason' => [static fn (array $data): array => [...$data, 'cli' => ['status' => 'unavailable', 'reason' => null]]],
        'pending without a version' => [static fn (array $data): array => [...$data, 'cli' => ['status' => 'pending', 'reason' => 'release_missing', 'version' => null, 'tag' => null]]],
        'missing agent' => [static function (array $data): array {
            unset($data['agent']);

            return $data;
        }],
    ]);
});

describe('gateway:status desired fleet state', function (): void {
    it('reads the desired state an active peer receives', function (): void {
        $body = desired_fleet_state_fixture_body('gateway-status/peer');

        $status = send_desired_fleet_state($body, ShowGatewayStatusRequest::class);

        expect($status)->toBeInstanceOf(GatewayStatusResponse::class)
            ->and($status->toArray()['desired_fleet_state'])->toBe($body['data']['desired_fleet_state']);
    });

    it('leaves out a desired state that is absent or malformed', function (mixed $desired): void {
        $body = desired_fleet_state_fixture_body('gateway-status/peer');
        $body['data']['desired_fleet_state'] = $desired;

        expect(send_desired_fleet_state($body, ShowGatewayStatusRequest::class)->desiredFleetState)->toBeNull();
    })->with([
        'anonymous caller' => [null],
        'malformed' => [['commit' => 'nope']],
    ]);
});
