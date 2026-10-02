<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\DatabaseServers\CreateDatabaseServerRequest;
use Orbit\Sdk\Requests\DatabaseServers\DestroyDatabaseServerRequest;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-database-server-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);

    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

/** @return array<string, int|string|null> */
function database_server_cli_data(): array
{
    return [
        'id' => 1,
        'slug' => 'beast-mysql',
        'node_id' => 7,
        'process_id' => 3,
        'tag' => '8.4',
        'port' => 3306,
        'status' => 'active',
        'databases_count' => 0,
    ];
}

function database_server_cli_response(int $status = 200): MockResponse
{
    return MockResponse::make([
        'data' => database_server_cli_data(),
        'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
    ], $status);
}

describe('database:server commands', function (): void {
    it('resolves the Node by name and sends only the given tag and port', function (): void {
        $mockClient = MockClient::global([
            ListNodesRequest::class => MockResponse::make([
                'data' => [[
                    'id' => 7,
                    'name' => 'beast',
                    'status' => 'active',
                    'platform' => 'linux',
                    'public_ssh_host' => '192.0.2.80',
                    'public_ssh_port' => 22,
                    'user' => 'orbit',
                    'wireguard_ip' => '10.44.0.80',
                    'roles' => [],
                ]],
                'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
            ]),
            CreateDatabaseServerRequest::class => database_server_cli_response(201),
        ]);

        $this
            ->artisan('database:server:create', ['slug' => 'beast-mysql', '--node' => 'beast', '--port' => '3307', '--json' => true])
            ->expectsOutput(json_encode([...database_server_cli_data(), 'request_id' => '11111111-1111-4111-8111-111111111111'], JSON_THROW_ON_ERROR))
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())
            ->toBe(['slug' => 'beast-mysql', 'node_id' => 7, 'port' => 3307]);
    });

    it('sends the MySQL image tag as tag', function (): void {
        $mockClient = MockClient::global([CreateDatabaseServerRequest::class => database_server_cli_response(201)]);

        $this->artisan('database:server:create', ['slug' => 'beast-mysql', '--node' => '7', '--tag' => '8.0', '--json' => true])
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())
            ->toBe(['slug' => 'beast-mysql', 'node_id' => 7, 'tag' => '8.0']);
    });

    it('refuses a JSON destroy without --force and destroys after it', function (): void {
        $mockClient = MockClient::global([DestroyDatabaseServerRequest::class => database_server_cli_response()]);
        $tester = new CommandTester(app(Kernel::class)->all()['database:server:destroy']);

        expect($tester->execute(['slug' => 'beast-mysql', '--json' => true], ['interactive' => false]))->toBe(1)
            ->and($tester->getDisplay(true))->toContain('database.confirmation_required')
            ->and($mockClient->getLastPendingRequest())->toBeNull();

        $this->artisan('database:server:destroy', ['slug' => 'beast-mysql', '--force' => true, '--json' => true])
            ->assertExitCode(0);

        expect($mockClient->getLastRequest())->toBeInstanceOf(DestroyDatabaseServerRequest::class);
    });
});
