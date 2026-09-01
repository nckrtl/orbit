<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\ListNodeRolesRequest;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-node-role-query-'.Str::uuid();
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

it('registers the exact node role list command signature surface', function (): void {
    $command = app(Kernel::class)->all()['node:role:list'] ?? null;

    expect($command)
        ->toBeInstanceOf(SymfonyCommand::class)
        ->and(array_keys($command?->getDefinition()->getArguments() ?? []))
        ->toBe(['node'])
        ->and(node_role_command_options($command))
        ->toBe(['json' => false]);
});

it('lists node roles from the active gateway as JSON', function (): void {
    $assignment = node_role_assignment_payload();
    $mockClient = MockClient::global([
        ListNodeRolesRequest::class => MockResponse::make([
            'data' => [$assignment],
            'meta' => ['request_id' => node_role_command_request_id()],
        ]),
    ]);
    $expected = json_encode([
        'assignments' => [$assignment],
        'request_id' => node_role_command_request_id(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this
        ->artisan('node:role:list', ['node' => '7', '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(0);

    $request = $mockClient->getLastRequest();

    expect($request)
        ->toBeInstanceOf(ListNodeRolesRequest::class)
        ->and($request?->getMethod())
        ->toBe(Method::GET)
        ->and($request?->resolveEndpoint())
        ->toBe('/api/v1/nodes/7/roles');
});

it('shows a concise node role table with lifecycle and failure columns', function (): void {
    MockClient::global([
        ListNodeRolesRequest::class => MockResponse::make([
            'data' => [
                node_role_assignment_payload(),
                failed_node_role_assignment_payload(),
            ],
            'meta' => ['request_id' => node_role_command_request_id()],
        ]),
    ]);

    $this
        ->artisan('node:role:list', ['node' => '7'])
        ->expectsTable(
            ['ID', 'Role', 'Status', 'Failed step', 'Error code'],
            [
                [34, 'app-dev',  'active', '-',                 '-'],
                [35, 'app-prod', 'failed', 'converge:packages', 'packages.failed'],
            ],
        )
        ->expectsOutput('Request ID: '.node_role_command_request_id())
        ->assertExitCode(0);
});

it('lists Ingress lifecycle identity in JSON and human modes without extra settings', function (): void {
    $assignment = [
        'id' => 41,
        'role' => 'ingress',
        'status' => 'active',
        'failed_step' => null,
        'error_code' => null,
    ];
    MockClient::global([
        ListNodeRolesRequest::class => MockResponse::make([
            'data' => [$assignment],
            'meta' => ['request_id' => node_role_command_request_id()],
        ]),
    ]);

    $this
        ->artisan('node:role:list', ['node' => '17', '--json' => true])
        ->expectsOutput(json_encode([
            'assignments' => [$assignment],
            'request_id' => node_role_command_request_id(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->assertExitCode(0);
    $this
        ->artisan('node:role:list', ['node' => '17'])
        ->expectsTable(
            ['ID', 'Role', 'Status', 'Failed step', 'Error code'],
            [[41, 'ingress', 'active', '-', '-']],
        )
        ->expectsOutput('Request ID: '.node_role_command_request_id())
        ->assertExitCode(0);
});

it('shows an empty node role result clearly', function (): void {
    MockClient::global([
        ListNodeRolesRequest::class => MockResponse::make([
            'data' => [],
            'meta' => ['request_id' => node_role_command_request_id()],
        ]),
    ]);

    $this
        ->artisan('node:role:list', ['node' => '7'])
        ->expectsOutput('No roles.')
        ->expectsOutput('Request ID: '.node_role_command_request_id())
        ->assertExitCode(0);
});

it('rejects an invalid node role list node id before connector io', function (string $nodeId): void {
    $mockClient = MockClient::global();

    $this
        ->artisan('node:role:list', ['node' => $nodeId])
        ->expectsOutputToContain('Node ID must be a positive integer.')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'non-numeric' => 'operator',
    'zero' => '0',
    'negative' => '-1',
]);

it('prints the request id for node role list gateway API errors', function (): void {
    MockClient::global([
        ListNodeRolesRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'gateway.unavailable',
                    'message' => 'Gateway is unavailable.',
                    'details' => [],
                ],
            ],
            503,
            ['X-Orbit-Request-Id' => node_role_command_request_id()],
        ),
    ]);

    $this
        ->artisan('node:role:list', ['node' => '7'])
        ->expectsOutputToContain('Gateway is unavailable.')
        ->expectsOutput('Request ID: '.node_role_command_request_id())
        ->assertExitCode(1);
});

function node_role_command_options(?SymfonyCommand $command): array
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
function node_role_assignment_payload(): array
{
    return [
        'id' => 34,
        'role' => 'app-dev',
        'status' => 'active',
        'failed_step' => null,
        'error_code' => null,
    ];
}

/** @return array<string, mixed> */
function failed_node_role_assignment_payload(): array
{
    return [
        'id' => 35,
        'role' => 'app-prod',
        'status' => 'failed',
        'failed_step' => 'converge:packages',
        'error_code' => 'packages.failed',
    ];
}

function node_role_command_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
