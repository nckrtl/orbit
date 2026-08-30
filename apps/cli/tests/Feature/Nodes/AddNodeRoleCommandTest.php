<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\AddNodeRoleRequest;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-node-role-add-'.Str::uuid();
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

it('registers the exact node role add command signature surface', function (): void {
    $command = app(Kernel::class)->all()['node:role:add'] ?? null;

    expect($command)
        ->toBeInstanceOf(SymfonyCommand::class)
        ->and(array_keys($command?->getDefinition()->getArguments() ?? []))
        ->toBe(['node', 'role'])
        ->and(node_role_add_command_options($command))
        ->toBe([
            'converge' => false,
            'json' => false,
        ]);
});

it('rejects an invalid node id before connector io', function (string $nodeId): void {
    $mockClient = MockClient::global();

    $this
        ->artisan('node:role:add', ['node' => $nodeId, 'role' => 'app-dev'])
        ->expectsOutputToContain('Node ID must be a positive integer.')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'zero' => '0',
    'negative' => '-1',
]);

it('resolves a node name through the node list before adding the role', function (): void {
    $mockClient = MockClient::global([
        ListNodesRequest::class => MockResponse::make([
            'data' => [node_role_add_node_payload(7, 'app-dev')],
            'meta' => ['request_id' => node_role_add_request_id()],
        ]),
        AddNodeRoleRequest::class => MockResponse::make([
            'data' => added_node_role_payload(),
            'meta' => ['request_id' => node_role_add_request_id()],
        ], 201),
    ]);

    $this
        ->artisan('node:role:add', ['node' => 'app-dev', 'role' => 'app-dev', '--json' => true])
        ->assertExitCode(0);

    expect($mockClient->getLastRequest()?->resolveEndpoint())->toBe('/api/v1/nodes/7/roles');
});

it('rejects an unknown node name before the role request', function (): void {
    $mockClient = MockClient::global([
        ListNodesRequest::class => MockResponse::make([
            'data' => [node_role_add_node_payload(7, 'app-dev')],
            'meta' => ['request_id' => node_role_add_request_id()],
        ]),
    ]);

    $this
        ->artisan('node:role:add', ['node' => 'operator', 'role' => 'app-dev'])
        ->expectsOutputToContain('Node [operator] is not registered.')
        ->assertExitCode(1);

    $mockClient->assertNotSent(AddNodeRoleRequest::class);
});

it('rejects an empty role before connector io', function (): void {
    $mockClient = MockClient::global();

    $this
        ->artisan('node:role:add', ['node' => '7', 'role' => ''])
        ->expectsOutputToContain('Role is required.')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
});

it('sends one node role add request with default converge false as json for HTTP 201', function (): void {
    $mockClient = MockClient::global([
        AddNodeRoleRequest::class => MockResponse::make([
            'data' => added_node_role_payload(),
            'meta' => ['request_id' => node_role_add_request_id()],
        ], 201),
    ]);
    $expected = json_encode(added_node_role_expected_json(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this
        ->artisan('node:role:add', ['node' => '7', 'role' => 'app-dev', '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(0);

    $request = $mockClient->getLastRequest();
    $pendingRequest = $mockClient->getLastPendingRequest();

    expect($request)
        ->toBeInstanceOf(AddNodeRoleRequest::class)
        ->and($request?->getMethod())
        ->toBe(Method::POST)
        ->and($request?->resolveEndpoint())
        ->toBe('/api/v1/nodes/7/roles')
        ->and($pendingRequest?->body()->all())
        ->toBe([
            'role' => 'app-dev',
            'converge_existing' => false,
        ]);
});

it('sends converge true when requested and accepts HTTP 200 for an existing assignment', function (): void {
    $mockClient = MockClient::global([
        AddNodeRoleRequest::class => MockResponse::make([
            'data' => added_node_role_payload(),
            'meta' => ['request_id' => node_role_add_request_id()],
        ], 200),
    ]);

    $this
        ->artisan('node:role:add', ['node' => '7', 'role' => 'app-dev', '--converge' => true, '--json' => true])
        ->expectsOutput(json_encode(added_node_role_expected_json(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->assertExitCode(0);

    expect($mockClient->getLastPendingRequest()?->body()->all())
        ->toBe([
            'role' => 'app-dev',
            'converge_existing' => true,
        ]);
});

it('shows deterministic human output for a new node role assignment', function (): void {
    MockClient::global([
        AddNodeRoleRequest::class => MockResponse::make([
            'data' => added_node_role_payload(),
            'meta' => ['request_id' => node_role_add_request_id()],
        ], 201),
    ]);

    $this
        ->artisan('node:role:add', ['node' => '7', 'role' => 'app-dev'])
        ->expectsOutput('Role [app-dev] added to node [app-1] (#7).')
        ->expectsOutput('Request ID: '.node_role_add_request_id())
        ->assertExitCode(0);
});

it('renders gateway-owned node role add failures through the shared boundary', function (): void {
    $expected = json_encode([
        'error' => [
            'code' => 'validation.failed',
            'message' => 'Role [gateway] is protected from generic mutation.',
            'request_id' => node_role_add_request_id(),
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    MockClient::global([
        AddNodeRoleRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'validation.failed',
                    'message' => 'Role [gateway] is protected from generic mutation.',
                    'details' => [
                        'field' => 'role',
                        'role' => 'gateway',
                    ],
                ],
            ],
            422,
            ['X-Orbit-Request-Id' => node_role_add_request_id()],
        ),
    ]);

    $this
        ->artisan('node:role:add', ['node' => '7', 'role' => 'gateway', '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(1);
});

function node_role_add_command_options(?SymfonyCommand $command): array
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

/** @return array<string, mixed> */
function added_node_role_payload(): array
{
    return [
        'node_id' => 7,
        'node_name' => 'app-1',
        'role' => 'app-dev',
        'assignment' => [
            'id' => 34,
            'role' => 'app-dev',
            'status' => 'active',
            'failed_step' => null,
            'error_code' => null,
        ],
        'removed' => false,
    ];
}

/** @return array<string, mixed> */
function added_node_role_expected_json(): array
{
    return [
        ...added_node_role_payload(),
        'degradation' => null,
        'retained_on_node' => [],
        'follow_up' => null,
        'request_id' => node_role_add_request_id(),
    ];
}

function node_role_add_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}

it('shows deterministic human output for a converged existing node role assignment', function (): void {
    MockClient::global([
        AddNodeRoleRequest::class => MockResponse::make([
            'data' => added_node_role_payload(),
            'meta' => ['request_id' => node_role_add_request_id()],
        ], 200),
    ]);

    $this
        ->artisan('node:role:add', ['node' => '7', 'role' => 'app-dev', '--converge' => true])
        ->expectsOutput('Role [app-dev] added to node [app-1] (#7).')
        ->expectsOutput('Request ID: '.node_role_add_request_id())
        ->assertExitCode(0);
});

/** @return array<string, mixed> */
function node_role_add_node_payload(int $id, string $name): array
{
    return [
        'id' => $id,
        'name' => $name,
        'status' => 'active',
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'tld' => null,
        'public_ssh_host' => '203.0.113.7',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_address' => '10.44.0.7',
        'wireguard_public_key' => 'key',
        'wireguard_endpoint_override' => null,
        'dns_server_override' => null,
        'ssh_host_fingerprint' => null,
        'failed_step' => null,
        'error_code' => null,
        'roles' => [],
    ];
}
