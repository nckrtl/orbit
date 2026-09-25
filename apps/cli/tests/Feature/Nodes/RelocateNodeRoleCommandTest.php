<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\Nodes\RelocateNodeRoleRequest;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-node-role-relocate-'.Str::uuid();
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

it('registers the exact node role relocate command signature surface', function (): void {
    $command = app(Kernel::class)->all()['node:role:relocate'] ?? null;

    expect($command)
        ->toBeInstanceOf(SymfonyCommand::class)
        ->and(array_keys($command?->getDefinition()->getArguments() ?? []))
        ->toBe(['node', 'role'])
        ->and($command?->getDefinition()->getArgument('node')->getDescription())
        ->toBe('Node ID or name of the target')
        ->and(relocate_node_role_command_options($command))
        ->toBe([
            'force' => false,
            'from' => null,
            'json' => false,
        ]);
});

it('sends one forced gateway role relocate request as json', function (): void {
    $mockClient = MockClient::global([
        RelocateNodeRoleRequest::class => MockResponse::make([
            'data' => relocated_gateway_role_payload(),
            'meta' => ['request_id' => relocate_node_role_request_id()],
        ]),
    ]);
    $expected = json_encode(relocated_gateway_role_expected_json(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this
        ->artisan('node:role:relocate', ['node' => '7', 'role' => 'gateway', '--force' => true, '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(0);

    $request = $mockClient->getLastRequest();
    $pendingRequest = $mockClient->getLastPendingRequest();

    expect($request)
        ->toBeInstanceOf(RelocateNodeRoleRequest::class)
        ->and($request?->getMethod())
        ->toBe(Method::POST)
        ->and($request?->resolveEndpoint())
        ->toBe('/api/v1/nodes/7/roles/gateway/relocate')
        ->and($pendingRequest?->body()->all())
        ->toBe(['force' => true]);
});

it('sends optional from as a numeric source node id', function (): void {
    $mockClient = MockClient::global([
        RelocateNodeRoleRequest::class => MockResponse::make([
            'data' => relocated_gateway_role_payload(),
            'meta' => ['request_id' => relocate_node_role_request_id()],
        ]),
    ]);

    $this
        ->artisan('node:role:relocate', [
            'node' => '7',
            'role' => 'websocket',
            '--from' => '3',
            '--force' => true,
            '--json' => true,
        ])
        ->assertExitCode(0);

    expect($mockClient->getLastPendingRequest()?->body()->all())
        ->toBe(['force' => true, 'from' => 3])
        ->and($mockClient->getLastRequest()?->resolveEndpoint())
        ->toBe('/api/v1/nodes/7/roles/websocket/relocate');
});

it('shows deterministic human output for a relocated gateway role', function (): void {
    MockClient::global([
        RelocateNodeRoleRequest::class => MockResponse::make([
            'data' => relocated_gateway_role_payload(),
            'meta' => ['request_id' => relocate_node_role_request_id()],
        ]),
    ]);

    $this
        ->artisan('node:role:relocate', ['node' => '7', 'role' => 'gateway', '--force' => true])
        ->expectsOutput('Role [gateway] relocated to node [beast] (#7).')
        ->expectsOutput('Request ID: '.relocate_node_role_request_id())
        ->assertExitCode(0);
});

it('resolves a node name through the node list before relocating the role', function (): void {
    $mockClient = MockClient::global([
        ListNodesRequest::class => MockResponse::make([
            'data' => [relocate_node_role_node_payload()],
            'meta' => ['request_id' => relocate_node_role_request_id()],
        ]),
        RelocateNodeRoleRequest::class => MockResponse::make([
            'data' => relocated_gateway_role_payload(),
            'meta' => ['request_id' => relocate_node_role_request_id()],
        ]),
    ]);

    $this
        ->artisan('node:role:relocate', ['node' => 'beast', 'role' => 'gateway', '--force' => true, '--json' => true])
        ->assertExitCode(0);

    expect($mockClient->getLastRequest()?->resolveEndpoint())->toBe('/api/v1/nodes/7/roles/gateway/relocate');
});

it('rejects an empty role before connector io', function (): void {
    $mockClient = MockClient::global();

    $this
        ->artisan('node:role:relocate', ['node' => '7', 'role' => '', '--force' => true])
        ->expectsOutputToContain('Role is required.')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
});

it('renders gateway-owned relocate failures through the shared boundary', function (): void {
    $expected = json_encode([
        'error' => [
            'code' => 'validation.failed',
            'message' => 'Role [vpn] cannot be relocated.',
            'details' => [
                'field' => 'role',
                'role' => 'vpn',
            ],
            'request_id' => relocate_node_role_request_id(),
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    MockClient::global([
        RelocateNodeRoleRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'validation.failed',
                    'message' => 'Role [vpn] cannot be relocated.',
                    'details' => [
                        'field' => 'role',
                        'role' => 'vpn',
                    ],
                ],
            ],
            422,
            ['X-Orbit-Request-Id' => relocate_node_role_request_id()],
        ),
    ]);

    $this
        ->artisan('node:role:relocate', ['node' => '7', 'role' => 'vpn', '--force' => true, '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(1);
});

it('keeps the consent refusal details in json mode and sends no forced retry', function (): void {
    $details = ['field' => 'force', 'reason' => 'destructive_consent_required', 'role' => 'websocket'];
    $mockClient = MockClient::global([
        RelocateNodeRoleRequest::class => MockResponse::make(
            ['error' => ['code' => 'validation.failed', 'message' => 'Use --force to relocate this node role.', 'details' => $details]],
            422,
            ['X-Orbit-Request-Id' => relocate_node_role_request_id()],
        ),
    ]);

    $this
        ->artisan('node:role:relocate', ['node' => '7', 'role' => 'websocket', '--json' => true])
        ->expectsOutput(json_encode(['error' => [
            'code' => 'validation.failed',
            'message' => 'Use --force to relocate this node role.',
            'details' => $details,
            'request_id' => relocate_node_role_request_id(),
        ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->assertExitCode(1);

    expect($mockClient->getRecordedResponses())->toHaveCount(1);
});

/** @return array<string, mixed> */
function relocate_node_role_command_options(?SymfonyCommand $command): array
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
function relocated_gateway_role_payload(): array
{
    return [
        'node_id' => 7,
        'node_name' => 'beast',
        'role' => 'gateway',
        'assignment' => [
            'id' => 2,
            'role' => 'gateway',
            'status' => 'active',
            'failed_step' => null,
            'error_code' => null,
        ],
        'removed' => false,
    ];
}

/** @return array<string, mixed> */
function relocated_gateway_role_expected_json(): array
{
    return [
        ...relocated_gateway_role_payload(),
        'degradation' => null,
        'retained_on_node' => [],
        'follow_up' => null,
        'request_id' => relocate_node_role_request_id(),
    ];
}

function relocate_node_role_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}

/** @return array<string, mixed> */
function relocate_node_role_node_payload(): array
{
    return [
        'id' => 7,
        'name' => 'beast',
        'status' => 'active',
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'tld' => null,
        'public_ssh_host' => '203.0.113.7',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.7',
        'wireguard_public_key' => 'key',
        'wireguard_endpoint_override' => null,
        'dns_server_override' => null,
        'ssh_host_fingerprint' => null,
        'failed_step' => null,
        'error_code' => null,
        'roles' => [],
    ];
}
