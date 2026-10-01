<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\DatabaseServers\CreateDatabaseServerRequest;
use Orbit\Sdk\Requests\DatabaseServers\DestroyDatabaseServerRequest;
use Orbit\Sdk\Requests\DatabaseServers\ListDatabaseServersRequest;
use Orbit\Sdk\Requests\DatabaseServers\ShowDatabaseServerRequest;
use Orbit\Sdk\Responses\DatabaseServers\DatabaseServerResponse;
use Orbit\Sdk\Responses\DatabaseServers\DatabaseServersResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/** @return array<string, mixed> */
function database_server_sdk_payload(array $overrides = []): array
{
    return [
        'id' => 1,
        'slug' => 'beast-mysql',
        'node_id' => 2,
        'process_id' => 3,
        'tag' => '8.4',
        'port' => 3306,
        'status' => 'active',
        'databases_count' => 0,
        ...$overrides,
    ];
}

describe('database server requests', function (): void {
    it('uses the server methods and endpoints', function (GatewayRequest $request, Method $method, string $endpoint): void {
        expect($request->getMethod())->toBe($method)
            ->and($request->resolveEndpoint())->toBe($endpoint);
    })->with([
        'list' => [new ListDatabaseServersRequest, Method::GET, '/api/v1/database-servers'],
        'show' => [new ShowDatabaseServerRequest('beast-mysql'), Method::GET, '/api/v1/database-servers/beast-mysql'],
        'create' => [new CreateDatabaseServerRequest('beast-mysql', 2), Method::POST, '/api/v1/database-servers'],
        'destroy' => [new DestroyDatabaseServerRequest('beast-mysql'), Method::DELETE, '/api/v1/database-servers/beast-mysql'],
    ]);

    it('sends the tag and port only when given', function (): void {
        expect(new CreateDatabaseServerRequest('beast-mysql', 2)->body()->all())
            ->toBe(['slug' => 'beast-mysql', 'node_id' => 2])
            ->and(new CreateDatabaseServerRequest('beast-mysql', 2, '8.0', 3307)->body()->all())
            ->toBe(['slug' => 'beast-mysql', 'node_id' => 2, 'tag' => '8.0', 'port' => 3307]);
    });

    it('maps a shown server with its databases and a list of servers', function (): void {
        $connector = new GatewayConnector('https://10.44.0.1', '/tmp/orbit-ca.pem');
        $connector->withMockClient(new MockClient([
            ShowDatabaseServerRequest::class => MockResponse::make([
                'data' => database_server_sdk_payload([
                    'databases_count' => 1,
                    'databases' => [[
                        'id' => 5,
                        'slug' => 'dlf-leden',
                        'driver' => 'mysql',
                        'node_id' => 2,
                        'host' => '10.44.0.80',
                        'port' => 3306,
                        'database' => 'dlf_leden',
                        'path' => null,
                        'username' => 'dlf_main',
                        'has_password' => true,
                        'server' => 'beast-mysql',
                        'owner_instance_id' => 7,
                        'test_database' => 'dlf_leden_test',
                    ]],
                ]),
                'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
            ]),
            ListDatabaseServersRequest::class => MockResponse::make([
                'data' => [database_server_sdk_payload()],
                'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
            ]),
        ]));

        $server = $connector->send(new ShowDatabaseServerRequest('beast-mysql'))->dto();
        $servers = $connector->send(new ListDatabaseServersRequest)->dto();

        expect($server)->toBeInstanceOf(DatabaseServerResponse::class)
            ->and($server->databases)->toHaveCount(1)
            ->and($server->databases[0]->testDatabase ?? null)->toBe('dlf_leden_test')
            ->and($server->toArray()['databases'][0])->not->toHaveKey('request_id')
            ->and($server->requestId)->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a844')
            ->and($servers)->toBeInstanceOf(DatabaseServersResponse::class)
            ->and($servers->toArray()['servers'][0])->toBe(database_server_sdk_payload());
    });

    it('rejects a malformed server', function (array $payload): void {
        expect(fn () => DatabaseServerResponse::fromGatewayData($payload, 'request-1'))
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'bad port' => [database_server_sdk_payload(['port' => 0])],
        'bad status' => [database_server_sdk_payload(['status' => 'running'])],
        'bad tag' => [database_server_sdk_payload(['tag' => '-8.4'])],
        'bad slug' => [database_server_sdk_payload(['slug' => 'Beast'])],
        'missing node' => [database_server_sdk_payload(['node_id' => null])],
    ]);
});
