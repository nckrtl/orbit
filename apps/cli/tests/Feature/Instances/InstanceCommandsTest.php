<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Instances\CreateInstanceRequest;
use Orbit\Sdk\Requests\Instances\ListInstancesRequest;
use Orbit\Sdk\Requests\Instances\RemoveInstanceRequest;
use Orbit\Sdk\Requests\Instances\ShowInstanceRequest;
use Orbit\Sdk\Requests\Instances\UpdateInstancePhpRequest;
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
    it('documents the per-app node constraint and app-derived runtime identity', function (): void {
        $this
            ->artisan('help', ['command_name' => 'instance:new'])
            ->expectsOutputToContain('Create the single instance of an app on a node.')
            ->expectsOutputToContain('Metadata name; source path and hostname use the app slug')
            ->assertExitCode(0);
    });

    it('creates an instance through the active gateway as JSON', function (): void {
        $mockClient = MockClient::global([
            CreateInstanceRequest::class => instance_mock_response(201),
        ]);

        $this
            ->artisan('instance:new', [
                'app' => '3',
                'node' => '2',
                'name' => 'dev',
                '--environment' => 'development',
                '--document-root' => 'public',
                '--php' => '8.5',
                '--json' => true,
            ])
            ->expectsOutput(instance_json())
            ->assertExitCode(0);

        $request = $mockClient->getLastRequest();

        expect($mockClient->getLastPendingRequest()?->getUrl())
            ->toBe('https://10.44.0.1/api/v1/instances')
            ->and($request)
            ->toBeInstanceOf(CreateInstanceRequest::class)
            ->and($request?->body()->all())
            ->toMatchArray([
                'app_id' => 3,
                'node_id' => 2,
                'name' => 'dev',
                'environment' => 'development',
                'document_root' => 'public',
                'php_version' => '8.5',
            ]);
    });

    it('reports the created instance for humans', function (): void {
        MockClient::global([CreateInstanceRequest::class => instance_mock_response(201)]);

        $this
            ->artisan('instance:new', [
                'app' => '3',
                'node' => '2',
                'name' => 'dev',
            ])
            ->expectsOutput('Instance [dev] is active.')
            ->expectsOutput('Request ID: '.instance_request_id())
            ->assertExitCode(0);
    });
});

describe('instance:list', function (): void {
    it('lists instances as JSON', function (): void {
        MockClient::global([
            ListInstancesRequest::class => MockResponse::make([
                'data' => [instance_payload()],
                'meta' => ['request_id' => instance_request_id()],
            ]),
        ]);
        $expected = json_encode([
            'instances' => [instance_payload()],
            'request_id' => instance_request_id(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this
            ->artisan('instance:list', ['--json' => true])
            ->expectsOutput($expected)
            ->assertExitCode(0);
    });

    it('lists instances for humans', function (): void {
        MockClient::global([
            ListInstancesRequest::class => MockResponse::make([
                'data' => [instance_payload()],
                'meta' => ['request_id' => instance_request_id()],
            ]),
        ]);

        $this
            ->artisan('instance:list')
            ->expectsTable(
                ['ID', 'App', 'Node', 'Name', 'Environment', 'Status', 'PHP', 'Hostname'],
                [[5, 3, 2, 'dev', 'development', 'active', '8.5', 'orbit-docs.beast']],
            )
            ->expectsOutput('Request ID: '.instance_request_id())
            ->assertExitCode(0);
    });
});

describe('instance:show', function (): void {
    it('shows an instance as JSON', function (): void {
        $mockClient = MockClient::global([
            ShowInstanceRequest::class => instance_mock_response(),
        ]);

        $this
            ->artisan('instance:show', ['instance' => '5', '--json' => true])
            ->expectsOutput(instance_json())
            ->assertExitCode(0);

        expect($mockClient->getLastPendingRequest()?->getUrl())
            ->toBe('https://10.44.0.1/api/v1/instances/5');
    });

    it('shows instance details for humans', function (): void {
        MockClient::global([ShowInstanceRequest::class => instance_mock_response()]);

        $this
            ->artisan('instance:show', ['instance' => '5'])
            ->expectsOutput('dev (#5): active')
            ->expectsOutput('App: 3')
            ->expectsOutput('Node: 2')
            ->expectsOutput('Environment: development')
            ->expectsOutput('Checkout: /home/orbit/apps/orbit-docs')
            ->expectsOutput('Document root: public')
            ->expectsOutput('PHP: 8.5')
            ->expectsOutput('Hostname: orbit-docs.beast')
            ->expectsOutput('Certificate: internal')
            ->doesntExpectOutputToContain('Failure:')
            ->expectsOutput('Request ID: '.instance_request_id())
            ->assertExitCode(0);
    });

    it('shows failure details when instance convergence failed', function (): void {
        $payload = [
            ...instance_payload(),
            'status' => 'failed',
            'failed_step' => 'php-fpm-pool',
            'error_code' => 'runtime.php_failed',
        ];

        MockClient::global([
            ShowInstanceRequest::class => MockResponse::make([
                'data' => $payload,
                'meta' => ['request_id' => instance_request_id()],
            ]),
        ]);

        $this
            ->artisan('instance:show', ['instance' => '5'])
            ->expectsOutput('dev (#5): failed')
            ->expectsOutput('Failure: php-fpm-pool / runtime.php_failed')
            ->assertExitCode(0);
    });
});

describe('instance:remove', function (): void {
    it('removes an instance as JSON', function (): void {
        $mockClient = MockClient::global([
            RemoveInstanceRequest::class => instance_mock_response(),
        ]);

        $this
            ->artisan('instance:remove', ['instance' => '5', '--json' => true])
            ->expectsOutput(instance_json())
            ->assertExitCode(0);

        expect($mockClient->getLastPendingRequest()?->getUrl())
            ->toBe('https://10.44.0.1/api/v1/instances/5');
    });

    it('reports the removed instance for humans', function (): void {
        MockClient::global([RemoveInstanceRequest::class => instance_mock_response()]);

        $this
            ->artisan('instance:remove', ['instance' => '5'])
            ->expectsOutput('Instance [dev] removed.')
            ->expectsOutput('Request ID: '.instance_request_id())
            ->assertExitCode(0);
    });
});

describe('instance:php', function (): void {
    it('updates the PHP version as JSON', function (): void {
        $mockClient = MockClient::global([
            UpdateInstancePhpRequest::class => instance_mock_response(status: 200, phpVersion: '8.4'),
        ]);

        $this
            ->artisan('instance:php', [
                'instance' => '5',
                'version' => '8.4',
                '--json' => true,
            ])
            ->expectsOutput(instance_json('8.4'))
            ->assertExitCode(0);

        $request = $mockClient->getLastRequest();

        expect($mockClient->getLastPendingRequest()?->getUrl())
            ->toBe('https://10.44.0.1/api/v1/instances/5/php')
            ->and($request?->body()->all())
            ->toBe(['php_version' => '8.4']);
    });

    it('reports the updated PHP version for humans', function (): void {
        MockClient::global([
            UpdateInstancePhpRequest::class => instance_mock_response(status: 200, phpVersion: '8.4'),
        ]);

        $this
            ->artisan('instance:php', ['instance' => '5', 'version' => '8.4'])
            ->expectsOutput('Instance [dev] now uses PHP 8.4.')
            ->expectsOutput('Request ID: '.instance_request_id())
            ->assertExitCode(0);
    });
});

it('rejects invalid instance IDs before making an API request', function (string $command, string $instanceId): void {
    $mockClient = MockClient::global();
    $parameters = ['instance' => $instanceId];

    if ($command === 'instance:php') {
        $parameters['version'] = '8.5';
    }

    $this
        ->artisan($command, $parameters)
        ->expectsOutputToContain('Instance ID must be a positive integer.')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'show zero' => ['instance:show', '0'],
    'remove negative' => ['instance:remove', '-1'],
    'php non-numeric' => ['instance:php', 'dev'],
]);

it('rejects invalid parent IDs before creating an instance', function (
    string $appId,
    string $nodeId,
    string $message,
): void {
    $mockClient = MockClient::global();

    $this
        ->artisan('instance:new', [
            'app' => $appId,
            'node' => $nodeId,
            'name' => 'dev',
        ])
        ->expectsOutputToContain($message)
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'invalid app' => ['0', '2', 'App ID must be a positive integer.'],
    'invalid node' => ['3', '-1', 'Node ID must be a positive integer.'],
]);

it('rejects invalid PHP versions before making an API request', function (string $command, string $version): void {
    $mockClient = MockClient::global();
    $parameters = $command === 'instance:new'
        ? ['app' => '3', 'node' => '2', 'name' => 'dev', '--php' => $version]
        : ['instance' => '5', 'version' => $version];

    $this
        ->artisan($command, $parameters)
        ->expectsOutputToContain('PHP version must use major.minor format, for example 8.5.')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'new malformed' => ['instance:new', 'php8.5'],
    'update patch version' => ['instance:php', '8.5.1'],
]);

/** @return array<string, int|string|list<string>|null> */
function instance_payload(string $phpVersion = '8.5'): array
{
    return [
        'id' => 5,
        'app_id' => 3,
        'node_id' => 2,
        'name' => 'dev',
        'environment' => 'development',
        'checkout_path' => '/home/orbit/apps/orbit-docs',
        'document_root' => 'public',
        'php_version' => $phpVersion,
        'hostname' => 'orbit-docs.beast',
        'certificate_mode' => 'internal',
        'status' => 'active',
        'failed_step' => null,
        'error_code' => null,
    ];
}

function instance_mock_response(int $status = 200, string $phpVersion = '8.5'): MockResponse
{
    return MockResponse::make([
        'data' => instance_payload($phpVersion),
        'meta' => ['request_id' => instance_request_id()],
    ], $status);
}

function instance_json(string $phpVersion = '8.5'): string
{
    return json_encode([
        ...instance_payload($phpVersion),
        'request_id' => instance_request_id(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function instance_request_id(): string
{
    return '0198e15d-16c4-7855-8eb2-182b53ad28ba';
}
