<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\Nodes\RenameNodeRequest;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-node-rename-'.Str::uuid();
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

it('registers the exact node rename command signature surface', function (): void {
    $command = app(Kernel::class)->all()['node:rename'] ?? null;

    expect($command)
        ->toBeInstanceOf(SymfonyCommand::class)
        ->and(array_keys($command?->getDefinition()->getArguments() ?? []))
        ->toBe(['node', 'name'])
        ->and($command?->getDefinition()->getArgument('node')->getDescription())
        ->toBe('Node ID or name')
        ->and(rename_node_command_options($command))
        ->toBe([
            'json' => false,
        ]);
});

it('sends one node rename request as json', function (): void {
    $mockClient = MockClient::global([
        RenameNodeRequest::class => MockResponse::make([
            'data' => renamed_node_payload(),
            'meta' => ['request_id' => rename_node_request_id()],
        ]),
    ]);
    $expected = json_encode(renamed_node_expected_json(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this
        ->artisan('node:rename', ['node' => '1', 'name' => 'vpn', '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(0);

    $request = $mockClient->getLastRequest();

    expect($request)
        ->toBeInstanceOf(RenameNodeRequest::class)
        ->and($request?->getMethod())
        ->toBe(Method::PATCH)
        ->and($request?->resolveEndpoint())
        ->toBe('/api/v1/nodes/1/name')
        ->and($request?->body()->all())
        ->toBe(['name' => 'vpn']);
});

it('shows deterministic human output for a renamed node', function (): void {
    MockClient::global([
        RenameNodeRequest::class => MockResponse::make([
            'data' => renamed_node_payload(),
            'meta' => ['request_id' => rename_node_request_id()],
        ]),
    ]);

    $this
        ->artisan('node:rename', ['node' => '1', 'name' => 'vpn'])
        ->expectsOutput('Node #1 renamed to [vpn].')
        ->expectsOutput('Request ID: '.rename_node_request_id())
        ->assertExitCode(0);
});

it('resolves a node name through the node list before renaming', function (): void {
    $mockClient = MockClient::global([
        ListNodesRequest::class => MockResponse::make([
            'data' => [renamed_node_payload(['name' => 'gateway'])],
            'meta' => ['request_id' => rename_node_request_id()],
        ]),
        RenameNodeRequest::class => MockResponse::make([
            'data' => renamed_node_payload(),
            'meta' => ['request_id' => rename_node_request_id()],
        ]),
    ]);

    $this
        ->artisan('node:rename', ['node' => 'gateway', 'name' => 'vpn', '--json' => true])
        ->assertExitCode(0);

    expect($mockClient->getLastRequest()?->resolveEndpoint())->toBe('/api/v1/nodes/1/name');
});

it('rejects an empty name before connector io', function (): void {
    $mockClient = MockClient::global();

    $this
        ->artisan('node:rename', ['node' => '1', 'name' => ''])
        ->expectsOutputToContain('Name is required.')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
});

it('renders gateway-owned rename failures through the shared boundary', function (): void {
    $expected = json_encode([
        'error' => [
            'code' => 'node.has_herdr_sessions',
            'message' => 'Node [gateway] still owns Herdr sessions. Observer hostnames embed the Node name. Destroy those sessions, then rename, then recreate them.',
            'request_id' => rename_node_request_id(),
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    MockClient::global([
        RenameNodeRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'node.has_herdr_sessions',
                    'message' => 'Node [gateway] still owns Herdr sessions. Observer hostnames embed the Node name. Destroy those sessions, then rename, then recreate them.',
                ],
            ],
            409,
            ['X-Orbit-Request-Id' => rename_node_request_id()],
        ),
    ]);

    $this
        ->artisan('node:rename', ['node' => '1', 'name' => 'vpn', '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(1);
});

/** @return array<string, mixed> */
function rename_node_command_options(?SymfonyCommand $command): array
{
    if (! $command instanceof SymfonyCommand) {
        return [];
    }

    return collect($command->getDefinition()->getOptions())
        ->except([
            'help',
            'silent',
            'quiet',
            'verbose',
            'version',
            'ansi',
            'no-ansi',
            'no-interaction',
            'env',
        ])
        ->map(static fn ($option): mixed => $option->getDefault())
        ->all();
}

/** @param array<string, mixed> $overrides */
function renamed_node_payload(array $overrides = []): array
{
    return [
        'id' => 1,
        'name' => 'vpn',
        'status' => 'active',
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'os_version' => null,
        'tld' => null,
        'public_ssh_host' => '94.237.46.255',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.1',
        'wireguard_public_key' => 'key',
        'wireguard_endpoint_override' => null,
        'dns_server_override' => null,
        'ssh_host_fingerprint' => null,
        'failed_step' => null,
        'error_code' => null,
        'roles' => ['gateway', 'vpn'],
        ...$overrides,
    ];
}

/** @return array<string, mixed> */
function renamed_node_expected_json(): array
{
    return [
        'id' => 1,
        'cluster_id' => null,
        'name' => 'vpn',
        'status' => 'active',
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'os_version' => null,
        'tld' => null,
        'public_ssh_host' => '94.237.46.255',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.1',
        'lan_ip' => null,
        'wireguard_public_key' => 'key',
        'wireguard_endpoint_override' => null,
        'dns_server_override' => null,
        'ssh_host_fingerprint' => null,
        'failed_step' => null,
        'error_code' => null,
        'roles' => ['gateway', 'vpn'],
        'settings' => null,
        'request_id' => rename_node_request_id(),
    ];
}

function rename_node_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
