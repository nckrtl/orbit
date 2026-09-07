<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\AppInstances\CreateAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\ListAppInstancesRequest;
use Orbit\Sdk\Requests\AppInstances\RemoveAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\ShowAppInstanceRequest;
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

describe('instance:new', function (): void {
    it('documents the development source-only contract', function (): void {
        $this
            ->artisan('help', ['command_name' => 'instance:new'])
            ->expectsOutputToContain('Create a development AppInstance on an app-dev node.')
            ->expectsOutputToContain('default is reserved for the App default source')
            ->assertExitCode(0);
    });

    it('creates an AppInstance with inherited root as JSON', function (): void {
        $mockClient = MockClient::global([
            CreateAppInstanceRequest::class => instance_mock_response(201),
        ]);

        $this
            ->artisan('instance:new', [
                'app' => '3',
                'node' => '2',
                'name' => 'dev',
                '--json' => true,
            ])
            ->expectsOutput(instance_json())
            ->assertExitCode(0);

        $request = $mockClient->getLastRequest();

        expect($mockClient->getLastPendingRequest()?->getUrl())
            ->toBe('https://10.44.0.1/api/v1/instances')
            ->and($request)
            ->toBeInstanceOf(CreateAppInstanceRequest::class)
            ->and($request?->body()->all())
            ->toBe(['app_id' => 3, 'node_id' => 2, 'name' => 'dev']);
    });

    it('transports an optional root override without execution controls', function (): void {
        $mockClient = MockClient::global([
            CreateAppInstanceRequest::class => instance_mock_response(201),
        ]);

        $this
            ->artisan('instance:new', [
                'app' => '3',
                'node' => '2',
                'name' => 'dev',
                '--root' => 'site/public',
            ])
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())->toBe([
            'app_id' => 3,
            'node_id' => 2,
            'name' => 'dev',
            'root' => 'site/public',
        ]);
    });

    it('transports an optional Route hostname without local policy validation', function (): void {
        $mockClient = MockClient::global([
            CreateAppInstanceRequest::class => instance_mock_response(201),
        ]);

        $this
            ->artisan('instance:new', [
                'app' => '3',
                'node' => '2',
                'name' => 'dev',
                '--hostname' => 'Odd_Value',
            ])
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())->toBe([
            'app_id' => 3,
            'node_id' => 2,
            'name' => 'dev',
            'hostname' => 'Odd_Value',
        ]);
    });

    it('transports an optional branch without local policy validation', function (): void {
        $mockClient = MockClient::global([
            CreateAppInstanceRequest::class => instance_mock_response(201),
        ]);

        $this
            ->artisan('instance:new', [
                'app' => '3',
                'node' => '2',
                'name' => 'default',
                '--branch' => 'release',
            ])
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())->toBe([
            'app_id' => 3,
            'node_id' => 2,
            'name' => 'default',
            'branch' => 'release',
        ]);
    });

    it('reports the created AppInstance for humans', function (): void {
        MockClient::global([CreateAppInstanceRequest::class => instance_mock_response(201)]);

        $this
            ->artisan('instance:new', ['app' => '3', 'node' => '2', 'name' => 'dev'])
            ->expectsOutput('Instance [dev] is active.')
            ->expectsOutput('Source layout: checkout')
            ->expectsOutput('Selected branch: dev')
            ->expectsOutput('Branch override: -')
            ->expectsOutput('Migration required: no')
            ->expectsOutput('Route hostname: dev.orbit.test')
            ->expectsOutput('URL: https://dev.orbit.test')
            ->expectsOutput('Request ID: '.instance_request_id())
            ->assertExitCode(0);
    });
});

describe('instance:list', function (): void {
    it('lists AppInstances as JSON', function (): void {
        MockClient::destroyGlobal();
        MockClient::global([
            ListAppInstancesRequest::class => MockResponse::make([
                'data' => [instance_payload()],
                'meta' => ['request_id' => instance_request_id()],
            ]),
        ]);
        $expected = json_encode([
            'app_instances' => [[
                ...instance_payload(),
                'route' => [...instance_route_payload(), 'request_id' => instance_request_id()],
            ]],
            'request_id' => instance_request_id(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this
            ->artisan('instance:list', ['--json' => true])
            ->expectsOutput($expected)
            ->assertExitCode(0);
    });

    it('lists AppInstance source identity for humans', function (): void {
        MockClient::global([
            ListAppInstancesRequest::class => MockResponse::make([
                'data' => [instance_payload()],
                'meta' => ['request_id' => instance_request_id()],
            ]),
        ]);

        $this
            ->artisan('instance:list')
            ->expectsTable(
                [
                    'ID',
                    'App',
                    'Node',
                    'Name',
                    'Environment',
                    'Source layout',
                    'Root',
                    'Selected branch',
                    'Branch override',
                    'Migration required',
                    'Route hostname',
                    'URL',
                    'Status',
                    'Removal',
                ],
                [[
                    5,
                    3,
                    2,
                    'dev',
                    'development',
                    'checkout',
                    'public',
                    'dev',
                    '-',
                    'no',
                    'dev.orbit.test',
                    'https://dev.orbit.test',
                    'active',
                    '-',
                ]],
            )
            ->expectsOutput('Request ID: '.instance_request_id())
            ->assertExitCode(0);
    });

    it('lists bounded unfinished removal progress for humans and JSON', function (): void {
        $payload = instance_payload(removal: removal_progress_payload());
        MockClient::global([
            ListAppInstancesRequest::class => MockResponse::make([
                'data' => [$payload],
                'meta' => ['request_id' => instance_request_id()],
            ]),
        ]);

        $this
            ->artisan('instance:list')
            ->expectsOutputToContain(
                'normal 0/2 completed; 2 remaining; runtime_cleanup; failed runtime_cleanup (instance.runtime_interrupted)',
            )
            ->assertExitCode(0);

        MockClient::destroyGlobal();
        MockClient::global([
            ListAppInstancesRequest::class => MockResponse::make([
                'data' => [$payload],
                'meta' => ['request_id' => instance_request_id()],
            ]),
        ]);
        $this
            ->artisan('instance:list', ['--json' => true])
            ->expectsOutputToContain('"removal":{"operation_id":"0198e15d-16c4-7855-8eb2-182b53ad28bb"')
            ->assertExitCode(0);
    });
});

describe('instance:show', function (): void {
    it('shows an AppInstance as JSON', function (): void {
        MockClient::global([ShowAppInstanceRequest::class => instance_mock_response()]);

        $this
            ->artisan('instance:show', ['instance' => '5', '--json' => true])
            ->expectsOutput(instance_json())
            ->assertExitCode(0);
    });

    it('shows AppInstance source details for humans', function (): void {
        MockClient::global([ShowAppInstanceRequest::class => instance_mock_response()]);

        $this
            ->artisan('instance:show', ['instance' => '5'])
            ->expectsOutput('dev (#5): active')
            ->expectsOutput('App: 3')
            ->expectsOutput('Node: 2')
            ->expectsOutput('Source layout: checkout')
            ->expectsOutput('Checkout: /home/orbit/apps/orbit-docs/dev')
            ->expectsOutput('Root override: -')
            ->expectsOutput('Effective root: public')
            ->expectsOutput('Selected branch: dev')
            ->expectsOutput('Branch override: -')
            ->expectsOutput('Migration required: no')
            ->expectsOutput('Starting commit: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
            ->expectsOutput('Route hostname: dev.orbit.test')
            ->expectsOutput('URL: https://dev.orbit.test')
            ->assertExitCode(0);
    });

    it('shows bounded unfinished removal progress for humans', function (): void {
        $payload = instance_payload(removal: removal_progress_payload(force: true));
        MockClient::destroyGlobal();
        MockClient::global([ShowAppInstanceRequest::class => instance_mock_response(payload: $payload)]);

        $this
            ->artisan('instance:show', ['instance' => '5'])
            ->expectsOutput('dev (#5): removing')
            ->expectsOutput('Removal mode: forced')
            ->expectsOutput('Removal progress: 0/2 completed; 2 remaining')
            ->expectsOutput('Removal step: runtime_cleanup')
            ->expectsOutput('Removal failed step: runtime_cleanup')
            ->expectsOutput('Removal error code: instance.runtime_interrupted')
            ->assertExitCode(0);
    });

    it('shows bounded unfinished removal progress as JSON', function (): void {
        $payload = instance_payload(removal: removal_progress_payload(force: true));
        MockClient::global([ShowAppInstanceRequest::class => instance_mock_response(payload: $payload)]);

        $expected = json_encode([
            ...$payload,
            'route' => [...instance_route_payload(), 'request_id' => instance_request_id()],
            'request_id' => instance_request_id(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this
            ->artisan('instance:show', ['instance' => '5', '--json' => true])
            ->expectsOutput($expected)
            ->assertExitCode(0);
    });
});

describe('instance:remove', function (): void {
    it('removes an AppInstance in normal mode by default', function (): void {
        $mockClient = MockClient::global([RemoveAppInstanceRequest::class => removal_mock_response()]);

        $this
            ->artisan('instance:remove', ['instance' => '5', '--json' => true])
            ->expectsOutput(removal_json())
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())->toBeEmpty();
    });

    it('transports explicit force and renders bounded progress', function (): void {
        $mockClient = MockClient::global([RemoveAppInstanceRequest::class => removal_mock_response(force: true)]);

        $this
            ->artisan('instance:remove', ['instance' => '5', '--force' => true])
            ->expectsOutput('Instance [dev] removed.')
            ->expectsOutput('Mode: forced')
            ->expectsOutput('Progress: 2/2 completed; 0 remaining')
            ->expectsOutput('Current step: -')
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())->toBe(['force' => true]);
    });

    it('preserves bounded failed removal progress in human and JSON errors', function (): void {
        $failure = [
            'error' => [
                'code' => 'instance.runtime_interrupted',
                'message' => 'AppInstance removal was accepted but remains incomplete.',
                'details' => ['removal' => removal_progress_payload()],
                'request_id' => instance_request_id(),
            ],
        ];
        MockClient::global([
            RemoveAppInstanceRequest::class => MockResponse::make(
                $failure,
                502,
                ['X-Orbit-Request-Id' => instance_request_id()],
            ),
        ]);

        $this
            ->artisan('instance:remove', ['instance' => '5'])
            ->expectsOutputToContain('AppInstance removal was accepted but remains incomplete.')
            ->expectsOutput('Mode: normal')
            ->expectsOutput('Progress: 0/2 completed; 2 remaining')
            ->expectsOutput('Current step: runtime_cleanup')
            ->expectsOutput('Failed step: runtime_cleanup')
            ->expectsOutput('Error code: instance.runtime_interrupted')
            ->assertExitCode(1);

        MockClient::destroyGlobal();
        MockClient::global([
            RemoveAppInstanceRequest::class => MockResponse::make(
                $failure,
                502,
                ['X-Orbit-Request-Id' => instance_request_id()],
            ),
        ]);
        $expected = json_encode($failure, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this
            ->artisan('instance:remove', ['instance' => '5', '--json' => true])
            ->expectsOutput($expected)
            ->assertExitCode(1);
    });
});

it('rejects invalid Instance IDs before making an API request', function (string $command, string $instanceId): void {
    $mockClient = MockClient::global();

    $this
        ->artisan($command, ['instance' => $instanceId])
        ->expectsOutputToContain('Instance ID must be a positive integer.')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'show zero' => ['instance:show', '0'],
    'remove negative' => ['instance:remove', '-1'],
]);

it('rejects invalid parent IDs before creating an AppInstance', function (
    string $appId,
    string $nodeId,
    string $message,
): void {
    $mockClient = MockClient::global();

    $this
        ->artisan('instance:new', ['app' => $appId, 'node' => $nodeId, 'name' => 'dev'])
        ->expectsOutputToContain($message)
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'invalid app' => ['0', '2', 'App ID must be a positive integer.'],
    'invalid node' => ['3', '-1', 'Node ID must be a positive integer.'],
]);

/** @return array<string, mixed> */
function instance_payload(?array $removal = null): array
{
    return [
        'id' => 5,
        'app_id' => 3,
        'node_id' => 2,
        'name' => 'dev',
        'environment' => 'development',
        'source_layout' => 'checkout',
        'checkout_path' => '/home/orbit/apps/orbit-docs/dev',
        'root' => null,
        'effective_root' => 'public',
        'selected_branch' => 'dev',
        'branch_override' => null,
        'migration_required' => false,
        'starting_commit' => str_repeat('a', times: 40),
        'status' => $removal === null ? 'active' : 'removing',
        'route' => instance_route_payload(),
        'hostname' => 'dev.orbit.test',
        'url' => 'https://dev.orbit.test',
        'removal' => $removal,
    ];
}

/** @return array<string, mixed> */
function instance_route_payload(): array
{
    return [
        'id' => 8,
        'app_id' => 3,
        'node_id' => 2,
        'cluster_id' => null,
        'generation_basis_node_id' => 2,
        'hostname' => 'dev.orbit.test',
        'provenance' => 'generated',
        'publication' => 'private',
        'status' => 'active',
        'failed_step' => null,
        'error_code' => null,
        'target' => ['id' => 9, 'app_instance_id' => 5, 'position' => 0],
    ];
}

function instance_mock_response(int $status = 200, ?array $payload = null): MockResponse
{
    return MockResponse::make([
        'data' => $payload ?? instance_payload(),
        'meta' => ['request_id' => instance_request_id()],
    ], $status);
}

function removal_mock_response(bool $force = false): MockResponse
{
    return MockResponse::make([
        'data' => removal_payload($force),
        'meta' => ['request_id' => instance_request_id()],
    ]);
}

/** @return array<string, mixed> */
function removal_payload(bool $force = false): array
{
    return [
        'operation_id' => '0198e15d-16c4-7855-8eb2-182b53ad28bb',
        'id' => 5,
        'name' => 'dev',
        'force' => $force,
        'status' => 'completed',
        'current_step' => null,
        'total' => 2,
        'completed' => 2,
        'remaining' => 0,
        'failed_step' => null,
        'error_code' => null,
    ];
}

/** @return array<string, mixed> */
function removal_progress_payload(bool $force = false): array
{
    return [
        'operation_id' => '0198e15d-16c4-7855-8eb2-182b53ad28bb',
        'id' => 5,
        'name' => 'dev',
        'force' => $force,
        'status' => 'failed',
        'current_step' => 'runtime_cleanup',
        'total' => 2,
        'completed' => 0,
        'remaining' => 2,
        'failed_step' => 'runtime_cleanup',
        'error_code' => 'instance.runtime_interrupted',
    ];
}

function instance_json(): string
{
    return json_encode([
        ...instance_payload(),
        'route' => [...instance_route_payload(), 'request_id' => instance_request_id()],
        'request_id' => instance_request_id(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function removal_json(): string
{
    return json_encode([
        ...removal_payload(),
        'request_id' => instance_request_id(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function instance_request_id(): string
{
    return '0198e15d-16c4-7855-8eb2-182b53ad28ba';
}
