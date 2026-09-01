<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\AddNodeRoleRequest;
use Orbit\Sdk\Requests\Nodes\RemoveNodeRoleRequest;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-node-role-remove-'.Str::uuid();
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

it('registers the exact node role remove command signature surface', function (): void {
    $command = app(Kernel::class)->all()['node:role:remove'] ?? null;

    expect($command)
        ->toBeInstanceOf(SymfonyCommand::class)
        ->and(array_keys($command?->getDefinition()->getArguments() ?? []))
        ->toBe(['node', 'role'])
        ->and(node_role_remove_command_options($command))
        ->toBe([
            'force' => false,
            'purge-data' => false,
            'offline' => false,
            'json' => false,
        ]);
});

it('rejects an invalid node id before connector io', function (string $nodeId): void {
    $mockClient = MockClient::global();

    $this
        ->artisan('node:role:remove', ['node' => $nodeId, 'role' => 'app-dev', '--force' => true])
        ->expectsOutputToContain('Node ID must be a positive integer.')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'non-numeric' => 'operator',
    'zero' => '0',
    'negative' => '-1',
]);

it('rejects an empty role before connector io', function (): void {
    $mockClient = MockClient::global();

    $this
        ->artisan('node:role:remove', ['node' => '7', 'role' => '', '--force' => true])
        ->expectsOutputToContain('Role is required.')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
});

it('sends one forced node role removal request as json', function (): void {
    $mockClient = MockClient::global([
        RemoveNodeRoleRequest::class => MockResponse::make([
            'data' => removed_node_role_payload(),
            'meta' => ['request_id' => node_role_remove_request_id()],
        ]),
    ]);
    $expected = json_encode(removed_node_role_expected_json(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this
        ->artisan('node:role:remove', ['node' => '7', 'role' => 'app-dev', '--force' => true, '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(0);

    $request = $mockClient->getLastRequest();
    $pendingRequest = $mockClient->getLastPendingRequest();

    expect($request)
        ->toBeInstanceOf(RemoveNodeRoleRequest::class)
        ->and($request?->getMethod())
        ->toBe(Method::DELETE)
        ->and($request?->resolveEndpoint())
        ->toBe('/api/v1/nodes/7/roles/app-dev')
        ->and($pendingRequest?->body()->all())
        ->toBe([
            'force' => true,
            'purge_data' => false,
            'offline' => false,
        ])
        ->and($mockClient->getRecordedResponses())
        ->toHaveCount(1);
});

it('repeats Ingress removal and supports remove-then-add replacement in human and JSON modes', function (): void {
    $mockClient = MockClient::global([
        RemoveNodeRoleRequest::class => MockResponse::make([
            'data' => ingress_removed_node_role_payload(),
            'meta' => ['request_id' => node_role_remove_request_id()],
        ]),
        AddNodeRoleRequest::class => MockResponse::make([
            'data' => ingress_replacement_node_role_payload(),
            'meta' => ['request_id' => node_role_remove_request_id()],
        ], 201),
    ]);

    $this
        ->artisan('node:role:remove', ['node' => '17', 'role' => 'ingress', '--force' => true])
        ->expectsOutput('Role [ingress] removed from node [ingress-old] (#17).')
        ->assertExitCode(0);
    $this
        ->artisan('node:role:remove', [
            'node' => '17',
            'role' => 'ingress',
            '--force' => true,
            '--json' => true,
        ])
        ->expectsOutput(json_encode([
            ...ingress_removed_node_role_payload(),
            'degradation' => null,
            'retained_on_node' => [],
            'follow_up' => null,
            'request_id' => node_role_remove_request_id(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->assertExitCode(0);
    $this
        ->artisan('node:role:add', ['node' => '18', 'role' => 'ingress', '--json' => true])
        ->assertExitCode(0);

    $mockClient->assertSentCount(2, RemoveNodeRoleRequest::class);
    expect($mockClient->getLastRequest())
        ->toBeInstanceOf(AddNodeRoleRequest::class)
        ->and($mockClient->getLastPendingRequest()?->body()->all())
        ->toBe(['role' => 'ingress', 'converge_existing' => false]);
});

it('requires the preview failure in json mode and sends no forced retry', function (): void {
    $expected = json_encode([
        'error' => [
            'code' => 'validation.failed',
            'message' => 'Use --force to remove this node role.',
            'request_id' => node_role_remove_request_id(),
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $mockClient = MockClient::global([
        RemoveNodeRoleRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'validation.failed',
                    'message' => 'Use --force to remove this node role.',
                    'details' => [
                        'field' => 'force',
                        'reason' => 'destructive_consent_required',
                        'role' => 'app-dev',
                        'dependents' => [
                            '1 development instance record',
                            '1 workspace record',
                        ],
                    ],
                ],
            ],
            422,
            ['X-Orbit-Request-Id' => node_role_remove_request_id()],
        ),
    ]);

    $this
        ->artisan('node:role:remove', ['node' => '7', 'role' => 'app-dev', '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(1);

    expect($mockClient->getRecordedResponses())->toHaveCount(1);
});

it('requires the preview failure in non-interactive mode and sends no forced retry', function (): void {
    $mockClient = MockClient::global([
        RemoveNodeRoleRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'validation.failed',
                    'message' => 'Use --force to remove this node role.',
                    'details' => [
                        'field' => 'force',
                        'reason' => 'destructive_consent_required',
                        'role' => 'app-dev',
                        'dependents' => [],
                    ],
                ],
            ],
            422,
            ['X-Orbit-Request-Id' => node_role_remove_request_id()],
        ),
    ]);

    $this
        ->artisan('node:role:remove', ['node' => '7', 'role' => 'app-dev', '--no-interaction' => true])
        ->expectsOutputToContain('Use --force to remove this node role.')
        ->expectsOutput('Request ID: '.node_role_remove_request_id())
        ->assertExitCode(1);

    expect($mockClient->getRecordedResponses())->toHaveCount(1);
});

it('shows dependents then sends one forced retry after confirmation', function (): void {
    $calls = 0;
    $mockClient = MockClient::global([
        RemoveNodeRoleRequest::class => static function () use (&$calls): MockResponse {
            $calls++;

            if ($calls === 1) {
                return MockResponse::make(
                    [
                        'error' => [
                            'code' => 'validation.failed',
                            'message' => 'Use --force to remove this node role.',
                            'details' => [
                                'field' => 'force',
                                'reason' => 'destructive_consent_required',
                                'role' => 'app-dev',
                                'dependents' => [
                                    '1 development instance record',
                                    '1 workspace record',
                                ],
                            ],
                        ],
                    ],
                    422,
                    ['X-Orbit-Request-Id' => node_role_remove_request_id()],
                );
            }

            return MockResponse::make([
                'data' => removed_node_role_payload(),
                'meta' => ['request_id' => node_role_remove_request_id()],
            ]);
        },
    ]);

    $this
        ->artisan('node:role:remove', ['node' => '7', 'role' => 'app-dev'])
        ->expectsOutput('Dependent resources:')
        ->expectsOutput('  - 1 development instance record')
        ->expectsOutput('  - 1 workspace record')
        ->expectsConfirmation("Remove role 'app-dev' from node #7?", 'yes')
        ->assertExitCode(0);

    $mockClient->assertSentCount(2, RemoveNodeRoleRequest::class);
    $mockClient->assertSentInOrder([
        static fn (RemoveNodeRoleRequest $request): bool => $request->body()->all() === [
            'force' => false,
            'purge_data' => false,
            'offline' => false,
        ],
        static fn (RemoveNodeRoleRequest $request): bool => $request->body()->all() === [
            'force' => true,
            'purge_data' => false,
            'offline' => false,
        ],
    ]);
});

it('does not force removal after a declined confirmation', function (): void {
    $mockClient = MockClient::global([
        RemoveNodeRoleRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'validation.failed',
                    'message' => 'Use --force to remove this node role.',
                    'details' => [
                        'field' => 'force',
                        'reason' => 'destructive_consent_required',
                        'role' => 'app-dev',
                        'dependents' => [],
                    ],
                ],
            ],
            422,
            ['X-Orbit-Request-Id' => node_role_remove_request_id()],
        ),
    ]);

    $this
        ->artisan('node:role:remove', ['node' => '7', 'role' => 'app-dev'])
        ->expectsConfirmation("Remove role 'app-dev' from node #7?", 'no')
        ->doesntExpectOutput('Dependent resources:')
        ->assertExitCode(1);

    expect($mockClient->getRecordedResponses())->toHaveCount(1);
});

it('forwards purge data only on the forced retry and keeps empty dependents hidden', function (): void {
    $calls = 0;
    $mockClient = MockClient::global([
        RemoveNodeRoleRequest::class => static function () use (&$calls): MockResponse {
            $calls++;

            if ($calls === 1) {
                return MockResponse::make(
                    [
                        'error' => [
                            'code' => 'validation.failed',
                            'message' => 'Use --force to remove this node role.',
                            'details' => [
                                'field' => 'force',
                                'reason' => 'destructive_consent_required',
                                'role' => 'app-dev',
                                'dependents' => [],
                            ],
                        ],
                    ],
                    422,
                    ['X-Orbit-Request-Id' => node_role_remove_request_id()],
                );
            }

            return MockResponse::make([
                'data' => removed_node_role_payload(),
                'meta' => ['request_id' => node_role_remove_request_id()],
            ]);
        },
    ]);

    $this
        ->artisan('node:role:remove', ['node' => '7', 'role' => 'app-dev', '--purge-data' => true])
        ->expectsConfirmation("Remove role 'app-dev' from node #7?", 'yes')
        ->doesntExpectOutput('Dependent resources:')
        ->doesntExpectOutputToContain('  - ')
        ->assertExitCode(0);

    $mockClient->assertSentCount(2, RemoveNodeRoleRequest::class);
    $mockClient->assertSentInOrder([
        static fn (RemoveNodeRoleRequest $request): bool => $request->body()->all() === [
            'force' => false,
            'purge_data' => false,
            'offline' => false,
        ],
        static fn (RemoveNodeRoleRequest $request): bool => $request->body()->all() === [
            'force' => true,
            'purge_data' => true,
            'offline' => false,
        ],
    ]);
});

it('fails closed when the preview unexpectedly succeeds', function (): void {
    $expected = json_encode([
        'error' => [
            'code' => 'gateway.invalid_response',
            'message' => 'Gateway response is invalid.',
            'request_id' => null,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $mockClient = MockClient::global([
        RemoveNodeRoleRequest::class => MockResponse::make([
            'data' => removed_node_role_payload(),
            'meta' => ['request_id' => node_role_remove_request_id()],
        ]),
    ]);

    $this
        ->artisan('node:role:remove', ['node' => '7', 'role' => 'app-dev', '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(1);

    expect($mockClient->getRecordedResponses())->toHaveCount(1);
});

it('shows deterministic human output for a removed node role assignment', function (): void {
    MockClient::global([
        RemoveNodeRoleRequest::class => MockResponse::make([
            'data' => removed_node_role_payload(),
            'meta' => ['request_id' => node_role_remove_request_id()],
        ]),
    ]);

    $this
        ->artisan('node:role:remove', ['node' => '7', 'role' => 'app-dev', '--force' => true])
        ->expectsOutput('Role [app-dev] removed from node [app-1] (#7).')
        ->expectsOutput('Request ID: '.node_role_remove_request_id())
        ->assertExitCode(0);
});

it('shows no degradation advisory for an ordinary node role removal', function (): void {
    MockClient::global([
        RemoveNodeRoleRequest::class => MockResponse::make([
            'data' => removed_node_role_payload(),
            'meta' => ['request_id' => node_role_remove_request_id()],
        ]),
    ]);

    $this
        ->artisan('node:role:remove', ['node' => '7', 'role' => 'app-dev', '--force' => true])
        ->expectsOutput('Role [app-dev] removed from node [app-1] (#7).')
        ->doesntExpectOutputToContain('Left on the node:')
        ->doesntExpectOutputToContain('  - ')
        ->expectsOutput('Request ID: '.node_role_remove_request_id())
        ->assertExitCode(0);
});

it('shows the degradation advisory for an offline node role removal', function (): void {
    $mockClient = MockClient::global([
        RemoveNodeRoleRequest::class => MockResponse::make([
            'data' => removed_node_role_degraded_payload(),
            'meta' => ['request_id' => node_role_remove_request_id()],
        ]),
    ]);

    $this
        ->artisan('node:role:remove', ['node' => '7', 'role' => 'app-dev', '--force' => true, '--offline' => true])
        ->expectsOutput('Role [app-dev] removed from node [app-1] (#7).')
        ->expectsOutput('Warning: Node [app-1] was unreachable. Orbit removed only the state it owns.')
        ->expectsOutput('Left on the node:')
        ->expectsOutput('  - Caddy site configuration and certificates for the app-dev role')
        ->expectsOutput('Run the node-local Metrics cleanup on the node once it boots, or discard the node.')
        ->expectsOutput('Request ID: '.node_role_remove_request_id())
        ->assertExitCode(0);

    expect($mockClient->getLastPendingRequest()?->body()->all())
        ->toBe([
            'force' => true,
            'purge_data' => false,
            'offline' => true,
        ]);
});

it('renders unrelated gateway failures through the shared boundary without a forced retry', function (): void {
    $expected = json_encode([
        'error' => [
            'code' => 'gateway.unavailable',
            'message' => 'Gateway is unavailable.',
            'request_id' => node_role_remove_request_id(),
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $mockClient = MockClient::global([
        RemoveNodeRoleRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'gateway.unavailable',
                    'message' => 'Gateway is unavailable.',
                    'details' => [],
                ],
            ],
            503,
            ['X-Orbit-Request-Id' => node_role_remove_request_id()],
        ),
    ]);

    $this
        ->artisan('node:role:remove', ['node' => '7', 'role' => 'app-dev', '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(1);

    $mockClient->assertSentCount(1, RemoveNodeRoleRequest::class);
});

function node_role_remove_command_options(?SymfonyCommand $command): array
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
function removed_node_role_payload(): array
{
    return [
        'node_id' => 7,
        'node_name' => 'app-1',
        'role' => 'app-dev',
        'assignment' => null,
        'removed' => true,
    ];
}

/** @return array<string, mixed> */
function ingress_removed_node_role_payload(): array
{
    return [
        'node_id' => 17,
        'node_name' => 'ingress-old',
        'role' => 'ingress',
        'assignment' => null,
        'removed' => true,
    ];
}

/** @return array<string, mixed> */
function ingress_replacement_node_role_payload(): array
{
    return [
        'node_id' => 18,
        'node_name' => 'ingress-new',
        'role' => 'ingress',
        'assignment' => [
            'id' => 42,
            'role' => 'ingress',
            'status' => 'active',
            'failed_step' => null,
            'error_code' => null,
        ],
        'removed' => false,
    ];
}

/** @return array<string, mixed> */
function removed_node_role_degraded_payload(): array
{
    return [
        'node_id' => 7,
        'node_name' => 'app-1',
        'role' => 'app-dev',
        'assignment' => null,
        'removed' => true,
        'degradation' => 'unreachable',
        'retained_on_node' => [
            'Caddy site configuration and certificates for the app-dev role',
        ],
        'follow_up' => 'Run the node-local Metrics cleanup on the node once it boots, or discard the node.',
    ];
}

/** @return array<string, mixed> */
function removed_node_role_expected_json(): array
{
    return [
        ...removed_node_role_payload(),
        'degradation' => null,
        'retained_on_node' => [],
        'follow_up' => null,
        'request_id' => node_role_remove_request_id(),
    ];
}

function node_role_remove_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
