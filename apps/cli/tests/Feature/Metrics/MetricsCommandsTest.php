<?php

declare(strict_types=1);

use App\Commands\Metrics\EnableMetricsCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel;
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
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Orbit\Sdk\Responses\Nodes\NodesResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->previousColumns = getenv('COLUMNS');
    putenv('COLUMNS=200');
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
    if ($this->previousColumns === false) {
        putenv('COLUMNS');
    } else {
        putenv('COLUMNS='.$this->previousColumns);
    }
});

it('registers the six metrics commands', function (): void {
    $commands = collect($this->app->make(Kernel::class)->all())
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

it('renders the Gateway role conflict for a second enable while an assignment exists', function (): void {
    $originalColumns = getenv('COLUMNS');
    putenv('COLUMNS=120');
    $this->beforeApplicationDestroyed(static function () use ($originalColumns): void {
        putenv($originalColumns === false ? 'COLUMNS' : 'COLUMNS='.$originalColumns);
    });

    $mock = MockClient::global([EnableMetricsRequest::class => metrics_cli_role_conflict_response()]);

    $this
        ->artisan('metrics:enable', ['node' => '7', '--json' => true])
        ->expectsOutput(json_encode([
            'error' => [
                'code' => 'node.role_conflict',
                'message' => 'The metrics role is already assigned; remove it before enabling it on another node.',
                'request_id' => metrics_cli_request_id(),
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->assertExitCode(1);

    $mock->assertSentCount(1);
    $mock->assertNotSent(ShowMetricsStatusRequest::class);
    expect($mock->getLastRequest()?->body()->all())->toBe(['node_id' => 7]);

    $this
        ->artisan('metrics:enable', ['node' => '7'])
        ->expectsOutputToContain('The metrics role is already assigned; remove it before enabling it on another node.')
        ->expectsOutput('Request ID: '.metrics_cli_request_id())
        ->assertExitCode(1);

    $mock->assertSentCount(2);
    $mock->assertNotSent(ShowMetricsStatusRequest::class);
});

it('renders credentials in human output without leaking them in errors', function (): void {
    $password = str_repeat('x', times: 12);
    MockClient::global([
        ShowMetricsCredentialsRequest::class => MockResponse::make([
            'data' => ['url' => 'https://metrics.orbit', 'username' => 'admin', 'password' => $password],
            'meta' => ['request_id' => '22222222-2222-4222-8222-222222222222'],
        ]),
    ]);

    [$exit, $output] = metrics_cli_display('metrics:credentials');
    $flat = preg_replace('/[ \t]+/', ' ', $output) ?? $output;

    expect($exit)->toBe(0)
        ->and($flat)->toContain('URL https://metrics.orbit')
        ->and($flat)->toContain('Username admin')
        ->and($flat)->toContain('Password '.$password)
        ->and($output)->toContain('22222222-2222-4222-8222-222222222222');
});

it('requires a node id in non-interactive enable mode', function (): void {
    $mock = MockClient::global();

    $this
        ->artisan('metrics:enable', ['--json' => true])
        ->expectsOutputToContain('Node ID or name is required.')
        ->assertExitCode(1);

    expect($mock->getLastPendingRequest())->toBeNull();
});

it('enables Metrics on an explicit node and sends the node payload', function (): void {
    $mock = MockClient::global([
        EnableMetricsRequest::class => MockResponse::make([
            'data' => ['node_id' => 7, 'status' => 'active'],
            'meta' => ['request_id' => '66666666-6666-4666-8666-666666666666'],
        ]),
    ]);

    [$exit, $output] = metrics_cli_display('metrics:enable', ['node' => '7']);
    $flat = preg_replace('/[ \t]+/', ' ', $output) ?? $output;

    expect($exit)->toBe(0)
        ->and($output)->toContain('Metrics operation completed for node #7.')
        ->and($flat)->toContain('Status active')
        ->and($flat)->toContain('Request ID 66666666-6666-4666-8666-666666666666')
        ->and($output)->not->toContain('Publication');

    $mock->assertSentCount(1);
    expect($mock->getLastRequest()?->body()->all())->toBe(['node_id' => 7]);
});

it('refuses non-interactive disable without --force before reading status', function (): void {
    $mock = MockClient::global();

    $exitCode = Artisan::call('metrics:disable', ['--json' => true]);

    expect($exitCode)
        ->toBe(1)
        ->and(json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR))
        ->toBe([
            'error' => [
                'code' => 'metrics.force_required',
                'message' => 'Non-interactive Metrics disable requires --force.',
                'request_id' => null,
            ],
        ]);

    $mock->assertNothingSent();
    $mock->assertNotSent(ShowMetricsStatusRequest::class);
    $mock->assertNotSent(DisableMetricsRequest::class);
});

it('requires --force before --purge-data without reading status', function (): void {
    $mock = MockClient::global();

    $exitCode = Artisan::call('metrics:disable', ['--purge-data' => true, '--json' => true]);

    expect($exitCode)
        ->toBe(1)
        ->and(json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR))
        ->toBe([
            'error' => [
                'code' => 'metrics.force_required',
                'message' => '--purge-data requires --force.',
                'request_id' => null,
            ],
        ]);

    expect($mock->getLastPendingRequest())->toBeNull();
});

it('sends the force and purge disable payload', function (): void {
    $mock = MockClient::global([
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

    $mock->assertNotSent(ShowMetricsStatusRequest::class);
    expect($mock->getLastRequest()?->body()->all())->toBe(['force' => true, 'purge_data' => true]);
});

it('returns field=node when non-interactive enable omits the node', function (): void {
    $mock = MockClient::global();

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

    expect($mock->getLastPendingRequest())->toBeNull();
});

it('treats an empty node argument like a missing node', function (): void {
    $mock = MockClient::global();

    $this
        ->artisan('metrics:enable', ['node' => '', '--json' => true])
        ->expectsOutput(json_encode([
            'error' => [
                'code' => 'metrics.node_required',
                'message' => 'Node ID or name is required.',
                'details' => ['field' => 'node'],
                'request_id' => null,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->assertExitCode(SymfonyCommand::FAILURE);

    expect($mock->getLastPendingRequest())->toBeNull();
});

it('excludes an inactive node from the enable data list', function (): void {
    $requestId = metrics_cli_request_id();
    $nodes = new NodesResponse([
        NodeResponse::fromGatewayData(metrics_cli_node_payload(3, 'app-dev', 'active', ['app-dev']), $requestId),
        NodeResponse::fromGatewayData(metrics_cli_node_payload(4, 'stale-node', 'inactive', ['app-dev']), $requestId),
    ], $requestId);

    $method = new ReflectionMethod(EnableMetricsCommand::class, 'eligibleNodeRows');
    $rows = $method->invoke(null, $nodes);

    expect($rows)->toHaveKey(3)
        ->and($rows)->not->toHaveKey(4);
});

it('warns when a Metrics disable leaves the Gateway publication uncleaned', function (): void {
    $mock = MockClient::global([
        DisableMetricsRequest::class => MockResponse::make([
            'data' => ['node_id' => 7, 'status' => 'removed', 'publication' => 'uncleaned'],
            'meta' => ['request_id' => metrics_cli_request_id()],
        ]),
    ]);

    [$exit, $output] = metrics_cli_display('metrics:disable', ['--force' => true]);
    $flat = preg_replace('/[ \t]+/', ' ', $output) ?? $output;

    expect($exit)->toBe(0)
        ->and($output)->toContain('Disabled Metrics; publication not cleaned.')
        ->and($output)->toContain('Metrics operation completed for node #7.')
        ->and($flat)->toContain('Publication uncleaned')
        ->and($output)->toContain(
            'Publication not cleaned: no single active Gateway. The metrics.orbit route, certificate, and DNS record remain on the Gateway.',
        )
        ->and($flat)->toContain('Request ID '.metrics_cli_request_id());

    $mock->assertNotSent(ShowMetricsStatusRequest::class);
});

it('renders no uncleaned warning for a cleaned Metrics disable', function (): void {
    MockClient::global([
        DisableMetricsRequest::class => MockResponse::make([
            'data' => ['node_id' => 7, 'status' => 'removed', 'publication' => 'cleaned'],
            'meta' => ['request_id' => metrics_cli_request_id()],
        ]),
    ]);

    [$exit, $output] = metrics_cli_display('metrics:disable', ['--force' => true]);
    $flat = preg_replace('/[ \t]+/', ' ', $output) ?? $output;

    expect($exit)->toBe(0)
        ->and($output)->toContain('Disabled Metrics.')
        ->and($flat)->toContain('Publication cleaned')
        ->and($output)->not->toContain('Publication not cleaned')
        ->and($output)->not->toContain('publication not cleaned');
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
        ListNodesRequest::class => MockResponse::make([
            'data' => [
                metrics_cli_node_payload(id: 1, name: 'gateway', status: 'active', roles: ['gateway', 'vpn']),
                metrics_cli_node_payload(id: 2, name: 'app-dev', status: 'active', roles: ['app-dev']),
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
        ListNodesRequest::class => MockResponse::make([
            'data' => [metrics_cli_node_payload(id: 2, name: 'app-dev', status: 'active', roles: ['app-dev'])],
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
            'data' => [metrics_cli_node_payload(id: 3, name: 'app-prod', status: 'active', roles: ['app-prod'])],
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

    [$exit, $output] = metrics_cli_display('metrics:status');
    $flat = preg_replace('/[ \t]+/', ' ', $output) ?? $output;

    expect($exit)->toBe(0)
        ->and($flat)->toContain('Enabled yes')
        ->and($flat)->toContain('URL https://metrics.orbit')
        ->and($flat)->toContain('Assignment ID 2')
        ->and($flat)->toContain('Assignment status active')
        ->and($flat)->toContain('Assignment node ID 3')
        ->and($flat)->toContain('Assignment node app-dev')
        ->and($flat)->toContain('Prometheus healthy')
        ->and($flat)->toContain('Grafana healthy')
        ->and($output)->toContain('DESIRED')
        ->and($output)->toContain('DEGRADED')
        ->and($flat)->toContain('│ 7 │ orbit-ops │ yes │ active │ explicit_enabled │ — │')
        ->and($flat)->toContain('│ 9 │ unreachable-node │ yes │ unknown │ role_default │ unreachable │')
        ->and($flat)->toContain('Request ID: '.metrics_cli_request_id());
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

    [$exit, $output] = metrics_cli_display('metrics:status');
    $flat = preg_replace('/[ \t]+/', ' ', $output) ?? $output;

    expect($exit)->toBe(0)
        ->and($flat)->toContain('Enabled yes')
        ->and($flat)->toContain('Assignment status failed')
        ->and($flat)->toContain('Failed step metrics:runtime')
        ->and($flat)->toContain('Error code metrics.runtime_failed')
        ->and($flat)->toContain('Prometheus unknown')
        ->and($flat)->toContain('Grafana unknown')
        ->and($output)->toContain('No Metrics exporters configured.')
        ->and($flat)->toContain('Request ID: '.metrics_cli_request_id());
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
    'enable' => ['metrics:enable', ['node' => '3'], EnableMetricsRequest::class],
    'disable' => ['metrics:disable', ['--force' => true], DisableMetricsRequest::class],
    'status' => ['metrics:status', [], ShowMetricsStatusRequest::class],
    'credentials' => ['metrics:credentials', [], ShowMetricsCredentialsRequest::class],
    'credential reset' => ['metrics:credentials', ['--reset' => true], ResetMetricsCredentialsRequest::class],
    'exporter enable' => ['metrics:exporter:enable', ['node' => '7'], EnableMetricsExporterRequest::class],
    'exporter disable' => ['metrics:exporter:disable', ['node' => '7'], DisableMetricsExporterRequest::class],
]);

/**
 * @param  array{id:int,node_id:int,node_name:string,status:string,failed_step:?string,error_code:?string}|null  $assignment
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

/**
 * The Gateway's refusal of a second Metrics enable while an assignment exists.
 */
function metrics_cli_role_conflict_response(): MockResponse
{
    return MockResponse::make(
        [
            'error' => [
                'code' => 'node.role_conflict',
                'message' => 'The metrics role is already assigned; remove it before enabling it on another node.',
                'details' => [],
            ],
        ],
        409,
        ['X-Orbit-Request-Id' => metrics_cli_request_id()],
    );
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
        'wireguard_ip' => "10.44.0.{$id}",
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

/**
 * @param  array<string, mixed>  $arguments
 * @return array{0: int, 1: string}
 */
function metrics_cli_display(string $command, array $arguments = []): array
{
    $tester = new CommandTester(app(Kernel::class)->all()[$command]);

    return [$tester->execute($arguments, ['interactive' => false]), $tester->getDisplay(true)];
}
