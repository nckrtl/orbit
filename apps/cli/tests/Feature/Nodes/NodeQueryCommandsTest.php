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
                'data' => [node_payload()],
                'meta' => ['request_id' => request_id()],
            ]),
        ]);
        $expected = json_encode([
            'nodes' => [node_payload()],
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
                'data' => [node_payload()],
                'meta' => ['request_id' => request_id()],
            ]),
        ]);

        $this
            ->artisan('node:list')
            ->expectsTable(
                ['ID', 'Name', 'Status', 'Roles', 'SSH', 'WireGuard'],
                [[2, 'app-dev', 'active', 'app-dev', 'orbit@94.237.40.75:22', '10.44.0.3']],
            )
            ->expectsOutput('Request ID: '.request_id())
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
});

describe('node:show', function (): void {
    it('shows one node from the active gateway as JSON', function (): void {
        $mockClient = MockClient::global([
            ShowNodeRequest::class => MockResponse::make([
                'data' => node_payload(),
                'meta' => ['request_id' => request_id()],
            ]),
        ]);
        $expected = json_encode([
            ...node_payload(),
            'request_id' => request_id(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

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
                'data' => node_payload(),
                'meta' => ['request_id' => request_id()],
            ]),
        ]);

        $this
            ->artisan('node:show', ['node' => '2'])
            ->expectsOutput('app-dev: active')
            ->expectsOutput('Roles: app-dev')
            ->expectsOutput('SSH: orbit@94.237.40.75:22')
            ->expectsOutput('WireGuard: 10.44.0.3')
            ->expectsOutput('Platform: linux (x86_64)')
            ->expectsOutput('Request ID: '.request_id())
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
});

/** @return array<string, int|string|list<string>|null> */
function node_payload(): array
{
    return [
        'id' => 2,
        'name' => 'app-dev',
        'status' => 'active',
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => '94.237.40.75',
        'public_ssh_port' => 22,
        'ssh_user' => 'orbit',
        'wireguard_address' => '10.44.0.3',
        'ssh_host_fingerprint' => 'SHA256:4dxvKOYfyTcqJHYoxamTSu9bYYI5KE3xYWQPCAmeUTo',
        'failed_step' => null,
        'error_code' => null,
        'roles' => ['app-dev'],
    ];
}

function request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
