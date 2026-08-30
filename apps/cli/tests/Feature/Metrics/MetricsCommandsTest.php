<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Metrics\DisableMetricsExporterRequest;
use Orbit\Sdk\Requests\Metrics\DisableMetricsRequest;
use Orbit\Sdk\Requests\Metrics\EnableMetricsExporterRequest;
use Orbit\Sdk\Requests\Metrics\EnableMetricsRequest;
use Orbit\Sdk\Requests\Metrics\ResetMetricsCredentialsRequest;
use Orbit\Sdk\Requests\Metrics\ShowMetricsCredentialsRequest;
use Orbit\Sdk\Requests\Metrics\ShowMetricsStatusRequest;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        'test',
        'https://10.44.0.1',
        '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

it('registers the six metrics commands', function (): void {
    $commands = collect($this->app->make(\Illuminate\Contracts\Console\Kernel::class)->all())
        ->keys()
        ->filter(static fn (string $name): bool => str_starts_with($name, 'metrics:'))
        ->values()
        ->all();

    expect($commands)->toContain(...[
        'metrics:enable',
        'metrics:disable',
        'metrics:status',
        'metrics:credentials',
        'metrics:exporter:enable',
        'metrics:exporter:disable',
    ]);
});

it('renders status exporter rows in JSON', function (): void {
    $requestId = '11111111-1111-4111-8111-111111111111';
    MockClient::global([
        ShowMetricsStatusRequest::class => MockResponse::make([
            'data' => [
                'enabled' => true,
                'url' => 'https://metrics.orbit',
                'assignment' => [
                    'id' => 2,
                    'node_id' => 7,
                    'node_name' => 'metrics-node',
                    'status' => 'active',
                    'failed_step' => null,
                    'error_code' => null,
                ],
                'prometheus' => 'healthy',
                'grafana' => 'healthy',
                'exporters' => [
                    [
                        'id' => 2,
                        'name' => 'metrics',
                        'desired' => true,
                        'actual' => 'active',
                        'reason' => 'metrics_node',
                        'degraded_reason' => null,
                    ],
                    [
                        'id' => 5,
                        'name' => 'app-prod',
                        'desired' => true,
                        'actual' => 'unknown',
                        'reason' => 'role_default',
                        'degraded_reason' => 'unreachable',
                    ],
                ],
            ],
            'meta' => ['request_id' => $requestId],
        ]),
    ]);

    $this
        ->artisan('metrics:status', ['--json' => true])
        ->expectsOutput(json_encode([
            'enabled' => true,
            'url' => 'https://metrics.orbit',
            'assignment' => [
                'id' => 2,
                'node_id' => 7,
                'node_name' => 'metrics-node',
                'status' => 'active',
                'failed_step' => null,
                'error_code' => null,
            ],
            'prometheus' => 'healthy',
            'grafana' => 'healthy',
            'exporters' => [
                [
                    'id' => 2,
                    'name' => 'metrics',
                    'desired' => true,
                    'actual' => 'active',
                    'reason' => 'metrics_node',
                    'degraded_reason' => null,
                ],
                [
                    'id' => 5,
                    'name' => 'app-prod',
                    'desired' => true,
                    'actual' => 'unknown',
                    'reason' => 'role_default',
                    'degraded_reason' => 'unreachable',
                ],
            ],
            'request_id' => $requestId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->assertExitCode(0);
});

it('blocks enabling when any assignment already exists', function (): void {
    MockClient::global([
        ShowMetricsStatusRequest::class => MockResponse::make([
            'data' => [
                'enabled' => true,
                'url' => 'https://metrics.orbit',
                'assignment' => [
                    'id' => 9,
                    'node_id' => 7,
                    'node_name' => 'metrics-node',
                    'status' => 'failed',
                    'failed_step' => 'metrics:runtime',
                    'error_code' => 'metrics.runtime_failed',
                ],
                'prometheus' => 'unknown',
                'grafana' => 'unknown',
                'exporters' => [],
            ],
            'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
        ]),
    ]);

    $this
        ->artisan('metrics:enable', ['node' => '7', '--json' => true])
        ->expectsOutput(json_encode([
            'error' => [
                'code' => 'metrics.assignment_exists',
                'message' => 'Metrics already has a non-terminal assignment.',
                'request_id' => null,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->assertExitCode(1);
});

it('renders credentials in human output without leaking them in errors', function (): void {
    $password = str_repeat('x', times: 12);
    MockClient::global([
        ShowMetricsCredentialsRequest::class => MockResponse::make([
            'data' => ['url' => 'https://metrics.orbit', 'username' => 'admin', 'password' => $password],
            'meta' => ['request_id' => '22222222-2222-4222-8222-222222222222'],
        ]),
    ]);

    $this
        ->artisan('metrics:credentials')
        ->expectsOutput('URL: https://metrics.orbit')
        ->expectsOutput('Username: admin')
        ->expectsOutput('Password: '.$password)
        ->expectsOutput('Request ID: 22222222-2222-4222-8222-222222222222')
        ->assertExitCode(0);
});

it('requires a node id in non-interactive enable mode', function (): void {
    MockClient::global([
        ShowMetricsStatusRequest::class => MockResponse::make([
            'data' => [
                'enabled' => false,
                'url' => null,
                'assignment' => null,
                'prometheus' => 'disabled',
                'grafana' => 'disabled',
                'exporters' => [],
            ],
            'meta' => ['request_id' => '44444444-4444-4444-8444-444444444444'],
        ]),
    ]);

    $this
        ->artisan('metrics:enable', ['--json' => true])
        ->expectsOutputToContain('Node ID or name is required.')
        ->assertExitCode(1);
});

it('enables Metrics on an explicit node and sends the node payload', function (): void {
    $mock = MockClient::global([
        ShowMetricsStatusRequest::class => MockResponse::make([
            'data' => [
                'enabled' => false,
                'url' => null,
                'assignment' => null,
                'prometheus' => 'disabled',
                'grafana' => 'disabled',
                'exporters' => [],
            ],
            'meta' => ['request_id' => '55555555-5555-4555-8555-555555555555'],
        ]),
        EnableMetricsRequest::class => MockResponse::make([
            'data' => ['node_id' => 7, 'status' => 'active'],
            'meta' => ['request_id' => '66666666-6666-4666-8666-666666666666'],
        ]),
    ]);

    $this
        ->artisan('metrics:enable', ['node' => '7'])
        ->expectsOutput('Metrics operation completed for node #7: active.')
        ->assertExitCode(0);

    expect($mock->getLastRequest()?->body()->all())->toBe(['node_id' => 7]);
});

it('requires force for non-interactive disable and purge', function (): void {
    $this
        ->artisan('metrics:disable', ['--json' => true])
        ->expectsOutputToContain('Non-interactive Metrics disable requires --force.')
        ->assertExitCode(1);
    $this
        ->artisan('metrics:disable', ['--purge-data' => true, '--json' => true])
        ->expectsOutputToContain('Non-interactive Metrics disable requires --force.')
        ->assertExitCode(1);
});

it('sends the force and purge disable payload', function (): void {
    $mock = MockClient::global([
        ShowMetricsStatusRequest::class => MockResponse::make([
            'data' => metrics_cli_status_payload([
                'id' => 9,
                'node_id' => 7,
                'node_name' => 'metrics-node',
                'status' => 'active',
                'failed_step' => null,
                'error_code' => null,
            ]),
            'meta' => ['request_id' => '88888888-8888-4888-8888-888888888888'],
        ]),
        DisableMetricsRequest::class => MockResponse::make([
            'data' => ['node_id' => 7, 'status' => 'removed'],
            'meta' => ['request_id' => '77777777-7777-4777-8777-777777777777'],
        ]),
    ]);

    $this
        ->artisan('metrics:disable', ['--force' => true, '--purge-data' => true, '--json' => true])
        ->expectsOutput(json_encode([
            'node_id' => 7,
            'status' => 'removed',
            'request_id' => '77777777-7777-4777-8777-777777777777',
        ], JSON_THROW_ON_ERROR))
        ->assertExitCode(0);

    expect($mock->getLastRequest()?->body()->all())->toBe(['force' => true, 'purge_data' => true]);
});

it('prompts from the active eligible node list before enabling Metrics', function (): void {
    $mock = MockClient::global([
        ShowMetricsStatusRequest::class => MockResponse::make([
            'data' => metrics_cli_status_payload(),
            'meta' => ['request_id' => metrics_cli_request_id()],
        ]),
        ListNodesRequest::class => MockResponse::make([
            'data' => [
                metrics_cli_node_payload(3, 'app-dev', 'active', ['app-dev']),
                metrics_cli_node_payload(7, 'orbit-ops', 'active', []),
                metrics_cli_node_payload(8, 'pending', 'provisioning', []),
            ],
            'meta' => ['request_id' => metrics_cli_request_id()],
        ]),
        EnableMetricsRequest::class => MockResponse::make([
            'data' => ['node_id' => 3, 'status' => 'active'],
            'meta' => ['request_id' => metrics_cli_request_id()],
        ]),
    ]);

    $this
        ->artisan('metrics:enable')
        ->expectsOutput('Eligible active nodes:')
        ->expectsTable(
            ['ID', 'Name', 'Roles'],
            [
                [3, 'app-dev',   'app-dev'],
                [7, 'orbit-ops', '-'],
            ],
        )
        ->expectsQuestion('Node ID or name', '3')
        ->expectsOutput('Metrics operation completed for node #3: active.')
        ->expectsOutput('Request ID: '.metrics_cli_request_id())
        ->assertSuccessful();

    $mock->assertSentInOrder([
        ShowMetricsStatusRequest::class,
        ListNodesRequest::class,
        EnableMetricsRequest::class,
    ]);
    expect($mock->getLastRequest()?->body()->all())->toBe(['node_id' => 3]);
});

it('resolves a typed node name against the already-fetched node list without listing nodes twice', function (): void {
    $mock = MockClient::global([
        ShowMetricsStatusRequest::class => MockResponse::make([
            'data' => metrics_cli_status_payload(),
            'meta' => ['request_id' => metrics_cli_request_id()],
        ]),
        ListNodesRequest::class => MockResponse::make([
            'data' => [
                metrics_cli_node_payload(3, 'app-dev', 'active', ['app-dev']),
                metrics_cli_node_payload(7, 'orbit-ops', 'active', []),
            ],
            'meta' => ['request_id' => metrics_cli_request_id()],
        ]),
        EnableMetricsRequest::class => MockResponse::make([
            'data' => ['node_id' => 7, 'status' => 'active'],
            'meta' => ['request_id' => metrics_cli_request_id()],
        ]),
    ]);

    $this
        ->artisan('metrics:enable')
        ->expectsOutput('Eligible active nodes:')
        ->expectsQuestion('Node ID or name', 'orbit-ops')
        ->expectsOutput('Metrics operation completed for node #7: active.')
        ->expectsOutput('Request ID: '.metrics_cli_request_id())
        ->assertSuccessful();

    $mock->assertSentCount(1, ListNodesRequest::class);
    $mock->assertSentInOrder([
        ShowMetricsStatusRequest::class,
        ListNodesRequest::class,
        EnableMetricsRequest::class,
    ]);
    expect($mock->getLastRequest()?->body()->all())->toBe(['node_id' => 7]);
});

it('rejects an existing assignment before listing or prompting for nodes', function (): void {
    $mock = MockClient::global([
        ShowMetricsStatusRequest::class => MockResponse::make([
            'data' => metrics_cli_status_payload([
                'id' => 9,
                'node_id' => 3,
                'node_name' => 'app-dev',
                'status' => 'failed',
                'failed_step' => 'metrics:runtime',
                'error_code' => 'metrics.runtime_failed',
            ]),
            'meta' => ['request_id' => metrics_cli_request_id()],
        ]),
    ]);

    $this
        ->artisan('metrics:enable')
        ->doesntExpectOutput('Eligible active nodes:')
        ->expectsOutputToContain('Metrics already has a non-terminal assignment.')
        ->assertExitCode(SymfonyCommand::FAILURE);

    $mock->assertSentCount(1);
    $mock->assertSent(ShowMetricsStatusRequest::class);
    $mock->assertNotSent(ListNodesRequest::class);
    $mock->assertNotSent(EnableMetricsRequest::class);
});

it('returns field=node when non-interactive enable omits the node', function (): void {
    MockClient::global([
        ShowMetricsStatusRequest::class => MockResponse::make([
            'data' => metrics_cli_status_payload(),
            'meta' => ['request_id' => metrics_cli_request_id()],
        ]),
    ]);

    $this
        ->artisan('metrics:enable', ['--json' => true])
        ->expectsOutput(json_encode([
            'error' => [
                'code' => 'metrics.node_required',
                'message' => 'Node ID or name is required.',
                'details' => ['field' => 'node'],
                'request_id' => null,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->assertExitCode(SymfonyCommand::FAILURE);
});

it('cancels interactive disable without sending a mutation', function (): void {
    $mock = MockClient::global([
        ShowMetricsStatusRequest::class => MockResponse::make([
            'data' => metrics_cli_status_payload([
                'id' => 2,
                'node_id' => 3,
                'node_name' => 'app-dev',
                'status' => 'active',
                'failed_step' => null,
                'error_code' => null,
            ]),
            'meta' => ['request_id' => metrics_cli_request_id()],
        ]),
    ]);

    $this
        ->artisan('metrics:disable')
        ->expectsOutput('Metrics disable preview:')
        ->expectsOutput('  Data: preserve')
        ->expectsOutput('  Assignment: remove')
        ->expectsConfirmation('Disable Metrics?', 'no')
        ->expectsOutput('Confirmation is required.')
        ->assertExitCode(SymfonyCommand::FAILURE);

    $mock->assertSentCount(1);
    $mock->assertNotSent(DisableMetricsRequest::class);
});

it('accepts interactive disable and preserves data', function (): void {
    $mock = MockClient::global([
        ShowMetricsStatusRequest::class => MockResponse::make([
            'data' => metrics_cli_status_payload([
                'id' => 2,
                'node_id' => 3,
                'node_name' => 'app-dev',
                'status' => 'active',
                'failed_step' => null,
                'error_code' => null,
            ]),
            'meta' => ['request_id' => metrics_cli_request_id()],
        ]),
        DisableMetricsRequest::class => MockResponse::make([
            'data' => ['node_id' => 3, 'status' => 'removed'],
            'meta' => ['request_id' => metrics_cli_request_id()],
        ]),
    ]);

    $this
        ->artisan('metrics:disable')
        ->expectsConfirmation('Disable Metrics?', 'yes')
        ->expectsOutput('Metrics operation completed for node #3: removed.')
        ->expectsOutput('Request ID: '.metrics_cli_request_id())
        ->assertSuccessful();

    $mock->assertSentInOrder([ShowMetricsStatusRequest::class, DisableMetricsRequest::class]);
    expect($mock->getLastRequest()?->body()->all())->toBe(['force' => true, 'purge_data' => false]);
});

it('resets credentials through the focused request and renders exact JSON', function (): void {
    $password = str_repeat('r', times: 24);
    $mock = MockClient::global([
        ResetMetricsCredentialsRequest::class => MockResponse::make([
            'data' => [
                'url' => 'https://metrics.orbit',
                'username' => 'admin',
                'password' => $password,
            ],
            'meta' => ['request_id' => metrics_cli_request_id()],
        ]),
    ]);

    $this
        ->artisan('metrics:credentials', ['--reset' => true, '--json' => true])
        ->expectsOutput(json_encode([
            'url' => 'https://metrics.orbit',
            'username' => 'admin',
            'password' => $password,
            'request_id' => metrics_cli_request_id(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->assertSuccessful();

    expect($mock->getLastRequest())
        ->toBeInstanceOf(ResetMetricsCredentialsRequest::class)
        ->and($mock->getLastRequest()?->getMethod())
        ->toBe(Method::POST)
        ->and($mock->getLastPendingRequest()?->getUrl())
        ->toBe('https://10.44.0.1/api/v1/metrics/credentials/reset');
});

it('enables Metrics on a node given by name', function (): void {
    $mock = MockClient::global([
        ShowMetricsStatusRequest::class => MockResponse::make([
            'data' => metrics_cli_status_payload(),
            'meta' => ['request_id' => metrics_cli_request_id()],
        ]),
        ListNodesRequest::class => MockResponse::make([
            'data' => [
                metrics_cli_node_payload(1, 'gateway', 'active', ['gateway', 'vpn']),
                metrics_cli_node_payload(2, 'app-dev', 'active', ['app-dev']),
            ],
            'meta' => ['request_id' => metrics_cli_request_id()],
        ]),
        EnableMetricsRequest::class => MockResponse::make([
            'data' => ['node_id' => 2, 'status' => 'active'],
            'meta' => ['request_id' => metrics_cli_request_id()],
        ]),
    ]);

    $this
        ->artisan('metrics:enable', ['node' => 'app-dev', '--json' => true])
        ->expectsOutput(json_encode([
            'node_id' => 2,
            'status' => 'active',
            'request_id' => metrics_cli_request_id(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->assertSuccessful();

    expect($mock->getLastRequest()?->body()->all())->toBe(['node_id' => 2]);
});

it('rejects an unknown node name before any Metrics mutation', function (): void {
    $mock = MockClient::global([
        ShowMetricsStatusRequest::class => MockResponse::make([
            'data' => metrics_cli_status_payload(),
            'meta' => ['request_id' => metrics_cli_request_id()],
        ]),
        ListNodesRequest::class => MockResponse::make([
            'data' => [metrics_cli_node_payload(2, 'app-dev', 'active', ['app-dev'])],
            'meta' => ['request_id' => metrics_cli_request_id()],
        ]),
    ]);

    $this
        ->artisan('metrics:enable', ['node' => 'missing', '--json' => true])
        ->expectsOutputToContain('"code":"node.not_found"')
        ->assertExitCode(1);

    $mock->assertNotSent(EnableMetricsRequest::class);
});

it('resolves exporter node names through the node list', function (): void {
    $mock = MockClient::global([
        ListNodesRequest::class => MockResponse::make([
            'data' => [metrics_cli_node_payload(3, 'app-prod', 'active', ['app-prod'])],
            'meta' => ['request_id' => metrics_cli_request_id()],
        ]),
        DisableMetricsExporterRequest::class => MockResponse::make([
            'data' => ['node_id' => 3, 'status' => 'active'],
            'meta' => ['request_id' => metrics_cli_request_id()],
        ]),
    ]);

    $this
        ->artisan('metrics:exporter:disable', ['node' => 'app-prod', '--json' => true])
        ->assertSuccessful();

    expect($mock->getLastPendingRequest()?->getUrl())
        ->toBe('https://10.44.0.1/api/v1/metrics/exporters/3');
});

it('sends the selected node through each exporter request', function (
    string $command,
    string $requestClass,
    Method $method,
): void {
    $mock = MockClient::global([
        $requestClass => MockResponse::make([
            'data' => ['node_id' => 7, 'status' => 'active'],
            'meta' => ['request_id' => metrics_cli_request_id()],
        ]),
    ]);

    $this
        ->artisan($command, ['node' => '7', '--json' => true])
        ->expectsOutput(json_encode([
            'node_id' => 7,
            'status' => 'active',
            'request_id' => metrics_cli_request_id(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->assertSuccessful();

    expect($mock->getLastRequest())
        ->toBeInstanceOf($requestClass)
        ->and($mock->getLastRequest()?->getMethod())
        ->toBe($method)
        ->and($mock->getLastPendingRequest()?->getUrl())
        ->toBe('https://10.44.0.1/api/v1/metrics/exporters/7');
})->with([
    'enable' => ['metrics:exporter:enable', EnableMetricsExporterRequest::class, Method::PUT],
    'disable' => ['metrics:exporter:disable', DisableMetricsExporterRequest::class, Method::DELETE],
]);

it('renders complete human status tables', function (): void {
    MockClient::global([
        ShowMetricsStatusRequest::class => MockResponse::make([
            'data' => [
                ...metrics_cli_status_payload([
                    'id' => 2,
                    'node_id' => 3,
                    'node_name' => 'app-dev',
                    'status' => 'active',
                    'failed_step' => null,
                    'error_code' => null,
                ]),
                'enabled' => true,
                'url' => 'https://metrics.orbit',
                'prometheus' => 'healthy',
                'grafana' => 'healthy',
                'exporters' => [
                    [
                        'id' => 7,
                        'name' => 'orbit-ops',
                        'desired' => true,
                        'actual' => 'active',
                        'reason' => 'explicit_enabled',
                    ],
                    [
                        'id' => 9,
                        'name' => 'unreachable-node',
                        'desired' => true,
                        'actual' => 'unknown',
                        'reason' => 'role_default',
                        'degraded_reason' => 'unreachable',
                    ],
                ],
            ],
            'meta' => ['request_id' => metrics_cli_request_id()],
        ]),
    ]);

    $this
        ->artisan('metrics:status')
        ->expectsTable(['Field', 'Value'], [
            ['Enabled',    'yes'],
            ['URL',        'https://metrics.orbit'],
            ['Assignment', '#2 (active) on app-dev'],
            ['Prometheus', 'healthy'],
            ['Grafana',    'healthy'],
        ])
        ->expectsTable(['ID', 'Node', 'Desired', 'Actual', 'Reason', 'Degraded'], [
            [7, 'orbit-ops',        'yes', 'active',  'explicit_enabled', '-'],
            [9, 'unreachable-node', 'yes', 'unknown', 'role_default',     'unreachable'],
        ])
        ->expectsOutput('Request ID: '.metrics_cli_request_id())
        ->assertSuccessful();
});

it('renders the failed step and error code for a failed assignment in human status output', function (): void {
    MockClient::global([
        ShowMetricsStatusRequest::class => MockResponse::make([
            'data' => metrics_cli_status_payload([
                'id' => 9,
                'node_id' => 3,
                'node_name' => 'app-dev',
                'status' => 'failed',
                'failed_step' => 'metrics:runtime',
                'error_code' => 'metrics.runtime_failed',
            ]),
            'meta' => ['request_id' => metrics_cli_request_id()],
        ]),
    ]);

    $this
        ->artisan('metrics:status')
        ->expectsTable(['Field', 'Value'], [
            ['Enabled',     'yes'],
            ['URL',         'https://metrics.orbit'],
            ['Assignment',  '#9 (failed) on app-dev'],
            ['Failed step', 'metrics:runtime'],
            ['Error code',  'metrics.runtime_failed'],
            ['Prometheus',  'unknown'],
            ['Grafana',     'unknown'],
        ])
        ->expectsOutput('Request ID: '.metrics_cli_request_id())
        ->assertSuccessful();
});

it('renders structured secret-safe failures for every Metrics command', function (
    string $command,
    array $arguments,
    string $requestClass,
): void {
    $secret = str_repeat('s', times: 24);
    $mock = MockClient::global([
        $requestClass => MockResponse::make(
            [
                'error' => [
                    'code' => 'metrics.runtime_failed',
                    'message' => 'Metrics request failed.',
                    'details' => ['password' => $secret],
                ],
            ],
            502,
            ['X-Orbit-Request-Id' => metrics_cli_request_id()],
        ),
    ]);

    $exitCode = Artisan::call($command, [...$arguments, '--json' => true]);
    $output = trim(Artisan::output());

    expect($exitCode)
        ->toBe(SymfonyCommand::FAILURE)
        ->and($output)
        ->toBe(json_encode([
            'error' => [
                'code' => 'metrics.runtime_failed',
                'message' => 'Metrics request failed.',
                'request_id' => metrics_cli_request_id(),
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->not->toContain('details')
        ->not->toContain($secret);
    $mock->assertSent($requestClass);
})->with([
    'enable preflight' => ['metrics:enable', ['node' => '3'], ShowMetricsStatusRequest::class],
    'disable preflight' => ['metrics:disable', ['--force' => true], ShowMetricsStatusRequest::class],
    'status' => ['metrics:status', [], ShowMetricsStatusRequest::class],
    'credentials' => ['metrics:credentials', [], ShowMetricsCredentialsRequest::class],
    'credential reset' => ['metrics:credentials', ['--reset' => true], ResetMetricsCredentialsRequest::class],
    'exporter enable' => ['metrics:exporter:enable', ['node' => '7'], EnableMetricsExporterRequest::class],
    'exporter disable' => ['metrics:exporter:disable', ['node' => '7'], DisableMetricsExporterRequest::class],
]);

/**
 * @param array{id:int,node_id:int,node_name:string,status:string,failed_step:?string,error_code:?string}|null $assignment
 * @return array{enabled: bool, url: ?string, assignment: ?array{id:int,node_id:int,node_name:string,status:string,failed_step:?string,error_code:?string}, prometheus: string, grafana: string, exporters: array{}}
 */
function metrics_cli_status_payload(?array $assignment = null): array
{
    $enabled = $assignment !== null;

    return [
        'enabled' => $enabled,
        'url' => $enabled ? 'https://metrics.orbit' : null,
        'assignment' => $assignment,
        'prometheus' => $enabled ? 'unknown' : 'disabled',
        'grafana' => $enabled ? 'unknown' : 'disabled',
        'exporters' => [],
    ];
}

/** @param list<string> $roles */
function metrics_cli_node_payload(int $id, string $name, string $status, array $roles): array
{
    return [
        'id' => $id,
        'name' => $name,
        'status' => $status,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'tld' => "{$name}.orbit",
        'public_ssh_host' => "{$name}.example.test",
        'public_ssh_port' => 22,
        'ssh_user' => 'orbit',
        'wireguard_address' => "10.44.0.{$id}",
        'wireguard_public_key' => "{$name}-public-key",
        'wireguard_endpoint_override' => null,
        'dns_server_override' => null,
        'ssh_host_fingerprint' => null,
        'failed_step' => null,
        'error_code' => null,
        'roles' => $roles,
    ];
}

function metrics_cli_request_id(): string
{
    return '99999999-9999-4999-8999-999999999999';
}
