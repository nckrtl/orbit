<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\AddNodeAccessRequest;
use Orbit\Sdk\Requests\Nodes\RemoveNodeAccessRequest;
use Orbit\Sdk\Responses\Nodes\AddedNodeAccessResponse;
use Orbit\Sdk\Responses\Nodes\RemovedNodeAccessResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-node-access-'.Str::uuid();
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

it('registers the exact node access add command signature surface', function (): void {
    $command = app(Kernel::class)->all()['node:access:add'] ?? null;

    expect($command)
        ->toBeInstanceOf(SymfonyCommand::class)
        ->and(array_keys($command?->getDefinition()->getArguments() ?? []))
        ->toBe(['consumer', 'serving'])
        ->and(command_options($command))
        ->toBe(['json' => false]);
});

it('rejects an invalid consumer node id before connector io', function (string $consumerId): void {
    $mockClient = MockClient::global();

    $this
        ->artisan('node:access:add', ['consumer' => $consumerId, 'serving' => '3'])
        ->expectsOutputToContain('Consumer ID must be a positive integer.')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'consumer non-numeric' => 'operator',
    'consumer zero' => '0',
    'consumer negative' => '-1',
]);

it('rejects an invalid serving node id before connector io', function (string $servingId): void {
    $mockClient = MockClient::global();

    $this
        ->artisan('node:access:add', ['consumer' => '2', 'serving' => $servingId])
        ->expectsOutputToContain('Serving ID must be a positive integer.')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'serving non-numeric' => 'app-dev',
    'serving zero' => '0',
    'serving negative' => '-1',
]);

it('sends one node access add request to the active gateway as json', function (): void {
    $mockClient = MockClient::global([
        AddNodeAccessRequest::class => MockResponse::make([
            'data' => added_node_access_payload(),
            'meta' => ['request_id' => node_access_command_request_id()],
        ]),
    ]);
    $expected = json_encode(
        [
            ...added_node_access_payload(),
            'request_id' => node_access_command_request_id(),
        ],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    );

    $this
        ->artisan('node:access:add', ['consumer' => '2', 'serving' => '3', '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(0);

    $request = $mockClient->getLastRequest();

    expect($request)
        ->toBeInstanceOf(AddNodeAccessRequest::class)
        ->and($request?->getMethod())
        ->toBe(Method::PUT)
        ->and($request?->resolveEndpoint())
        ->toBe('/api/v1/nodes/3/access/2')
        ->and($mockClient->getRecordedResponses())
        ->toHaveCount(1);
});

it('shows deterministic human output for a new node access edge', function (): void {
    MockClient::global([
        AddNodeAccessRequest::class => MockResponse::make([
            'data' => added_node_access_payload(),
            'meta' => ['request_id' => node_access_command_request_id()],
        ]),
    ]);

    $this
        ->artisan('node:access:add', ['consumer' => '2', 'serving' => '3'])
        ->expectsOutput('Access from [operator] (#2) to [app-dev] (#3) added.')
        ->expectsOutput('Request ID: '.node_access_command_request_id())
        ->assertExitCode(0);
});

it('shows deterministic human output for an existing node access edge', function (): void {
    $payload = added_node_access_payload();
    $payload['already_exists'] = true;

    MockClient::global([
        AddNodeAccessRequest::class => MockResponse::make([
            'data' => $payload,
            'meta' => ['request_id' => node_access_command_request_id()],
        ]),
    ]);

    $this
        ->artisan('node:access:add', ['consumer' => '2', 'serving' => '3'])
        ->expectsOutput('Access from [operator] (#2) to [app-dev] (#3) already exists.')
        ->expectsOutput('Request ID: '.node_access_command_request_id())
        ->assertExitCode(0);
});

it('renders gateway api failures through the shared boundary and exits one', function (): void {
    $expected = json_encode([
        'error' => [
            'code' => 'node_access.required',
            'message' => 'Node access is required.',
            'request_id' => node_access_command_request_id(),
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    MockClient::global([
        AddNodeAccessRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'node_access.required',
                    'message' => 'Node access is required.',
                    'details' => [
                        'consumer_node' => ['id' => 2, 'name' => 'operator'],
                        'serving_node' => ['id' => 3, 'name' => 'app-dev'],
                    ],
                ],
            ],
            403,
            ['X-Orbit-Request-Id' => node_access_command_request_id()],
        ),
    ]);

    $this
        ->artisan('node:access:add', ['consumer' => '2', 'serving' => '3', '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(1);
});

it('registers the exact node access remove command signature surface', function (): void {
    $command = app(Kernel::class)->all()['node:access:remove'] ?? null;

    expect($command)
        ->toBeInstanceOf(SymfonyCommand::class)
        ->and(array_keys($command?->getDefinition()->getArguments() ?? []))
        ->toBe(['consumer', 'serving'])
        ->and(command_options($command))
        ->toBe([
            'force' => false,
            'json' => false,
        ]);
});

it('rejects an invalid consumer node id for removal before connector io', function (string $consumerId): void {
    $mockClient = MockClient::global();

    $this
        ->artisan('node:access:remove', ['consumer' => $consumerId, 'serving' => '3', '--force' => true])
        ->expectsOutputToContain('Consumer ID must be a positive integer.')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'remove consumer non-numeric' => 'operator',
    'remove consumer zero' => '0',
    'remove consumer negative' => '-1',
]);

it('rejects an invalid serving node id for removal before connector io', function (string $servingId): void {
    $mockClient = MockClient::global();

    $this
        ->artisan('node:access:remove', ['consumer' => '2', 'serving' => $servingId, '--force' => true])
        ->expectsOutputToContain('Serving ID must be a positive integer.')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'remove serving non-numeric' => 'app-dev',
    'remove serving zero' => '0',
    'remove serving negative' => '-1',
]);

it('does not send a removal request when interactive confirmation is declined', function (): void {
    $mockClient = MockClient::global();

    $this
        ->artisan('node:access:remove', ['consumer' => '2', 'serving' => '3'])
        ->expectsConfirmation('Remove access from node #2 to node #3?', 'no')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
});

it('sends exactly one removal request when interactive confirmation is accepted', function (): void {
    $mockClient = MockClient::global([
        RemoveNodeAccessRequest::class => MockResponse::make([
            'data' => removed_node_access_payload(),
            'meta' => ['request_id' => node_access_command_request_id()],
        ]),
    ]);

    $this
        ->artisan('node:access:remove', ['consumer' => '2', 'serving' => '3'])
        ->expectsConfirmation('Remove access from node #2 to node #3?', 'yes')
        ->assertExitCode(0);

    expect($mockClient->getLastRequest())
        ->toBeInstanceOf(RemoveNodeAccessRequest::class)
        ->and($mockClient->getRecordedResponses())
        ->toHaveCount(1);
});

it('requires force for non-interactive human removal execution', function (): void {
    $mockClient = MockClient::global();

    $this
        ->artisan('node:access:remove', ['consumer' => '2', 'serving' => '3', '--no-interaction' => true])
        ->expectsOutputToContain('Use --force to confirm node access removal.')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
});

it('requires force for json removal execution', function (): void {
    $mockClient = MockClient::global();
    $expected = json_encode([
        'error' => [
            'code' => 'node_access.confirmation_required',
            'message' => 'Use --force to confirm node access removal.',
            'request_id' => null,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this
        ->artisan('node:access:remove', ['consumer' => '2', 'serving' => '3', '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
});

it('sends one node access removal request to the active gateway as json when forced', function (): void {
    $mockClient = MockClient::global([
        RemoveNodeAccessRequest::class => MockResponse::make([
            'data' => removed_node_access_payload(),
            'meta' => ['request_id' => node_access_command_request_id()],
        ]),
    ]);
    $expected = json_encode(removed_node_access_payload(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this
        ->artisan('node:access:remove', ['consumer' => '2', 'serving' => '3', '--force' => true, '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(0);

    $request = $mockClient->getLastRequest();

    expect($request)
        ->toBeInstanceOf(RemoveNodeAccessRequest::class)
        ->and($request?->getMethod())
        ->toBe(Method::DELETE)
        ->and($request?->resolveEndpoint())
        ->toBe('/api/v1/nodes/3/access/2')
        ->and($mockClient->getRecordedResponses())
        ->toHaveCount(1);
});

it('shows deterministic human output for removed node access', function (): void {
    MockClient::global([
        RemoveNodeAccessRequest::class => MockResponse::make([
            'data' => removed_node_access_payload(),
            'meta' => ['request_id' => node_access_command_request_id()],
        ]),
    ]);

    $this
        ->artisan('node:access:remove', ['consumer' => '2', 'serving' => '3', '--force' => true])
        ->expectsOutput('Access from [operator] (#2) to [app-dev] (#3) removed.')
        ->expectsOutput('Request ID: '.node_access_command_request_id())
        ->assertExitCode(0);
});

it('shows deterministic human output for already absent node access', function (): void {
    $payload = removed_node_access_payload();
    $payload['already_absent'] = true;

    MockClient::global([
        RemoveNodeAccessRequest::class => MockResponse::make([
            'data' => $payload,
            'meta' => ['request_id' => node_access_command_request_id()],
        ]),
    ]);

    $this
        ->artisan('node:access:remove', ['consumer' => '2', 'serving' => '3', '--force' => true])
        ->expectsOutput('Access from [operator] (#2) to [app-dev] (#3) was already absent.')
        ->expectsOutput('Request ID: '.node_access_command_request_id())
        ->assertExitCode(0);
});

it('shows a self lockout warning after successful removal', function (): void {
    $payload = removed_node_access_payload();
    $payload['self_lockout'] = true;

    MockClient::global([
        RemoveNodeAccessRequest::class => MockResponse::make([
            'data' => $payload,
            'meta' => ['request_id' => node_access_command_request_id()],
        ]),
    ]);

    $this
        ->artisan('node:access:remove', ['consumer' => '2', 'serving' => '3', '--force' => true])
        ->expectsOutput('Access from [operator] (#2) to [app-dev] (#3) removed.')
        ->expectsOutput('Warning: This node no longer has Gateway access.')
        ->expectsOutput('Request ID: '.node_access_command_request_id())
        ->assertExitCode(0);
});

it('renders node access removal gateway api failures through the shared boundary and exits one', function (): void {
    $expected = json_encode([
        'error' => [
            'code' => 'node_access.required',
            'message' => 'Node access is required.',
            'request_id' => node_access_command_request_id(),
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    MockClient::global([
        RemoveNodeAccessRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'node_access.required',
                    'message' => 'Node access is required.',
                    'details' => [
                        'consumer_node' => ['id' => 2, 'name' => 'operator'],
                        'serving_node' => ['id' => 3, 'name' => 'app-dev'],
                    ],
                ],
            ],
            403,
            ['X-Orbit-Request-Id' => node_access_command_request_id()],
        ),
    ]);

    $this
        ->artisan('node:access:remove', ['consumer' => '2', 'serving' => '3', '--force' => true, '--json' => true])
        ->expectsOutput($expected)
        ->assertExitCode(1);
});

function command_options(?SymfonyCommand $command): array
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
function added_node_access_payload(): array
{
    return AddedNodeAccessResponse::fromGatewayData([
        'consumer_node' => ['id' => 2, 'name' => 'operator'],
        'serving_node' => ['id' => 3, 'name' => 'app-dev'],
        'already_exists' => false,
    ], node_access_command_request_id())->toArray();
}

function node_access_command_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}

/** @return array<string, mixed> */
function removed_node_access_payload(): array
{
    return RemovedNodeAccessResponse::fromGatewayData([
        'consumer_node' => ['id' => 2, 'name' => 'operator'],
        'serving_node' => ['id' => 3, 'name' => 'app-dev'],
        'already_absent' => false,
        'self_lockout' => false,
    ], node_access_command_request_id())->toArray();
}
