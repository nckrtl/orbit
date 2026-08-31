<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
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

describe('node:list', function (): void {
    it('lists nodes from the active gateway as JSON', function (): void {
        $mockClient = MockClient::global([
            ListNodesRequest::class => MockResponse::make([
                'data' => [list_node_payload()],
                'meta' => ['request_id' => request_id()],
            ]),
        ]);
        $expected = json_encode([
            'nodes' => [list_node_payload()],
            'request_id' => request_id(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this
            ->artisan('node:list', ['--json' => true])
            ->expectsOutput($expected)
            ->assertExitCode(0);

        expect($mockClient->getLastPendingRequest()?->getUrl())
            ->toBe('https://10.44.0.1/api/v1/nodes');
    });

    it('shows a concise node table', function (): void {
        MockClient::global([
            ListNodesRequest::class => MockResponse::make([
                'data' => [list_node_payload()],
                'meta' => ['request_id' => request_id()],
            ]),
        ]);

        $this
            ->artisan('node:list')
            ->expectsTable(
                ['ID', 'Name', 'Status', 'Roles', 'Platform', 'TLD', 'User', 'Cluster', 'WireGuard', 'LAN'],
                [[2, 'app-dev', 'active', 'app-dev', 'linux', 'app-dev.orbit', 'orbit', 3, '10.44.0.3', '10.0.0.3']],
            )
            ->expectsOutput('Request ID: '.request_id())
            ->assertExitCode(0);
    });

    it('does not invent a missing public SSH port', function (): void {
        $payload = list_node_payload();
        $payload['public_ssh_port'] = null;
        MockClient::global([
            ListNodesRequest::class => MockResponse::make([
                'data' => [$payload],
                'meta' => ['request_id' => request_id()],
            ]),
        ]);
        $expected = json_encode([
            'nodes' => [[...$payload, 'public_ssh_port' => 0]],
            'request_id' => request_id(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this
            ->artisan('node:list', ['--json' => true])
            ->expectsOutput($expected)
            ->assertExitCode(0);
    });

    it('keeps the managed user visible without a complete public endpoint', function (): void {
        $payload = list_node_payload();
        $payload['public_ssh_port'] = null;
        MockClient::global([
            ListNodesRequest::class => MockResponse::make([
                'data' => [$payload],
                'meta' => ['request_id' => request_id()],
            ]),
        ]);

        $this
            ->artisan('node:list')
            ->expectsTable(
                ['ID', 'Name', 'Status', 'Roles', 'Platform', 'TLD', 'User', 'Cluster', 'WireGuard', 'LAN'],
                [[2, 'app-dev', 'active', 'app-dev', 'linux', 'app-dev.orbit', 'orbit', 3, '10.44.0.3', '10.0.0.3']],
            )
            ->assertExitCode(0);
    });

    it('reports when no nodes are registered', function (): void {
        MockClient::global([
            ListNodesRequest::class => MockResponse::make([
                'data' => [],
                'meta' => ['request_id' => request_id()],
            ]),
        ]);

        $this
            ->artisan('node:list')
            ->expectsOutput('No nodes.')
            ->expectsOutput('Request ID: '.request_id())
            ->assertExitCode(0);
    });

    it('fails clearly when no gateway profile is active', function (): void {
        new Filesystem()->deleteDirectory($this->orbitHome);
        $mockClient = MockClient::global();

        $this
            ->artisan('node:list')
            ->expectsOutputToContain('No active gateway profile.')
            ->assertExitCode(1);

        expect($mockClient->getLastPendingRequest())->toBeNull();
    });

    it('reports gateway API errors', function (): void {
        MockClient::global([
            ListNodesRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'gateway.unavailable',
                    'message' => 'Gateway is unavailable.',
                    'details' => [],
                ],
            ], 503),
        ]);

        $this
            ->artisan('node:list')
            ->expectsOutputToContain('Gateway is unavailable.')
            ->assertExitCode(1);
    });

    it('prints the request ID for list command gateway API errors', function (): void {
        MockClient::global([
            ListNodesRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => 'gateway.unavailable',
                        'message' => 'Gateway is unavailable.',
                        'details' => [],
                    ],
                ],
                503,
                [
                    'x-orbit-request-id' => request_id(),
                ],
            ),
        ]);

        $this
            ->artisan('node:list')
            ->expectsOutputToContain('Gateway is unavailable.')
            ->expectsOutput('Request ID: '.request_id())
            ->assertExitCode(1);
    });
});

describe('node:show', function (): void {
    it('shows one node from the active gateway as JSON', function (): void {
        $mockClient = MockClient::global([
            ShowNodeRequest::class => MockResponse::make([
                'data' => show_node_payload(),
                'meta' => ['request_id' => request_id()],
            ]),
        ]);
        $expected = json_encode(show_node_expected_json_payload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this
            ->artisan('node:show', ['node' => '2', '--json' => true])
            ->expectsOutput($expected)
            ->assertExitCode(0);

        expect($mockClient->getLastPendingRequest()?->getUrl())
            ->toBe('https://10.44.0.1/api/v1/nodes/2');
    });

    it('shows concise node details', function (): void {
        MockClient::global([
            ShowNodeRequest::class => MockResponse::make([
                'data' => show_node_payload(),
                'meta' => ['request_id' => request_id()],
            ]),
        ]);

        $this
            ->artisan('node:show', ['node' => '2'])
            ->expectsOutput('app-dev: active')
            ->expectsOutput('Roles: app-dev')
            ->expectsOutput('SSH: orbit@94.237.40.75:22')
            ->expectsOutput('Cluster: 3')
            ->expectsOutput('WireGuard: 10.44.0.3')
            ->expectsOutput('LAN: 10.0.0.3')
            ->expectsOutput('WireGuard public key: app-dev-public-key')
            ->expectsOutput('WireGuard endpoint override: 10.0.0.2:51820')
            ->expectsOutput('DNS server override: 10.0.0.2')
            ->expectsOutput('TLD: app-dev.orbit')
            ->expectsOutput('Platform: linux (x86_64)')
            ->expectsOutput('Access to: app-dev (#3), app-prod (#5)')
            ->expectsOutput('Accessible by: maintainer (#4)')
            ->expectsOutput('Request ID: '.request_id())
            ->assertExitCode(0);
    });

    it('shows empty node access summaries as dashes', function (): void {
        $payload = show_node_payload();
        $payload['access'] = [
            'can_access' => [],
            'accessible_by' => [],
        ];

        MockClient::global([
            ShowNodeRequest::class => MockResponse::make([
                'data' => $payload,
                'meta' => ['request_id' => request_id()],
            ]),
        ]);

        $this
            ->artisan('node:show', ['node' => '2'])
            ->expectsOutput('Access to: -')
            ->expectsOutput('Accessible by: -')
            ->assertExitCode(0);
    });

    it('renders an incomplete public SSH endpoint safely in node details', function (): void {
        $payload = show_node_payload();
        $payload['public_ssh_port'] = null;
        MockClient::global([
            ShowNodeRequest::class => MockResponse::make([
                'data' => $payload,
                'meta' => ['request_id' => request_id()],
            ]),
        ]);

        $this
            ->artisan('node:show', ['node' => '2'])
            ->expectsOutput('SSH: -')
            ->assertExitCode(0);
    });

    it('rejects an invalid node ID before making an API request', function (string $nodeId): void {
        $mockClient = MockClient::global();

        $this
            ->artisan('node:show', ['node' => $nodeId])
            ->expectsOutputToContain('Node ID must be a positive integer.')
            ->assertExitCode(1);

        expect($mockClient->getLastPendingRequest())->toBeNull();
    })->with([
        'non-numeric' => 'app-dev',
        'zero' => '0',
        'negative' => '-1',
    ]);

    it('fails clearly when no gateway profile is active', function (): void {
        new Filesystem()->deleteDirectory($this->orbitHome);
        $mockClient = MockClient::global();

        $this
            ->artisan('node:show', ['node' => '2'])
            ->expectsOutputToContain('No active gateway profile.')
            ->assertExitCode(1);

        expect($mockClient->getLastPendingRequest())->toBeNull();
    });

    it('reports gateway API errors', function (): void {
        MockClient::global([
            ShowNodeRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'node.not_found',
                    'message' => 'Node was not found.',
                    'details' => [],
                ],
            ], 404),
        ]);

        $this
            ->artisan('node:show', ['node' => '999'])
            ->expectsOutputToContain('Node was not found.')
            ->assertExitCode(1);
    });

    it('prints the request ID for show command gateway API errors', function (): void {
        MockClient::global([
            ShowNodeRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => 'node.not_found',
                        'message' => 'Node was not found.',
                        'details' => [],
                    ],
                ],
                404,
                [
                    'X-Orbit-Request-Id' => request_id(),
                ],
            ),
        ]);

        $this
            ->artisan('node:show', ['node' => '999'])
            ->expectsOutputToContain('Node was not found.')
            ->expectsOutput('Request ID: '.request_id())
            ->assertExitCode(1);
    });
});

/** @return array<string, int|string|list<string>|null> */
function list_node_payload(): array
{
    return [
        'id' => 2,
        'cluster_id' => 3,
        'name' => 'app-dev',
        'status' => 'active',
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'tld' => 'app-dev.orbit',
        'public_ssh_host' => '94.237.40.75',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
        'lan_ip' => '10.0.0.3',
        'wireguard_public_key' => 'app-dev-public-key',
        'wireguard_endpoint_override' => '10.0.0.2:51820',
        'dns_server_override' => '10.0.0.2',
        'ssh_host_fingerprint' => 'SHA256:4dxvKOYfyTcqJHYoxamTSu9bYYI5KE3xYWQPCAmeUTo',
        'failed_step' => null,
        'error_code' => null,
        'roles' => ['app-dev'],
        'settings' => null,
    ];
}

/**
 * @return array<string, int|string|list<string>|null|array{
 *     can_access: list<array{id: int, name: string}>,
 *     accessible_by: list<array{id: int, name: string}>
 * }>
 */
function show_node_payload(): array
{
    return [
        ...list_node_payload(),
        'access' => [
            'can_access' => [
                ['id' => 3, 'name' => 'app-dev'],
                ['id' => 5, 'name' => 'app-prod'],
            ],
            'accessible_by' => [
                ['id' => 4, 'name' => 'maintainer'],
            ],
        ],
    ];
}

/**
 * @return array{
 *     id: int,
 *     name: string,
 *     status: string,
 *     platform: string,
 *     architecture: string,
 *     tld: string,
 *     public_ssh_host: string,
 *     public_ssh_port: int,
 *     user: string,
 *     cluster_id: int,
 *     wireguard_ip: string,
 *     lan_ip: string,
 *     wireguard_public_key: string,
 *     wireguard_endpoint_override: string,
 *     dns_server_override: string,
 *     ssh_host_fingerprint: string,
 *     failed_step: null,
 *     error_code: null,
 *     roles: list<string>,
 *     request_id: string,
 *     access: array{
 *         can_access: list<array{id: int, name: string}>,
 *         accessible_by: list<array{id: int, name: string}>
 *     }
 * }
 */
function show_node_expected_json_payload(): array
{
    return [
        'id' => 2,
        'cluster_id' => 3,
        'name' => 'app-dev',
        'status' => 'active',
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'tld' => 'app-dev.orbit',
        'public_ssh_host' => '94.237.40.75',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
        'lan_ip' => '10.0.0.3',
        'wireguard_public_key' => 'app-dev-public-key',
        'wireguard_endpoint_override' => '10.0.0.2:51820',
        'dns_server_override' => '10.0.0.2',
        'ssh_host_fingerprint' => 'SHA256:4dxvKOYfyTcqJHYoxamTSu9bYYI5KE3xYWQPCAmeUTo',
        'failed_step' => null,
        'error_code' => null,
        'roles' => ['app-dev'],
        'settings' => null,
        'request_id' => request_id(),
        'access' => [
            'can_access' => [
                ['id' => 3, 'name' => 'app-dev'],
                ['id' => 5, 'name' => 'app-prod'],
            ],
            'accessible_by' => [
                ['id' => 4, 'name' => 'maintainer'],
            ],
        ],
    ];
}

function request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
