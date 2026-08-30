<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\RemoveNodeRequest;
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

it('requires force before sending a destructive node removal request', function (): void {
    $mockClient = MockClient::global();

    $this
        ->artisan('node:remove', ['node' => '2'])
        ->expectsConfirmation('Remove this node from the gateway?', 'no')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
});

it('accepts explicit confirmation before sending one node removal request', function (): void {
    $mockClient = MockClient::global([
        RemoveNodeRequest::class => MockResponse::make([
            'data' => removed_node_payload(),
            'meta' => ['request_id' => remove_node_request_id()],
        ]),
    ]);

    $this
        ->artisan('node:remove', ['node' => '2'])
        ->expectsConfirmation('Remove this node from the gateway?', 'yes')
        ->assertExitCode(0);

    expect($mockClient->getLastRequest())
        ->toBeInstanceOf(RemoveNodeRequest::class)
        ->and($mockClient->getRecordedResponses())
        ->toHaveCount(1);
});

it('sends node removal to the active gateway as json', function (): void {
    $mockClient = MockClient::global([
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
        ->toHaveCount(1);
});

it('sends the offline claim and returns the full degraded json payload', function (): void {
    $mockClient = MockClient::global([
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

it('sends force true when an interactive confirmation grants consent', function (): void {
    $mockClient = MockClient::global([
        RemoveNodeRequest::class => MockResponse::make([
            'data' => removed_node_degraded_payload(),
            'meta' => ['request_id' => remove_node_request_id()],
        ]),
    ]);

    $this
        ->artisan('node:remove', ['node' => '3', '--offline' => true])
        ->expectsConfirmation('Remove this node from the gateway?', 'yes')
        ->assertExitCode(0);

    expect($mockClient->getLastPendingRequest()?->body()->all())
        ->toBe(['force' => true, 'offline' => true]);
});

it('shows deterministic human output for node removal', function (): void {
    MockClient::global([
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
        RemoveNodeRequest::class => MockResponse::make([
            'data' => removed_node_degraded_payload(),
            'meta' => ['request_id' => remove_node_request_id()],
        ]),
    ]);

    $this
        ->artisan('node:remove', ['node' => '3', '--offline' => true, '--force' => true])
        ->expectsOutput('Node [app-prod] removed.')
        ->expectsOutput('Warning: Node [app-prod] was unreachable. Orbit removed only the state it owns.')
        ->expectsOutput('Roles shed:')
        ->expectsOutput('  - app-prod')
        ->expectsOutput('Left on the node:')
        ->expectsOutput('  - Caddy site configuration and certificates for the app-prod role')
        ->expectsOutput('Run the node-local Metrics cleanup on the node once it boots, or discard the node.')
        ->expectsOutput('Request ID: '.remove_node_request_id())
        ->assertExitCode(0);
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
