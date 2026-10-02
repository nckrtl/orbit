<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\AddNodeRequest;
use Orbit\Sdk\Requests\Nodes\RemoveNodeRequest;
use Orbit\Sdk\Requests\Nodes\ShowNodeRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
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
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

it('sends macOS enrollment fields and leaves policy to the gateway', function (array $options, array $body): void {
    $mockClient = MockClient::global([
        '*/api/v1/nodes' => MockResponse::make([
            'data' => macos_enrolled_node(),
            'meta' => ['request_id' => macos_request_id()],
        ], 201),
    ]);

    $this->artisan('node:add', [
        'name' => 'mini',
        'host' => '192.0.2.40',
        '--platform' => 'macos',
        '--host-key-fingerprint' => macos_fingerprint(),
        ...$options,
        '--json' => true,
    ])->assertExitCode(0);

    expect($mockClient->getLastRequest())
        ->toBeInstanceOf(AddNodeRequest::class)
        ->and($mockClient->getLastRequest()?->body()->all())
        ->toBe($body);
})->with([
    'existing account and tunnel' => [
        [
            '--user' => 'mini',
            '--orbit-user' => 'mini',
            '--wireguard-ip' => '10.44.0.40',
            '--architecture' => 'arm64',
        ],
        [
            'name' => 'mini',
            'public_ssh_host' => '192.0.2.40',
            'platform' => 'macos',
            'architecture' => 'arm64',
            'public_ssh_port' => 22,
            'user' => 'mini',
            'orbit_user' => 'mini',
            'roles' => [],
            'wireguard_ip' => '10.44.0.40',
            'host_key_fingerprint' => macos_fingerprint(),
        ],
    ],
    'omitted account' => [
        ['--wireguard-ip' => '10.44.0.40'],
        [
            'name' => 'mini',
            'public_ssh_host' => '192.0.2.40',
            'platform' => 'macos',
            'public_ssh_port' => 22,
            'roles' => [],
            'wireguard_ip' => '10.44.0.40',
            'host_key_fingerprint' => macos_fingerprint(),
        ],
    ],
    'different accounts' => [
        [
            '--user' => 'mini',
            '--orbit-user' => 'other',
            '--wireguard-ip' => '10.44.0.40',
        ],
        [
            'name' => 'mini',
            'public_ssh_host' => '192.0.2.40',
            'platform' => 'macos',
            'public_ssh_port' => 22,
            'user' => 'mini',
            'orbit_user' => 'other',
            'roles' => [],
            'wireguard_ip' => '10.44.0.40',
            'host_key_fingerprint' => macos_fingerprint(),
        ],
    ],
    'service role' => [
        [
            '--user' => 'mini',
            '--orbit-user' => 'mini',
            '--wireguard-ip' => '10.44.0.40',
            '--role' => ['app-dev'],
        ],
        [
            'name' => 'mini',
            'public_ssh_host' => '192.0.2.40',
            'platform' => 'macos',
            'public_ssh_port' => 22,
            'user' => 'mini',
            'orbit_user' => 'mini',
            'roles' => ['app-dev'],
            'wireguard_ip' => '10.44.0.40',
            'host_key_fingerprint' => macos_fingerprint(),
        ],
    ],
    'dns override' => [
        [
            '--user' => 'mini',
            '--orbit-user' => 'mini',
            '--wireguard-ip' => '10.44.0.40',
            '--dns-server' => '10.0.0.2',
        ],
        [
            'name' => 'mini',
            'public_ssh_host' => '192.0.2.40',
            'platform' => 'macos',
            'public_ssh_port' => 22,
            'user' => 'mini',
            'orbit_user' => 'mini',
            'roles' => [],
            'wireguard_ip' => '10.44.0.40',
            'dns_server_override' => '10.0.0.2',
            'host_key_fingerprint' => macos_fingerprint(),
        ],
    ],
    'omitted tunnel address' => [
        [
            '--user' => 'mini',
            '--orbit-user' => 'mini',
        ],
        [
            'name' => 'mini',
            'public_ssh_host' => '192.0.2.40',
            'platform' => 'macos',
            'public_ssh_port' => 22,
            'user' => 'mini',
            'orbit_user' => 'mini',
            'roles' => [],
            'host_key_fingerprint' => macos_fingerprint(),
        ],
    ],
]);

it('reports retained macOS host state in human and json output', function (): void {
    MockClient::global([
        ShowNodeRequest::class => MockResponse::make([
            'data' => macos_enrolled_node(),
            'meta' => ['request_id' => macos_request_id()],
        ]),
        RemoveNodeRequest::class => MockResponse::make([
            'data' => [
                'id' => 2,
                'name' => 'mini',
                'removed' => true,
                'wireguard_peer_removed' => true,
                'dns_records_removed' => true,
                'degradation' => null,
                'roles_shed' => [],
                'retained_on_node' => ['user', 'package-managers', 'host-wireguard'],
                'follow_up' => null,
            ],
            'meta' => ['request_id' => macos_request_id()],
        ]),
    ]);
    $json = json_encode([
        'id' => 2,
        'name' => 'mini',
        'removed' => true,
        'wireguard_peer_removed' => true,
        'dns_records_removed' => true,
        'degradation' => null,
        'roles_shed' => [],
        'retained_on_node' => ['user', 'package-managers', 'host-wireguard'],
        'follow_up' => null,
        'request_id' => macos_request_id(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this->artisan('node:remove', ['node' => '2', '--force' => true, '--json' => true])
        ->expectsOutput($json)
        ->assertExitCode(0);

    $this->artisan('node:remove', ['node' => '2', '--force' => true])
        ->expectsOutputToContain('Node [mini] removed.')
        ->expectsOutputToContain('Left on the node:')
        ->expectsOutputToContain('user')
        ->expectsOutputToContain('package-managers')
        ->expectsOutputToContain('host-wireguard')
        ->doesntExpectOutputToContain('Warning:')
        ->expectsOutput('Request ID: '.macos_request_id())
        ->assertExitCode(0);
});

it('cancels macOS removal before sending the delete', function (): void {
    $mockClient = MockClient::global([
        ShowNodeRequest::class => MockResponse::make([
            'data' => macos_enrolled_node(),
            'meta' => ['request_id' => macos_request_id()],
        ]),
        RemoveNodeRequest::class => MockResponse::make([
            'data' => ['id' => 2, 'name' => 'mini', 'removed' => true],
            'meta' => ['request_id' => macos_request_id()],
        ]),
    ]);
    $expected = json_encode([
        'error' => [
            'code' => 'node.confirmation_required',
            'message' => 'Use --force to confirm node removal.',
            'request_id' => null,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this->artisan('node:remove', ['node' => '2', '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(1);

    expect($mockClient->getLastRequest())
        ->toBeInstanceOf(ShowNodeRequest::class)
        ->and($mockClient->getRecordedResponses())
        ->toHaveCount(1);
});

/** @return array<string, mixed> */
function macos_enrolled_node(): array
{
    return [
        'id' => 2,
        'name' => 'mini',
        'status' => 'active',
        'platform' => 'macos',
        'architecture' => 'arm64',
        'public_ssh_host' => '192.0.2.40',
        'public_ssh_port' => 22,
        'user' => 'mini',
        'wireguard_ip' => '10.44.0.40',
        'ssh_host_fingerprint' => macos_fingerprint(),
        'roles' => [],
    ];
}

function macos_fingerprint(): string
{
    return 'SHA256:'.str_repeat('M', 43);
}

function macos_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
