<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\AppInstances\AppInstanceDeploymentLayoutRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-deployment-layout-'.Str::uuid();
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

describe('instance:prepare-deployment request', function (): void {
    it('sends one typed HTTP request and omits an unspecified SQLite source path', function (): void {
        $mock = MockClient::global([
            AppInstanceDeploymentLayoutRequest::class => prepare_deployment_response(),
        ]);

        $this
            ->artisan('instance:prepare-deployment', ['instance' => '17'])
            ->assertExitCode(0);

        expect($mock->getLastRequest())
            ->toBeInstanceOf(AppInstanceDeploymentLayoutRequest::class)
            ->and($mock->getLastPendingRequest()?->getUrl())
            ->toBe('https://10.44.0.1/api/v1/instances/17/deployment-layout')
            ->and($mock->getLastRequest()?->body()->all())
            ->toBeEmpty()
            ->and($mock->getRecordedResponses())
            ->toHaveCount(1);
    });

    it('transports the exact optional SQLite source path without local placement policy', function (): void {
        $mock = MockClient::global([
            AppInstanceDeploymentLayoutRequest::class => prepare_deployment_response(),
        ]);

        $this
            ->artisan('instance:prepare-deployment', [
                'instance' => '17',
                '--sqlite-source-path' => '../existing data/app.sqlite',
            ])
            ->assertExitCode(0);

        expect($mock->getLastRequest()?->body()->all())->toBe([
            'sqlite_source_path' => '../existing data/app.sqlite',
        ]);
    });

    it('refuses an invalid instance ID before an HTTP request', function (): void {
        $mock = MockClient::global();

        $exitCode = Artisan::call('instance:prepare-deployment', [
            'instance' => '0',
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(trim(Artisan::output()))
            ->toBe(prepare_deployment_error_json(
                code: 'instance.id_invalid',
                message: 'Instance ID must be a positive integer.',
            ))
            ->and($mock->getLastPendingRequest())
            ->toBeNull();
    });
});

describe('instance:prepare-deployment output', function (): void {
    it('renders a deterministic human result', function (): void {
        MockClient::global([
            AppInstanceDeploymentLayoutRequest::class => prepare_deployment_response(),
        ]);

        $this
            ->artisan('instance:prepare-deployment', ['instance' => '17'])
            ->expectsOutput('Deployment layout prepared for instance [main].')
            ->expectsOutput('Source layout: release')
            ->expectsOutput('Checkout: /home/orbit-app-17/releases/retained')
            ->expectsOutput('Effective root: /home/orbit-app-17/current/public')
            ->expectsOutput('Route hostname: app.example.test')
            ->expectsOutput('URL: https://app.example.test')
            ->expectsOutput('Request ID: '.prepare_deployment_request_id())
            ->assertExitCode(0);
    });

    it('renders the exact AppInstance JSON response', function (): void {
        MockClient::global([
            AppInstanceDeploymentLayoutRequest::class => prepare_deployment_response(),
        ]);

        $this
            ->artisan('instance:prepare-deployment', ['instance' => '17', '--json' => true])
            ->expectsOutput(prepare_deployment_json())
            ->assertExitCode(0);
    });
});

describe('instance:prepare-deployment failures', function (): void {
    it('renders a bounded correlated human failure', function (): void {
        MockClient::global([
            AppInstanceDeploymentLayoutRequest::class => prepare_deployment_failure_response(),
        ]);

        $this
            ->artisan('instance:prepare-deployment', [
                'instance' => '17',
                '--sqlite-source-path' => '/home/orbit-app-17/private-sentinel.sqlite',
            ])
            ->expectsOutputToContain('The deployment layout conflicts with existing content.')
            ->expectsOutput('Request ID: '.prepare_deployment_request_id())
            ->doesntExpectOutputToContain('private-sentinel.sqlite')
            ->assertExitCode(1);
    });

    it('renders a bounded correlated JSON failure', function (): void {
        MockClient::global([
            AppInstanceDeploymentLayoutRequest::class => prepare_deployment_failure_response(),
        ]);

        $exitCode = Artisan::call('instance:prepare-deployment', [
            'instance' => '17',
            '--sqlite-source-path' => '/home/orbit-app-17/private-sentinel.sqlite',
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(trim(Artisan::output()))
            ->toBe(prepare_deployment_error_json(
                code: 'instance.deployment_layout_conflict',
                message: 'The deployment layout conflicts with existing content.',
                requestId: prepare_deployment_request_id(),
            ))
            ->not->toContain('private-sentinel.sqlite');
    });
});

function prepare_deployment_response(): MockResponse
{
    return MockResponse::make([
        'data' => prepare_deployment_payload(),
        'meta' => ['request_id' => prepare_deployment_request_id()],
    ]);
}

function prepare_deployment_failure_response(): MockResponse
{
    return MockResponse::make([
        'error' => [
            'code' => 'instance.deployment_layout_conflict',
            'message' => 'The deployment layout conflicts with existing content.',
            'details' => ['source' => '/home/orbit-app-17/private-sentinel.sqlite'],
        ],
    ], 409, ['X-Orbit-Request-Id' => prepare_deployment_request_id()]);
}

/** @return array<string, mixed> */
function prepare_deployment_payload(): array
{
    return [
        'id' => 17,
        'app_id' => 3,
        'node_id' => 4,
        'name' => 'main',
        'environment' => 'production',
        'source_layout' => 'release',
        'checkout_path' => '/home/orbit-app-17/releases/retained',
        'production_user' => 'orbit-app-17',
        'production_home' => '/home/orbit-app-17',
        'root' => null,
        'effective_root' => '/home/orbit-app-17/current/public',
        'selected_branch' => 'main',
        'branch_override' => null,
        'migration_required' => false,
        'starting_commit' => str_repeat('a', 40),
        'detached' => false,
        'status' => 'active',
        'route' => null,
        'hostname' => 'app.example.test',
        'url' => 'https://app.example.test',
        'removal' => null,
    ];
}

function prepare_deployment_json(): string
{
    return json_encode([
        ...prepare_deployment_payload(),
        'request_id' => prepare_deployment_request_id(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function prepare_deployment_error_json(string $code, string $message, ?string $requestId = null): string
{
    return json_encode([
        'error' => [
            'code' => $code,
            'message' => $message,
            'request_id' => $requestId,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function prepare_deployment_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
