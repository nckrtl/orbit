<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Nodes\AddNodeRequest;
use Orbit\Sdk\Requests\Nodes\RemoveNodeRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Orbit\Sdk\Responses\Nodes\RemovedNodeResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('macOS enrollment transport', function (): void {
    it('sends the documented enrollment fields and omits the ones the caller left out', function (): void {
        $fingerprint = 'SHA256:'.str_repeat('M', 43);
        $request = new AddNodeRequest(
            name: 'mini',
            publicSshHost: '192.0.2.40',
            user: 'mini',
            orbitUser: 'mini',
            wireguardIp: '10.44.0.40',
            hostKeyFingerprint: $fingerprint,
            platform: 'macos',
        );

        expect($request->resolveEndpoint())
            ->toBe('/api/v1/nodes')
            ->and($request->body()->all())
            ->toBe([
                'name' => 'mini',
                'public_ssh_host' => '192.0.2.40',
                'platform' => 'macos',
                'public_ssh_port' => 22,
                'user' => 'mini',
                'orbit_user' => 'mini',
                'roles' => [],
                'wireguard_ip' => '10.44.0.40',
                'host_key_fingerprint' => $fingerprint,
            ])
            ->and($request->body()->all())
            ->not->toHaveKey('architecture');
    });

    it('preserves an observed architecture and does not apply enrollment policy', function (): void {
        $fingerprint = 'SHA256:'.str_repeat('M', 43);
        $request = new AddNodeRequest(
            name: 'mini',
            publicSshHost: '192.0.2.40',
            roles: ['app-dev'],
            user: 'mini',
            orbitUser: 'other',
            wireguardIp: '10.44.0.40',
            dnsServerOverride: '10.0.0.2',
            hostKeyFingerprint: $fingerprint,
            platform: 'macos',
            architecture: 'arm64',
        );

        expect($request->body()->all())->toBe([
            'name' => 'mini',
            'public_ssh_host' => '192.0.2.40',
            'platform' => 'macos',
            'architecture' => 'arm64',
            'public_ssh_port' => 22,
            'user' => 'mini',
            'orbit_user' => 'other',
            'roles' => ['app-dev'],
            'wireguard_ip' => '10.44.0.40',
            'dns_server_override' => '10.0.0.2',
            'host_key_fingerprint' => $fingerprint,
        ]);
    });

    it('omits an account the caller did not supply', function (): void {
        $request = new AddNodeRequest(
            name: 'mini',
            publicSshHost: '192.0.2.40',
            wireguardIp: '10.44.0.40',
            hostKeyFingerprint: 'SHA256:'.str_repeat('M', 43),
            platform: 'macos',
        );

        expect($request->body()->all())
            ->not->toHaveKey('user')
            ->and($request->body()->all())
            ->not->toHaveKey('orbit_user');
    });

    it('maps the recorded enrollment response without rewriting the account or architecture', function (): void {
        $response = macos_fixture_send('nodes/node-add/macos-enrolled', new AddNodeRequest(
            name: 'mini',
            publicSshHost: '192.0.2.40',
            platform: 'macos',
        ));
        $recorded = macos_fixture('nodes/node-add/macos-enrolled');

        expect($response)
            ->toBeInstanceOf(NodeResponse::class)
            ->and($response->platform)
            ->toBe('macos')
            ->and($response->architecture)
            ->toBe('arm64')
            ->and($response->user)
            ->toBe('mini')
            ->and($response->roles)
            ->toBe([])
            ->and($response->status)
            ->toBe('active')
            ->and($response->sshHostFingerprint)
            ->toBe('SHA256:'.str_repeat('M', 43))
            ->and($response->wireguardIp)
            ->toBe('10.44.0.40')
            ->and($response->toArray())
            ->toMatchArray($recorded['body']['data'])
            ->and($response->requestId)
            ->toBe($recorded['body']['meta']['request_id']);
    });

    it('maps the recorded removal response and keeps the retained host state', function (): void {
        $response = macos_fixture_send('nodes/node-remove/macos-removed', new RemoveNodeRequest(2, force: true));
        $recorded = macos_fixture('nodes/node-remove/macos-removed');

        expect($response)
            ->toBeInstanceOf(RemovedNodeResponse::class)
            ->and($response->removed)
            ->toBeTrue()
            ->and($response->degradation)
            ->toBeNull()
            ->and($response->followUp)
            ->toBeNull()
            ->and($response->retainedOnNode)
            ->toBe(['user', 'package-managers', 'host-wireguard'])
            ->and($response->toArray())
            ->toBe([...$recorded['body']['data'], 'request_id' => $recorded['body']['meta']['request_id']]);
    });

    it('preserves a recorded enrollment refusal without rewriting the code', function (string $fixture): void {
        $recorded = macos_fixture($fixture);
        $error = $recorded['body']['error'];

        try {
            macos_fixture_send($fixture, new AddNodeRequest(
                name: 'mini',
                publicSshHost: '192.0.2.40',
                platform: 'macos',
            ));
            throw new RuntimeException('Expected the recorded refusal.');
        } catch (GatewayApiException $exception) {
            expect($exception->errorCode())
                ->toBe($error['code'])
                ->and($exception->getMessage())
                ->toBe($error['message'])
                ->and($exception->details())
                ->toBe($error['details']);
        }
    })->with([
        'missing account' => 'nodes/node-add/macos-account-required',
        'mixed accounts' => 'nodes/node-add/macos-account-mismatch',
        'service role' => 'nodes/node-add/macos-role-unsupported',
        'settings change' => 'nodes/node-add/macos-settings-unsupported',
        'missing tunnel' => 'nodes/node-add/macos-wireguard-required',
    ]);
});

/** @return array<string, mixed> */
function macos_fixture(string $name): array
{
    $decoded = json_decode(
        (string) file_get_contents(dirname(__DIR__, levels: 4)."/fixtures/{$name}.json"),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    assert(is_array($decoded));

    return $decoded;
}

function macos_fixture_send(string $fixture, GatewayRequest $request): object
{
    $recorded = macos_fixture($fixture);
    $mockClient = new MockClient([
        $request::class => MockResponse::make($recorded['body'], $recorded['status']),
    ]);
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient($mockClient);

    $dto = $connector->send($request)->dto();
    assert(is_object($dto));

    return $dto;
}
