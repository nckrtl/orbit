<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Workspaces\CreateWorkspaceRequest;
use Orbit\Sdk\Requests\Workspaces\ListWorkspacesRequest;
use Orbit\Sdk\Requests\Workspaces\RemoveWorkspaceRequest;
use Orbit\Sdk\Requests\Workspaces\ShowWorkspaceRequest;
use Orbit\Sdk\Requests\Workspaces\UpdateWorkspacePhpRequest;
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

describe('workspace:new', function (): void {
    it('documents target-node checkout path behavior', function (): void {
        $this
            ->artisan('help', ['command_name' => 'workspace:new'])
            ->expectsOutputToContain(
                'Absolute target-node checkout path',
            )
            ->assertExitCode(0);
    });

    it('creates a workspace through the active gateway as JSON', function (): void {
        $mockClient = MockClient::global([
            CreateWorkspaceRequest::class => workspace_mock_response(201),
        ]);

        $this
            ->artisan('workspace:new', [
                'instance' => '5',
                'name' => 'feature-auth',
                '--branch' => 'feature/auth',
                '--path' => '/home/orbit/.orbit/worktrees/orbit-docs/feature-auth',
                '--php' => '8.4',
                '--json' => true,
            ])
            ->expectsOutput(workspace_json())
            ->assertExitCode(0);

        $request = $mockClient->getLastRequest();

        expect($mockClient->getLastPendingRequest()?->getUrl())
            ->toBe('https://10.44.0.1/api/v1/workspaces')
            ->and($request)
            ->toBeInstanceOf(CreateWorkspaceRequest::class)
            ->and($request?->body()->all())
            ->toBe([
                'instance_id' => 5,
                'name' => 'feature-auth',
                'branch' => 'feature/auth',
                'checkout_path' => '/home/orbit/.orbit/worktrees/orbit-docs/feature-auth',
                'php_version' => '8.4',
            ]);
    });

    it('creates a workspace with an explicit safe target-node path', function (): void {
        $mockClient = MockClient::global([
            CreateWorkspaceRequest::class => workspace_mock_response(201),
        ]);

        $this
            ->artisan('workspace:new', [
                'instance' => '5',
                'name' => 'feature-auth',
                '--path' => '/srv/users/nckrtl/projects/acme',
                '--json' => true,
            ])
            ->expectsOutput(workspace_json())
            ->assertExitCode(0);

        expect($mockClient->getLastRequest())
            ->toBeInstanceOf(CreateWorkspaceRequest::class)
            ->and($mockClient->getLastPendingRequest()?->getUrl())
            ->toBe('https://10.44.0.1/api/v1/workspaces')
            ->and($mockClient->getLastRequest()?->body()->all())
            ->toBe([
                'instance_id' => 5,
                'name' => 'feature-auth',
                'checkout_path' => '/srv/users/nckrtl/projects/acme',
            ]);
    });

    it('reports the created workspace for humans', function (): void {
        $mockClient = MockClient::global([
            CreateWorkspaceRequest::class => workspace_mock_response(201),
        ]);

        $this
            ->artisan('workspace:new', [
                'instance' => '5',
                'name' => 'feature-auth',
            ])
            ->expectsOutput('Workspace [feature-auth] is active.')
            ->expectsOutput('Request ID: '.workspace_request_id())
            ->assertExitCode(0);

        expect($mockClient->getLastRequest()?->body()->all())
            ->toBe([
                'instance_id' => 5,
                'name' => 'feature-auth',
            ]);
    });
});

describe('workspace:list', function (): void {
    it('lists workspaces as JSON', function (): void {
        MockClient::global([
            ListWorkspacesRequest::class => MockResponse::make([
                'data' => [workspace_payload()],
                'meta' => ['request_id' => workspace_request_id()],
            ]),
        ]);
        $expected = json_encode([
            'workspaces' => [workspace_payload()],
            'request_id' => workspace_request_id(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this
            ->artisan('workspace:list', ['--json' => true])
            ->expectsOutput($expected)
            ->assertExitCode(0);
    });

    it('lists workspaces for humans', function (): void {
        MockClient::global([
            ListWorkspacesRequest::class => MockResponse::make([
                'data' => [workspace_payload()],
                'meta' => ['request_id' => workspace_request_id()],
            ]),
        ]);

        $this
            ->artisan('workspace:list')
            ->expectsTable(
                ['ID', 'Instance', 'Node', 'Name', 'Branch', 'Status', 'PHP', 'Hostname'],
                [[7, 5, 2, 'feature-auth', 'feature/auth', 'active', '8.4', 'feature-auth.orbit-docs.beast']],
            )
            ->expectsOutput('Request ID: '.workspace_request_id())
            ->assertExitCode(0);
    });
});

describe('workspace:show', function (): void {
    it('shows a workspace as JSON', function (): void {
        $mockClient = MockClient::global([
            ShowWorkspaceRequest::class => workspace_mock_response(),
        ]);

        $this
            ->artisan('workspace:show', ['workspace' => '7', '--json' => true])
            ->expectsOutput(workspace_json())
            ->assertExitCode(0);

        expect($mockClient->getLastPendingRequest()?->getUrl())
            ->toBe('https://10.44.0.1/api/v1/workspaces/7');
    });

    it('shows workspace details for humans', function (): void {
        MockClient::global([ShowWorkspaceRequest::class => workspace_mock_response()]);

        $this
            ->artisan('workspace:show', ['workspace' => '7'])
            ->expectsOutput('feature-auth (#7): active')
            ->expectsOutput('Instance: 5')
            ->expectsOutput('Node: 2')
            ->expectsOutput('Branch: feature/auth')
            ->expectsOutput('Checkout: /home/orbit/.orbit/worktrees/orbit-docs/feature-auth')
            ->expectsOutput('PHP: 8.4')
            ->expectsOutput('Hostname: feature-auth.orbit-docs.beast')
            ->doesntExpectOutputToContain('Failure:')
            ->expectsOutput('Request ID: '.workspace_request_id())
            ->assertExitCode(0);
    });

    it('shows failure details when workspace convergence failed', function (): void {
        $payload = [
            ...workspace_payload(),
            'status' => 'failed',
            'failed_step' => 'caddy-config',
            'error_code' => 'runtime.caddy_failed',
        ];

        MockClient::global([
            ShowWorkspaceRequest::class => MockResponse::make([
                'data' => $payload,
                'meta' => ['request_id' => workspace_request_id()],
            ]),
        ]);

        $this
            ->artisan('workspace:show', ['workspace' => '7'])
            ->expectsOutput('feature-auth (#7): failed')
            ->expectsOutput('Failure: caddy-config / runtime.caddy_failed')
            ->assertExitCode(0);
    });
});

describe('workspace:remove', function (): void {
    it('removes a workspace as JSON', function (): void {
        $mockClient = MockClient::global([
            RemoveWorkspaceRequest::class => workspace_mock_response(),
        ]);

        $this
            ->artisan('workspace:remove', ['workspace' => '7', '--json' => true])
            ->expectsOutput(workspace_json())
            ->assertExitCode(0);

        expect($mockClient->getLastPendingRequest()?->getUrl())
            ->toBe('https://10.44.0.1/api/v1/workspaces/7');
    });

    it('reports the removed workspace for humans', function (): void {
        MockClient::global([RemoveWorkspaceRequest::class => workspace_mock_response()]);

        $this
            ->artisan('workspace:remove', ['workspace' => '7'])
            ->expectsOutput('Workspace [feature-auth] removed.')
            ->expectsOutput('Request ID: '.workspace_request_id())
            ->assertExitCode(0);
    });
});

describe('workspace:php', function (): void {
    it('updates the PHP version as JSON', function (): void {
        $mockClient = MockClient::global([
            UpdateWorkspacePhpRequest::class => workspace_mock_response(status: 200, phpVersion: '8.3'),
        ]);

        $this
            ->artisan('workspace:php', [
                'workspace' => '7',
                'version' => '8.3',
                '--json' => true,
            ])
            ->expectsOutput(workspace_json('8.3'))
            ->assertExitCode(0);

        expect($mockClient->getLastPendingRequest()?->getUrl())
            ->toBe('https://10.44.0.1/api/v1/workspaces/7/php')
            ->and($mockClient->getLastRequest()?->body()->all())
            ->toBe(['php_version' => '8.3']);
    });

    it('reports the updated PHP version for humans', function (): void {
        MockClient::global([
            UpdateWorkspacePhpRequest::class => workspace_mock_response(status: 200, phpVersion: '8.3'),
        ]);

        $this
            ->artisan('workspace:php', ['workspace' => '7', 'version' => '8.3'])
            ->expectsOutput('Workspace [feature-auth] now uses PHP 8.3.')
            ->expectsOutput('Request ID: '.workspace_request_id())
            ->assertExitCode(0);
    });
});

it('rejects invalid workspace IDs before making an API request', function (string $command, string $workspaceId): void {
    $mockClient = MockClient::global();
    $parameters = ['workspace' => $workspaceId];

    if ($command === 'workspace:php') {
        $parameters['version'] = '8.5';
    }

    $this
        ->artisan($command, $parameters)
        ->expectsOutputToContain('Workspace ID must be a positive integer.')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'show zero' => ['workspace:show', '0'],
    'remove negative' => ['workspace:remove', '-1'],
    'php non-numeric' => ['workspace:php', 'feature-auth'],
]);

it('rejects an invalid instance ID before creating a workspace', function (): void {
    $mockClient = MockClient::global();

    $this
        ->artisan('workspace:new', ['instance' => '0', 'name' => 'feature-auth'])
        ->expectsOutputToContain('Instance ID must be a positive integer.')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
});

it('rejects an unsafe checkout path before creating a workspace', function (string $path): void {
    $mockClient = MockClient::global();

    $this
        ->artisan('workspace:new', [
            'instance' => '5',
            'name' => 'feature-auth',
            '--path' => $path,
        ])
        ->expectsOutputToContain('Workspace checkout path must be a safe absolute path.')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'relative' => 'apps/orbit/feature-auth',
    'home root' => '/',
    'parent segment' => '/home/orbit/apps/../feature-auth',
    'dot segment' => '/home/orbit/apps/./feature-auth',
    'duplicate separator' => '/home/orbit/apps//feature-auth',
    'trailing separator' => '/home/orbit/apps/feature-auth/',
    'whitespace' => '/home/orbit/apps/feature auth',
    'backslash' => '/home/orbit/apps\\feature-auth',
    'unsafe character' => '/home/orbit/apps/feature@auth',
]);

it('rejects invalid PHP versions before making an API request', function (string $command, string $version): void {
    $mockClient = MockClient::global();
    $parameters = $command === 'workspace:new'
        ? ['instance' => '5', 'name' => 'feature-auth', '--php' => $version]
        : ['workspace' => '7', 'version' => $version];

    $this
        ->artisan($command, $parameters)
        ->expectsOutputToContain('PHP version must use major.minor format, for example 8.5.')
        ->assertExitCode(1);

    expect($mockClient->getLastPendingRequest())->toBeNull();
})->with([
    'new malformed' => ['workspace:new', 'php8.5'],
    'update patch version' => ['workspace:php', '8.5.1'],
]);

/** @return array<string, int|string|null> */
function workspace_payload(?string $phpVersion = '8.4'): array
{
    return [
        'id' => 7,
        'instance_id' => 5,
        'node_id' => 2,
        'name' => 'feature-auth',
        'branch' => 'feature/auth',
        'checkout_path' => '/home/orbit/.orbit/worktrees/orbit-docs/feature-auth',
        'php_version' => $phpVersion,
        'effective_php_version' => $phpVersion ?? '8.5',
        'hostname' => 'feature-auth.orbit-docs.beast',
        'status' => 'active',
        'failed_step' => null,
        'error_code' => null,
    ];
}

function workspace_mock_response(int $status = 200, ?string $phpVersion = '8.4'): MockResponse
{
    return MockResponse::make([
        'data' => workspace_payload($phpVersion),
        'meta' => ['request_id' => workspace_request_id()],
    ], $status);
}

function workspace_json(?string $phpVersion = '8.4'): string
{
    return json_encode([
        ...workspace_payload($phpVersion),
        'request_id' => workspace_request_id(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function workspace_request_id(): string
{
    return '0198e15e-4f0c-7c20-8619-8754c9455c71';
}
