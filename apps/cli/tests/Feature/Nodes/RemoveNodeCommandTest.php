<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\RemoveNodeRequest;
use Orbit\Sdk\Requests\Nodes\ShowNodeRequest;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-'.(string) Str::uuid();
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

it('fails as not-found for a missing node without force', function (): void {
    $mockClient = MockClient::global([
        ShowNodeRequest::class => MockResponse::make(
            missing_node_error_payload(),
            404,
            ['X-Orbit-Request-Id' => remove_node_request_id()],
        ),
    ]);
    $expected = json_encode(missing_node_error_json(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this
        ->artisan('node:remove', ['node' => '999999', '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(1);

    expect($mockClient->getLastRequest())
        ->toBeInstanceOf(ShowNodeRequest::class)
        ->and($mockClient->getLastPendingRequest()?->getUrl())
        ->toBe('https://10.44.0.1/api/v1/nodes/999999')
        ->and($mockClient->getLastPendingRequest()?->getMethod())
        ->toBe(Method::GET)
        ->and($mockClient->getRecordedResponses())
        ->toHaveCount(1);
});

it('fails as not-found for a missing node with force', function (): void {
    $mockClient = MockClient::global([
        ShowNodeRequest::class => MockResponse::make(
            missing_node_error_payload(),
            404,
            ['X-Orbit-Request-Id' => remove_node_request_id()],
        ),
    ]);
    $expected = json_encode(missing_node_error_json(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this
        ->artisan('node:remove', ['node' => '999999', '--force' => true, '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(1);

    expect($mockClient->getLastRequest())
        ->toBeInstanceOf(ShowNodeRequest::class)
        ->and($mockClient->getLastPendingRequest()?->getMethod())
        ->toBe(Method::GET)
        ->and($mockClient->getRecordedResponses())
        ->toHaveCount(1);
});

it('fails as typed not-found when the gateway names the missing node', function (): void {
    $mockClient = MockClient::global([
        ShowNodeRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'node.not_found',
                    'message' => 'Node was not found.',
                    'details' => [],
                ],
            ],
            404,
            ['X-Orbit-Request-Id' => remove_node_request_id()],
        ),
    ]);
    $expected = json_encode([
        'error' => [
            'code' => 'node.not_found',
            'message' => 'Node was not found.',
            'request_id' => remove_node_request_id(),
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this
        ->artisan('node:remove', ['node' => '999999', '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(1);

    expect($mockClient->getLastRequest())
        ->toBeInstanceOf(ShowNodeRequest::class)
        ->and($mockClient->getRecordedResponses())
        ->toHaveCount(1);
});

it('requires force only after an existing node is resolved', function (): void {
    $mockClient = MockClient::global([
        ShowNodeRequest::class => MockResponse::make(existing_node_show_payload()),
    ]);
    $expected = json_encode([
        'error' => [
            'code' => 'node.confirmation_required',
            'message' => 'Use --force to confirm node removal.',
            'request_id' => null,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this
        ->artisan('node:remove', ['node' => '2', '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(1);

    expect($mockClient->getLastRequest())
        ->toBeInstanceOf(ShowNodeRequest::class)
        ->and($mockClient->getRecordedResponses())
        ->toHaveCount(1);
});

it('sends node removal to the active gateway as json', function (): void {
    $mockClient = MockClient::global([
        ShowNodeRequest::class => MockResponse::make(existing_node_show_payload()),
        RemoveNodeRequest::class => MockResponse::make([
            'data' => removed_node_payload(),
            'meta' => ['request_id' => remove_node_request_id()],
        ]),
    ]);
    $expected = json_encode(removed_node_expected_json(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this
        ->artisan('node:remove', ['node' => '2', '--force' => true, '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(0);

    $request = $mockClient->getLastRequest();

    expect($request)
        ->toBeInstanceOf(RemoveNodeRequest::class)
        ->and($request?->getMethod())
        ->toBe(Method::DELETE)
        ->and($mockClient->getLastPendingRequest()?->getUrl())
        ->toBe('https://10.44.0.1/api/v1/nodes/2')
        ->and($mockClient->getLastPendingRequest()?->body()->all())
        ->toBe(['force' => true, 'offline' => false])
        ->and($mockClient->getRecordedResponses())
        ->toHaveCount(2);
});

it('sends the offline claim and returns the full degraded json payload', function (): void {
    $mockClient = MockClient::global([
        ShowNodeRequest::class => MockResponse::make(existing_node_show_payload(id: 3, name: 'app-prod')),
        RemoveNodeRequest::class => MockResponse::make([
            'data' => removed_node_degraded_payload(),
            'meta' => ['request_id' => remove_node_request_id()],
        ]),
    ]);
    $expected = json_encode(removed_node_degraded_expected_json(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this
        ->artisan('node:remove', ['node' => '3', '--offline' => true, '--force' => true, '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(0);

    expect($mockClient->getLastPendingRequest()?->body()->all())
        ->toBe(['force' => true, 'offline' => true]);
});

it('shows deterministic human output for node removal', function (): void {
    MockClient::global([
        ShowNodeRequest::class => MockResponse::make(existing_node_show_payload()),
        RemoveNodeRequest::class => MockResponse::make([
            'data' => removed_node_payload(),
            'meta' => ['request_id' => remove_node_request_id()],
        ]),
    ]);

    $this
        ->artisan('node:remove', ['node' => '2', '--force' => true])
        ->expectsOutput('Node [app-dev] removed.')
        ->doesntExpectOutputToContain('Left on the node:')
        ->doesntExpectOutputToContain('  - ')
        ->expectsOutput('Request ID: '.remove_node_request_id())
        ->assertExitCode(0);
});

it('shows the degradation advisory for an offline node removal', function (): void {
    MockClient::global([
        ShowNodeRequest::class => MockResponse::make(existing_node_show_payload(id: 3, name: 'app-prod')),
        RemoveNodeRequest::class => MockResponse::make([
            'data' => removed_node_degraded_payload(),
            'meta' => ['request_id' => remove_node_request_id()],
        ]),
    ]);

    expect(Artisan::call('node:remove', ['node' => '3', '--offline' => true, '--force' => true]))->toBe(0);
    $output = preg_replace('/\s+/', '', Artisan::output());
    expect($output)->toContain(preg_replace('/\s+/', '', 'Node [app-prod] removed.'));
    expect($output)->toContain(preg_replace('/\s+/', '', 'Warning: Node [app-prod] was unreachable. Orbit removed only the state it owns.'));
    expect($output)->toContain(preg_replace('/\s+/', '', 'Roles shed:'));
    expect($output)->toContain(preg_replace('/\s+/', '', '  app-prod'));
    expect($output)->toContain(preg_replace('/\s+/', '', 'Left on the node:'));
    expect($output)->toContain(preg_replace('/\s+/', '', '  Caddy site configuration and certificates for the app-prod role'));
    expect($output)->toContain(preg_replace('/\s+/', '', 'Run the node-local Metrics cleanup on the node once it boots, or discard the node.'));
    expect($output)->toContain(preg_replace('/\s+/', '', 'Request ID: '.remove_node_request_id()));
});

it('rejects an invalid node id before making an API request', function (string $nodeId): void {
    $mockClient = MockClient::global();

    $this
        ->artisan('node:remove', ['node' => $nodeId, '--force' => true])
        ->expectsOutputToContain('Node ID must be a positive integer.')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'non-numeric' => 'app-dev',
    'zero' => '0',
    'negative' => '-1',
]);

it('prints the request id for node removal gateway api errors', function (): void {
    MockClient::global([
        ShowNodeRequest::class => MockResponse::make(existing_node_show_payload()),
        RemoveNodeRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'node.not_found',
                    'message' => 'Node was not found.',
                    'details' => [],
                ],
            ],
            404,
            [
                'X-Orbit-Request-Id' => remove_node_request_id(),
            ],
        ),
    ]);

    $this
        ->artisan('node:remove', ['node' => '2', '--force' => true])
        ->expectsOutputToContain('Node was not found.')
        ->expectsOutput('Request ID: '.remove_node_request_id())
        ->assertExitCode(1);
});

/** @return array<string, mixed> */
function existing_node_show_payload(int $id = 2, string $name = 'app-dev'): array
{
    return [
        'data' => [
            'id' => $id,
            'name' => $name,
            'status' => 'active',
            'public_ssh_host' => '10.0.0.3',
            'public_ssh_port' => 22,
            'user' => 'orbit',
            'roles' => [],
        ],
        'meta' => ['request_id' => remove_node_request_id()],
    ];
}

/** @return array{error: array{code: string, message: string, details: array<never, never>}} */
function missing_node_error_payload(): array
{
    return [
        'error' => [
            'code' => 'http.404',
            'message' => 'Resource not found.',
            'details' => [],
        ],
    ];
}

/** @return array{error: array{code: string, message: string, request_id: string}} */
function missing_node_error_json(): array
{
    return [
        'error' => [
            'code' => 'http.404',
            'message' => 'Resource not found.',
            'request_id' => remove_node_request_id(),
        ],
    ];
}

function removed_node_payload(): array
{
    return [
        'id' => 2,
        'name' => 'app-dev',
        'removed' => true,
    ];
}

/** @return array<string, mixed> */
function removed_node_expected_json(): array
{
    return [
        ...removed_node_payload(),
        'wireguard_peer_removed' => false,
        'dns_records_removed' => false,
        'degradation' => null,
        'roles_shed' => [],
        'retained_on_node' => [],
        'follow_up' => null,
        'request_id' => remove_node_request_id(),
    ];
}

/** @return array<string, mixed> */
function removed_node_degraded_payload(): array
{
    return [
        'id' => 3,
        'name' => 'app-prod',
        'removed' => true,
        'wireguard_peer_removed' => true,
        'dns_records_removed' => true,
        'degradation' => 'unreachable',
        'roles_shed' => ['app-prod'],
        'retained_on_node' => [
            'Caddy site configuration and certificates for the app-prod role',
        ],
        'follow_up' => 'Run the node-local Metrics cleanup on the node once it boots, or discard the node.',
    ];
}

/** @return array<string, mixed> */
function removed_node_degraded_expected_json(): array
{
    return [
        ...removed_node_degraded_payload(),
        'request_id' => remove_node_request_id(),
    ];
}

function remove_node_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
