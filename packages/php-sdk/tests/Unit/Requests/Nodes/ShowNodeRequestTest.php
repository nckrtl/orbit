<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\ShowNodeRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe(ShowNodeRequest::class, function (): void {
    it('uses the numeric node ID and maps every public node field', function (): void {
        $mockClient = new MockClient([
            ShowNodeRequest::class => MockResponse::make([
                'data' => [
                    'id' => 12,
                    'name' => 'operator',
                    'status' => 'active',
                    'platform' => 'ubuntu',
                    'architecture' => 'x86_64',
                    'public_ssh_host' => '94.237.108.25',
                    'public_ssh_port' => 22,
                    'ssh_user' => 'orbit',
                    'wireguard_address' => '10.44.0.2',
                    'ssh_host_fingerprint' => 'SHA256:operator',
                    'failed_step' => null,
                    'error_code' => null,
                    'roles' => ['app-dev'],
                ],
                'meta' => ['request_id' => '0198e15d-16c4-7855-8eb2-182b53ad28ba'],
            ]),
        ]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);

        $response = $connector->send(new ShowNodeRequest(12))->dto();
        $request = $mockClient->getLastRequest();

        expect($request?->getMethod())
            ->toBe(Method::GET)
            ->and($request?->resolveEndpoint())
            ->toBe('/api/v1/nodes/12')
            ->and($response)
            ->toBeInstanceOf(NodeResponse::class)
            ->and($response->id)
            ->toBe(12)
            ->and($response->platform)
            ->toBe('ubuntu')
            ->and($response->architecture)
            ->toBe('x86_64')
            ->and($response->sshHostFingerprint)
            ->toBe('SHA256:operator')
            ->and($response->roles)
            ->toBe(['app-dev'])
            ->and($response->requestId)
            ->toBe('0198e15d-16c4-7855-8eb2-182b53ad28ba');
    });
});
