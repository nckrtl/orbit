<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\AddNodeRoleRequest;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
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
        ->and($command?->getDefinition()->getArgument('node')->getDescription())
        ->toBe('Node ID or name')
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
    'zero' => '0',
    'negative' => '-1',
]);

it('resolves a node name through the node list before removing the role', function (): void {
    $mockClient = MockClient::global([
        ListNodesRequest::class => MockResponse::make([
            'data' => [node_role_remove_node_payload(id: 7, name: 'mini')],
            'meta' => ['request_id' => node_role_remove_request_id()],
        ]),
        RemoveNodeRoleRequest::class => MockResponse::make([
            'data' => removed_node_role_payload(),
            'meta' => ['request_id' => node_role_remove_request_id()],
        ]),
    ]);

    $this
        ->artisan('node:role:remove', ['node' => 'mini', 'role' => 'app-dev', '--force' => true, '--json' => true])
        ->assertExitCode(0);

    expect($mockClient->getLastRequest()?->resolveEndpoint())->toBe('/api/v1/nodes/7/roles/app-dev');
});

it('rejects an unknown node name before the role removal request', function (): void {
    $mockClient = MockClient::global([
        ListNodesRequest::class => MockResponse::make([
            'data' => [node_role_remove_node_payload(id: 7, name: 'app-dev')],
            'meta' => ['request_id' => node_role_remove_request_id()],
        ]),
    ]);

    $this
        ->artisan('node:role:remove', ['node' => 'mini', 'role' => 'app-dev', '--force' => true])
        ->expectsOutputToContain('Node [mini] is not registered.')
        ->assertExitCode(1);

    $mockClient->assertNotSent(RemoveNodeRoleRequest::class);
});

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

it('renders unknown role enum validation details from the preview request', function (): void {
    $expected = json_encode([
        'error' => [
            'code' => 'validation.failed',
            'message' => 'The request data is invalid.',
            'details' => [
                'role' => ['The selected role is invalid.'],
            ],
            'request_id' => node_role_remove_request_id(),
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $mockClient = MockClient::global([
        RemoveNodeRoleRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'validation.failed',
                    'message' => 'The request data is invalid.',
                    'details' => [
                        'role' => ['The selected role is invalid.'],
                    ],
                ],
            ],
            422,
            ['X-Orbit-Request-Id' => node_role_remove_request_id()],
        ),
    ]);

    $this
        ->artisan('node:role:remove', [
            'node' => '7',
            'role' => 'nosuch',
            '--json' => true,
            '--no-interaction' => true,
        ])
        ->expectsOutput($expected)
        ->assertExitCode(1);

    expect($mockClient->getRecordedResponses())->toHaveCount(1);
});

it('requires the preview failure in json mode and sends no forced retry', function (): void {
    $expected = json_encode([
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
        ->expectsOutputToContain('reason: destructive_consent_required')
        ->expectsOutput('Request ID: '.node_role_remove_request_id())
        ->assertExitCode(1);

    expect($mockClient->getRecordedResponses())->toHaveCount(1);
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

    expect(Artisan::call('node:role:remove', ['node' => '7', 'role' => 'app-dev', '--force' => true, '--offline' => true]))->toBe(0);
    $output = preg_replace('/\s+/', '', Artisan::output());
    expect($output)->toContain(preg_replace('/\s+/', '', 'Role [app-dev] removed from node [app-1] (#7).'));
    expect($output)->toContain(preg_replace('/\s+/', '', 'Warning: Node [app-1] was unreachable. Orbit removed only the state it owns.'));
    expect($output)->toContain(preg_replace('/\s+/', '', 'Left on the node:'));
    expect($output)->toContain(preg_replace('/\s+/', '', '  Caddy site configuration and certificates for the app-dev role'));
    expect($output)->toContain(preg_replace('/\s+/', '', 'Run the node-local Metrics cleanup on the node once it boots, or discard the node.'));
    expect($output)->toContain(preg_replace('/\s+/', '', 'Request ID: '.node_role_remove_request_id()));

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

it('keeps the failed step of a forced role removal in json and names it in human output', function (): void {
    $response = static fn (): MockResponse => MockResponse::make(
        [
            'error' => [
                'code' => 'node_role.remove_failed',
                'message' => 'UFW is inactive on node [app-prod]. Retry with --offline if node [app-prod] is unreachable.',
                'details' => ['step' => 'remove:host-firewall', 'stdout' => 'private-output'],
            ],
        ],
        502,
        ['X-Orbit-Request-Id' => node_role_remove_request_id()],
    );
    MockClient::global([RemoveNodeRoleRequest::class => $response]);

    $this
        ->artisan('node:role:remove', ['node' => '7', 'role' => 'ingress', '--force' => true, '--json' => true])
        ->expectsOutput(json_encode([
            'error' => [
                'code' => 'node_role.remove_failed',
                'message' => 'UFW is inactive on node [app-prod]. Retry with --offline if node [app-prod] is unreachable.',
                'details' => ['step' => 'remove:host-firewall'],
                'request_id' => node_role_remove_request_id(),
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->doesntExpectOutputToContain('private-output')
        ->assertExitCode(1);

    MockClient::destroyGlobal();
    MockClient::global([RemoveNodeRoleRequest::class => $response]);
    $exitCode = Artisan::call('node:role:remove', ['node' => '7', 'role' => 'ingress', '--force' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('UFW is inactive on node [app-prod].', 'step: remove:host-firewall', 'Request ID: '.node_role_remove_request_id())
        ->not->toContain('private-output');
});

it('keeps the failed step of a preview failure that is not a consent refusal', function (): void {
    MockClient::global([
        RemoveNodeRoleRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'node_role.remove_failed',
                    'message' => 'Role [app-dev] dependencies changed during removal from node [app-dev].',
                    'details' => ['step' => 'dependency-race'],
                ],
            ],
            502,
            ['X-Orbit-Request-Id' => node_role_remove_request_id()],
        ),
    ]);

    $this
        ->artisan('node:role:remove', ['node' => '7', 'role' => 'app-dev', '--json' => true])
        ->expectsOutput(json_encode([
            'error' => [
                'code' => 'node_role.remove_failed',
                'message' => 'Role [app-dev] dependencies changed during removal from node [app-dev].',
                'details' => ['step' => 'dependency-race'],
                'request_id' => node_role_remove_request_id(),
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->assertExitCode(1);
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

/** @return array<string, mixed> */
function node_role_remove_node_payload(int $id, string $name): array
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
