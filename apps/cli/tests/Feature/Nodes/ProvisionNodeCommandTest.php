<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\ProvisionNodeRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

it('sends node provisioning to the active gateway', function (): void {
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
    $mockClient = MockClient::global([
        '*/api/v1/nodes' => MockResponse::make([
            'data' => [
                'id' => 1,
                'name' => 'app-dev',
                'status' => 'active',
                'platform' => 'linux',
                'architecture' => 'x86_64',
                'tld' => 'app-dev.orbit',
                'public_ssh_host' => '94.237.40.75',
                'public_ssh_port' => 22,
                'user' => 'orbit',
                'wireguard_address' => '10.44.0.2',
                'wireguard_public_key' => 'app-dev-public-key',
                'wireguard_endpoint_override' => '10.0.0.2:51820',
                'dns_server_override' => '10.0.0.2',
                'ssh_host_fingerprint' => 'SHA256:app-dev',
                'failed_step' => null,
                'error_code' => null,
                'roles' => ['app-dev'],
            ],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ], 201),
    ]);
    $expected = json_encode([
        'id' => 1,
        'name' => 'app-dev',
        'status' => 'active',
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'tld' => 'app-dev.orbit',
        'public_ssh_host' => '94.237.40.75',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_address' => '10.44.0.2',
        'wireguard_public_key' => 'app-dev-public-key',
        'wireguard_endpoint_override' => '10.0.0.2:51820',
        'dns_server_override' => '10.0.0.2',
        'ssh_host_fingerprint' => 'SHA256:app-dev',
        'failed_step' => null,
        'error_code' => null,
        'roles' => ['app-dev'],
        'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this
        ->artisan('node:provision', [
            'name' => 'app-dev',
            'host' => '94.237.40.75',
            '--role' => ['app-dev'],
            '--platform' => 'linux',
            '--architecture' => 'x86_64',
            '--tld' => '.App-Dev.Orbit',
            '--wireguard-address' => '10.44.0.2',
            '--wireguard-endpoint' => '10.0.0.2:51820',
            '--dns-server' => '10.0.0.2',
            '--host-key-fingerprint' => 'SHA256:5jCWsPXzMnd5zy5xVxZ2gzyjH9N3wVfL6n5X0M8W3uQ',
            '--json' => true,
        ])
        ->expectsOutput($expected)
        ->assertExitCode(0);

    $request = $mockClient->getLastRequest();

    expect($request)
        ->toBeInstanceOf(ProvisionNodeRequest::class)
        ->and($request?->body()->all())
        ->toBe([
            'name' => 'app-dev',
            'public_ssh_host' => '94.237.40.75',
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'tld' => '.App-Dev.Orbit',
            'public_ssh_port' => 22,
            'user' => 'root',
            'roles' => ['app-dev'],
            'wireguard_address' => '10.44.0.2',
            'wireguard_endpoint_override' => '10.0.0.2:51820',
            'dns_server_override' => '10.0.0.2',
            'host_key_fingerprint' => 'SHA256:5jCWsPXzMnd5zy5xVxZ2gzyjH9N3wVfL6n5X0M8W3uQ',
        ]);
});

it('passes bootstrap and managed users to the SDK', function (): void {
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
    ));
    $mockClient = MockClient::global([
        '*/api/v1/nodes' => MockResponse::make([
            'data' => [
                'id' => 1,
                'name' => 'app-dev',
                'status' => 'active',
                'public_ssh_host' => '94.237.40.75',
                'public_ssh_port' => 22,
                'user' => 'nckrtl',
                'roles' => [],
            ],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ], 201),
    ]);

    $this->artisan('node:provision', [
        'name' => 'app-dev',
        '--user' => 'nckrtl',
        '--orbit-user' => 'nckrtl',
    ])->assertExitCode(0);

    expect($mockClient->getLastRequest()?->body()->all())
        ->toHaveKey('user', 'nckrtl')
        ->toHaveKey('orbit_user', 'nckrtl');
});

it('rejects non-Linux platform input before making an API request', function (string $platform): void {
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
    $mockClient = MockClient::global();

    $this
        ->artisan('node:provision', [
            'name' => 'app-dev',
            'host' => '94.237.40.75',
            '--platform' => $platform,
        ])
        ->expectsOutputToContain('Platform must be linux.')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'unsupported platform' => 'windows',
    'retired platform' => 'darwin',
]);

it('rejects invalid SSH ports before making an API request', function (string $port): void {
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
    ));
    $mockClient = MockClient::global();
    $expected = json_encode([
        'error' => [
            'code' => 'node.ssh_port_invalid',
            'message' => 'SSH port must be an integer from 1 to 65535.',
            'request_id' => null,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this
        ->artisan('node:provision', [
            'name' => 'app-dev',
            'host' => '94.237.40.75',
            '--ssh-port' => $port,
            '--json' => true,
        ])
        ->expectsOutput($expected)
        ->doesntExpectOutputToContain($port)
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'decimal' => '22.5',
    'scientific notation' => '1e2',
    'zero' => '0',
    'above maximum' => '65536',
]);

it('rejects an invalid host key fingerprint before making an API request', function (): void {
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
    $mockClient = MockClient::global();

    $this
        ->artisan('node:provision', [
            'name' => 'app-dev',
            'host' => '94.237.40.75',
            '--host-key-fingerprint' => 'sha256:not-valid',
        ])
        ->expectsOutputToContain(
            'Host key fingerprint must use SSH SHA256 format: SHA256 followed by 43 base64 characters.',
        )
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
});

it('prints the request ID for provisioning gateway API errors', function (): void {
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
    MockClient::global([
        '*/api/v1/nodes' => MockResponse::make(
            [
                'error' => [
                    'code' => 'node.provision_failed',
                    'message' => 'Node provisioning failed.',
                    'details' => [],
                ],
            ],
            422,
            [
                'X-Orbit-Request-Id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
            ],
        ),
    ]);

    $this
        ->artisan('node:provision', [
            'name' => 'app-dev',
            'host' => '94.237.40.75',
        ])
        ->expectsOutputToContain('Node provisioning failed.')
        ->expectsOutput('Request ID: 0198e15c-bf97-7c23-8f1f-61b8fe67a844')
        ->assertExitCode(1);
});
