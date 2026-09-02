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
            ->expectsOutputToContain('Development AppInstance and branch name')
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

    it('reports the created AppInstance for humans', function (): void {
        MockClient::global([CreateAppInstanceRequest::class => instance_mock_response(201)]);

        $this
            ->artisan('instance:new', ['app' => '3', 'node' => '2', 'name' => 'dev'])
            ->expectsOutput('Instance [dev] is active.')
            ->expectsOutput('Request ID: '.instance_request_id())
            ->assertExitCode(0);
    });
});

describe('instance:list', function (): void {
    it('lists AppInstances as JSON', function (): void {
        MockClient::global([
            ListAppInstancesRequest::class => MockResponse::make([
                'data' => [instance_payload()],
                'meta' => ['request_id' => instance_request_id()],
            ]),
        ]);
        $expected = json_encode([
            'app_instances' => [instance_payload()],
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
                ['ID', 'App', 'Node', 'Cluster', 'Name', 'Environment', 'Root', 'Branch', 'Status'],
                [[5, 3, 2, 7, 'dev', 'development', 'public', 'dev', 'active']],
            )
            ->expectsOutput('Request ID: '.instance_request_id())
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
            ->expectsOutput('Cluster: 7')
            ->expectsOutput('Checkout: /home/orbit/apps/orbit-docs/dev')
            ->expectsOutput('Root override: -')
            ->expectsOutput('Effective root: public')
            ->expectsOutput('Branch: dev')
            ->expectsOutput('Starting commit: aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')
            ->assertExitCode(0);
    });
});

describe('instance:remove', function (): void {
    it('removes an AppInstance without discard by default', function (): void {
        $mockClient = MockClient::global([RemoveAppInstanceRequest::class => instance_mock_response()]);

        $this
            ->artisan('instance:remove', ['instance' => '5', '--json' => true])
            ->expectsOutput(instance_json())
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())->toBeEmpty();
    });

    it('transports explicit destructive source-discard intent', function (): void {
        $mockClient = MockClient::global([RemoveAppInstanceRequest::class => instance_mock_response()]);

        $this
            ->artisan('instance:remove', ['instance' => '5', '--discard-source' => true])
            ->expectsOutput('Instance [dev] removed.')
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())->toBe(['discard_source' => true]);
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

/** @return array<string, int|string|null> */
function instance_payload(): array
{
    return [
        'id' => 5,
        'app_id' => 3,
        'node_id' => 2,
        'cluster_id' => 7,
        'name' => 'dev',
        'environment' => 'development',
        'checkout_path' => '/home/orbit/apps/orbit-docs/dev',
        'root' => null,
        'effective_root' => 'public',
        'selected_branch' => 'dev',
        'starting_commit' => str_repeat('a', times: 40),
        'status' => 'active',
    ];
}

function instance_mock_response(int $status = 200): MockResponse
{
    return MockResponse::make([
        'data' => instance_payload(),
        'meta' => ['request_id' => instance_request_id()],
    ], $status);
}

function instance_json(): string
{
    return json_encode([
        ...instance_payload(),
        'request_id' => instance_request_id(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function instance_request_id(): string
{
    return '0198e15d-16c4-7855-8eb2-182b53ad28ba';
}
