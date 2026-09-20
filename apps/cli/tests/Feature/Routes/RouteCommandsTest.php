<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Support\Console\TerminalText;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\Processes\ListProcessesRequest;
use Orbit\Sdk\Requests\Routes\CreateRouteRequest;
use Orbit\Sdk\Requests\Routes\DestroyRouteRequest;
use Orbit\Sdk\Requests\Routes\ListRoutesRequest;
use Orbit\Sdk\Requests\Routes\SetRouteTargetRequest;
use Orbit\Sdk\Requests\Routes\ShowRouteRequest;
use Orbit\Sdk\Requests\Routes\UnsetRouteTargetRequest;
use Orbit\Sdk\Requests\Routes\UpdateRouteRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-route-'.Str::uuid();
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

it('creates target and targetless Routes while transporting policy values', function (): void {
    $mock = MockClient::global([CreateRouteRequest::class => route_mock_response(201)]);

    $this->artisan('route:create', [
        'app' => '3',
        'domain' => 'Odd_Value',
        '--publication' => 'future-policy',
        '--target' => '7',
        '--json' => true,
    ])->assertExitCode(0);

    expect($mock->getLastRequest()?->body()->all())->toBe([
        'app_id' => 3,
        'domain' => 'Odd_Value',
        'publication' => 'future-policy',
        'app_instance_id' => 7,
    ]);

    $this->artisan('route:create', [
        'app' => '3',
        'domain' => 'node.test',
        '--node' => '4',
    ])->assertExitCode(0);

    expect($mock->getLastRequest()?->body()->all())->toHaveKey('node_id', 4);
});

it('creates a custom proxy Route from a node name and upstream', function (): void {
    $mock = MockClient::global([
        ListNodesRequest::class => MockResponse::make([
            'data' => [route_cli_node_payload()],
            'meta' => ['request_id' => route_request_id()],
        ]),
        CreateRouteRequest::class => MockResponse::make([
            'data' => custom_proxy_route_payload(),
            'meta' => ['request_id' => route_request_id()],
        ], 201),
    ]);

    $this->artisan('route:create', [
        'app' => 'executor.orbit',
        '--node' => 'beast',
        '--upstream' => 'http://127.0.0.1:4788',
        '--json' => true,
    ])->assertExitCode(0);

    expect($mock->getLastRequest()?->body()->all())->toBe([
        'domain' => 'executor.orbit',
        'publication' => 'private',
        'node_id' => 4,
        'upstream' => 'http://127.0.0.1:4788',
    ]);
});

it('creates a custom proxy Route from a numeric node and Process name', function (): void {
    $mock = MockClient::global([
        ListProcessesRequest::class => MockResponse::make([
            'data' => [route_cli_process_payload()],
            'meta' => ['request_id' => route_request_id()],
        ]),
        CreateRouteRequest::class => MockResponse::make([
            'data' => [...custom_proxy_route_payload(), 'process_id' => 12, 'upstream' => 'http://127.0.0.1:4788'],
            'meta' => ['request_id' => route_request_id()],
        ], 201),
    ]);

    $this->artisan('route:create', [
        'app' => 'executor.orbit',
        '--node' => '4',
        '--process' => 'executor',
        '--json' => true,
    ])->assertExitCode(0);

    expect($mock->getLastRequest()?->body()->all())->toBe([
        'domain' => 'executor.orbit',
        'publication' => 'private',
        'node_id' => 4,
        'process_id' => 12,
    ]);
});

it('resolves a numeric Process ID without listing Processes', function (): void {
    $mock = MockClient::global([
        CreateRouteRequest::class => MockResponse::make([
            'data' => [...custom_proxy_route_payload(), 'process_id' => 12],
            'meta' => ['request_id' => route_request_id()],
        ], 201),
    ]);

    $this->artisan('route:create', [
        'app' => 'executor.orbit',
        '--node' => '4',
        '--process' => '12',
    ])->assertExitCode(0);

    expect($mock->getLastRequest())
        ->toBeInstanceOf(CreateRouteRequest::class)
        ->and($mock->getLastRequest()?->body()->all())
        ->toBe([
            'domain' => 'executor.orbit',
            'publication' => 'private',
            'node_id' => 4,
            'process_id' => 12,
        ]);
});

it('rejects custom proxy create shapes before transport', function (array $arguments, string $code): void {
    $mock = MockClient::global();

    $this
        ->artisan('route:create', $arguments)
        ->expectsOutputToContain($code)
        ->assertExitCode(1);

    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    'second positional' => [
        [
            'app' => 'executor.orbit',
            'domain' => 'other.orbit',
            '--node' => 'beast',
            '--upstream' => 'http://127.0.0.1:4788',
            '--json' => true,
        ],
        'route.scope_required',
    ],
    'missing node' => [
        [
            'app' => 'executor.orbit',
            '--upstream' => 'http://127.0.0.1:4788',
            '--json' => true,
        ],
        'route.scope_required',
    ],
    'target mix' => [
        [
            'app' => 'executor.orbit',
            '--node' => '4',
            '--upstream' => 'http://127.0.0.1:4788',
            '--target' => '7',
            '--json' => true,
        ],
        'route.scope_required',
    ],
    'cluster mix' => [
        [
            'app' => 'executor.orbit',
            '--node' => '4',
            '--upstream' => 'http://127.0.0.1:4788',
            '--cluster' => '5',
            '--json' => true,
        ],
        'route.scope_required',
    ],
    'public publication' => [
        [
            'app' => 'executor.orbit',
            '--node' => '4',
            '--upstream' => 'http://127.0.0.1:4788',
            '--publication' => 'public',
            '--json' => true,
        ],
        'route.publication_invalid',
    ],
    'both selectors' => [
        [
            'app' => 'executor.orbit',
            '--node' => '4',
            '--upstream' => 'http://127.0.0.1:4788',
            '--process' => 'executor',
            '--json' => true,
        ],
        'route.upstream_invalid',
    ],
]);

it('renders custom proxy kind and upstream instead of App targets', function (): void {
    $original = getenv('COLUMNS');
    putenv('COLUMNS=200');
    $payload = custom_proxy_route_payload();
    MockClient::global([
        ListRoutesRequest::class => MockResponse::make([
            'data' => [$payload],
            'meta' => ['request_id' => route_request_id()],
        ]),
        ShowRouteRequest::class => MockResponse::make([
            'data' => $payload,
            'meta' => ['request_id' => route_request_id()],
        ]),
    ]);

    try {
        expect(Artisan::call('route:list'))->toBe(0);
        expect(Artisan::output())
            ->toContain('KIND', 'custom_proxy', 'http://127.0.0.1:4788')
            ->not->toContain('712');

        expect(Artisan::call('route:show', ['route' => '11']))->toBe(0);
        expect(Artisan::output())
            ->toContain('Kind', 'custom_proxy', 'Upstream', 'http://127.0.0.1:4788')
            ->not->toContain('Targets');
    } finally {
        putenv($original === false ? 'COLUMNS' : 'COLUMNS='.$original);
    }
});

it('transports explicit private and public publication intents unchanged', function (string $publication): void {
    $mock = MockClient::global([
        CreateRouteRequest::class => route_mock_response(201),
        UpdateRouteRequest::class => route_mock_response(),
    ]);

    $this->artisan('route:create', [
        'app' => '3',
        'domain' => 'app.test',
        '--publication' => $publication,
        '--cluster' => '5',
        '--json' => true,
    ])->assertExitCode(0);

    expect($mock->getLastRequest()?->body()->all())->toBe([
        'app_id' => 3,
        'domain' => 'app.test',
        'publication' => $publication,
        'cluster_id' => 5,
    ]);

    $this->artisan('route:update', ['route' => '11', '--publication' => $publication])->assertExitCode(0);

    expect($mock->getLastRequest()?->body()->all())->toBe(['publication' => $publication]);
})->with(['private', 'public']);

it('refuses a missing publication value before transport', function (
    string $command,
    array $arguments,
): void {
    $mock = MockClient::global();

    $exitCode = Artisan::call($command, [...$arguments, '--json' => true]);
    $output = trim(Artisan::output());

    expect($exitCode)->toBe(1);
    expect(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe([
        'error' => [
            'code' => 'route.publication_invalid',
            'message' => 'Publication intent must be a non-empty value.',
            'request_id' => null,
        ],
    ]);
    expect($mock->getLastPendingRequest())->toBeNull();

    $this
        ->artisan($command, $arguments)
        ->expectsOutputToContain('Publication intent must be a non-empty value.')
        ->doesntExpectOutputToContain('route.publication_invalid')
        ->assertExitCode(1);
    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    'create without value' => ['route:create', ['app' => '1', 'domain' => 'pubtest.orbit', '--publication' => null, '--cluster' => '1']],
    'create with empty value' => ['route:create', ['app' => '1', 'domain' => 'pubtest.orbit', '--publication' => '', '--cluster' => '1']],
    'update without value' => ['route:update', ['route' => '11', '--publication' => null]],
    'update with empty value' => ['route:update', ['route' => '11', '--publication' => '']],
    'update with domain and no publication value' => ['route:update', ['route' => '11', '--domain' => 'next.test', '--publication' => null]],
]);

it('refuses the reported shell shape of a bare --publication flag', function (string $command): void {
    $mock = MockClient::global();

    $exitCode = Artisan::call($command);
    $output = trim(Artisan::output());

    expect($exitCode)->toBe(1);
    expect(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR)['error']['code'] ?? null)
        ->toBe('route.publication_invalid');
    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    'route:create' => 'route:create 1 pubtest.orbit --publication --cluster=1 --json',
    'route:update' => 'route:update 11 --publication --json',
]);

it('rejects impossible create shapes before transport', function (array $arguments, string $code): void {
    $mock = MockClient::global();

    $this
        ->artisan('route:create', $arguments)
        ->expectsOutputToContain($code)
        ->assertExitCode(1);

    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    'missing scope' => [
        [
            'app' => '3',
            'domain' => 'app.test',
            '--json' => true,
        ],
        'route.scope_required',
    ],
    'both scopes' => [
        [
            'app' => '3',
            'domain' => 'app.test',
            '--node' => '4',
            '--cluster' => '5',
            '--json' => true,
        ],
        'route.scope_required',
    ],
    'target and scope' => [
        [
            'app' => '3',
            'domain' => 'app.test',
            '--target' => '7',
            '--node' => '4',
            '--json' => true,
        ],
        'route.scope_conflict',
    ],
    'invalid target' => [
        [
            'app' => '3',
            'domain' => 'app.test',
            '--target' => 'many',
            '--json' => true,
        ],
        'route.id_invalid',
    ],
    'publication without value' => [
        [
            'app' => '3',
            'domain' => 'app.test',
            '--node' => '4',
            '--publication' => null,
            '--json' => true,
        ],
        'route.publication_invalid',
    ],
    'missing domain' => [
        [
            'app' => '3',
            'domain' => '',
            '--node' => '4',
            '--json' => true,
        ],
        'route.domain_required',
    ],
]);

it('renders only the first invalid input as one JSON document', function (
    string $command,
    array $arguments,
    string $code,
    string $message,
): void {
    $mock = MockClient::global();

    $exitCode = Artisan::call($command, [...$arguments, '--json' => true]);
    $output = trim(Artisan::output());

    expect($exitCode)->toBe(1);
    expect(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe([
        'error' => [
            'code' => $code,
            'message' => $message,
            'request_id' => null,
        ],
    ]);
    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    'create Route' => [
        'route:create',
        [
            'app' => 'invalid',
            'domain' => '',
            '--publication' => null,
            '--target' => 'invalid',
            '--node' => 'invalid',
            '--cluster' => 'invalid',
        ],
        'app.id_invalid',
        'App ID must be a positive integer.',
    ],
    'set Route target' => [
        'route:target:set',
        ['route' => 'invalid', 'target' => 'invalid'],
        'route.id_invalid',
        'Route ID must be a positive integer.',
    ],
]);

it('transports a combined domain and publication update and renders publication', function (): void {
    $payload = [...route_payload(), 'publication' => 'public', 'domain' => 'final.example.test'];
    $mock = MockClient::global([
        UpdateRouteRequest::class => MockResponse::make([
            'data' => $payload,
            'meta' => ['request_id' => route_request_id()],
        ]),
        ShowRouteRequest::class => MockResponse::make([
            'data' => $payload,
            'meta' => ['request_id' => route_request_id()],
        ]),
    ]);

    $this->artisan('route:update', [
        'route' => '11',
        '--domain' => 'final.example.test',
        '--publication' => 'public',
        '--json' => true,
    ])->assertExitCode(0);

    expect($mock->getLastRequest()?->body()->all())->toBe([
        'domain' => 'final.example.test',
        'publication' => 'public',
    ]);

    expect(Artisan::call('route:show', ['route' => '11']))->toBe(0);
    expect(Artisan::output())->toContain('Publication')
        ->toContain('public')
        ->not->toContain('Public publication');
});

it('lists, shows, updates, targets, clears, and removes through exact requests', function (): void {
    $mock = MockClient::global([
        ListRoutesRequest::class => MockResponse::make([
            'data' => [route_payload()],
            'meta' => ['request_id' => route_request_id()],
        ]),
        ShowRouteRequest::class => route_mock_response(),
        UpdateRouteRequest::class => route_mock_response(),
        SetRouteTargetRequest::class => route_mock_response(),
        UnsetRouteTargetRequest::class => route_mock_response(),
        DestroyRouteRequest::class => route_mock_response(),
    ]);

    $this->artisan('route:list', ['--json' => true])->assertExitCode(0);
    $this->artisan('route:show', ['route' => '11'])->assertExitCode(0);
    $this->artisan('route:update', ['route' => '11', '--domain' => 'next.test'])->assertExitCode(0);
    expect($mock->getLastRequest()?->body()->all())->toBe(['domain' => 'next.test']);
    $this->artisan('route:target:set', ['route' => '11', 'target' => '8'])->assertExitCode(0);
    expect($mock->getLastRequest()?->body()->all())->toBe(['app_instance_id' => 8]);
    $this->artisan('route:target:set', [
        'route' => '11',
        'target' => '8',
        '--targets' => ['9'],
        '--reassign' => ['10:4'],
        '--remove' => ['11'],
    ])->assertExitCode(0);
    expect($mock->getLastRequest()?->body()->all())->toBe([
        'targets' => [8, 9],
        'dispositions' => [
            ['app_instance_id' => 10, 'route_id' => 4],
            ['app_instance_id' => 11, 'remove' => true],
        ],
    ]);
    $this->artisan('route:target:unset', ['route' => '11', '--yes' => true])->assertExitCode(0);
    $this->artisan('route:destroy', ['route' => '11', '--yes' => true])->assertExitCode(0);
});

it('rejects the removed hostname argument and option', function (string $command, array $arguments, string $message): void {
    $mock = MockClient::global();
    $tester = new CommandTester(app(Kernel::class)->all()[$command]);

    expect($tester->execute($arguments, ['interactive' => false]))->toBe(1);
    expect(json_decode(trim($tester->getDisplay()), associative: true, flags: JSON_THROW_ON_ERROR))->toBe([
        'error' => [
            'code' => 'input.invalid',
            'message' => $message,
            'request_id' => null,
        ],
    ]);
    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    'create hostname argument' => [
        'route:create',
        ['app' => '3', 'hostname' => 'app.test', '--node' => '4', '--json' => true],
        'The "hostname" argument does not exist.',
    ],
    'update hostname option' => [
        'route:update',
        ['route' => '11', '--hostname' => 'next.test', '--json' => true],
        'The "--hostname" option does not exist.',
    ],
]);

it('rejects an empty update and invalid IDs before transport', function (): void {
    $mock = MockClient::global();
    $this
        ->artisan('route:update', ['route' => '11', '--json' => true])
        ->expectsOutputToContain('route.update_required')
        ->assertExitCode(1);
    $this
        ->artisan('route:show', ['route' => 'zero', '--json' => true])
        ->expectsOutputToContain('route.id_invalid')
        ->assertExitCode(1);
    expect($mock->getLastPendingRequest())->toBeNull();
});

function route_mock_response(int $status = 200): MockResponse
{
    return MockResponse::make([
        'data' => route_payload(),
        'meta' => ['request_id' => route_request_id()],
    ], $status);
}

/** @return array<string, mixed> */
function route_payload(): array
{
    return [
        'id' => 11,
        'kind' => 'app',
        'app_id' => 3,
        'node_id' => 4,
        'cluster_id' => null,
        'generation_basis_node_id' => null,
        'domain' => 'app.test',
        'provenance' => 'explicit',
        'publication' => 'private',
        'status' => 'pending',
        'failed_step' => null,
        'error_code' => null,
        'replaces_route_id' => null,
        'replaced_by_route_id' => null,
        'replacement_step' => null,
        'target_set_step' => null,
        'target' => ['id' => 12, 'app_instance_id' => 7, 'position' => 0],
        'targets' => [['id' => 12, 'app_instance_id' => 7, 'position' => 0]],
        'process_id' => null,
        'upstream' => null,
    ];
}

function route_request_id(): string
{
    return '0198e15d-16c4-7855-8eb2-182b53ad28ba';
}

/** @return array<string, mixed> */
function custom_proxy_route_payload(): array
{
    return [
        ...route_payload(),
        'kind' => 'custom_proxy',
        'app_id' => null,
        'target' => null,
        'targets' => [],
        'process_id' => null,
        'upstream' => 'http://127.0.0.1:4788',
        'domain' => 'executor.orbit',
    ];
}

/** @return array<string, mixed> */
function route_cli_node_payload(): array
{
    return [
        'id' => 4,
        'name' => 'beast',
        'status' => 'active',
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'tld' => null,
        'public_ssh_host' => '203.0.113.7',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.7',
        'wireguard_public_key' => 'key',
        'wireguard_endpoint_override' => null,
        'dns_server_override' => null,
        'ssh_host_fingerprint' => null,
        'failed_step' => null,
        'error_code' => null,
        'roles' => [],
    ];
}

/** @return array<string, mixed> */
function route_cli_process_payload(): array
{
    return [
        'id' => 12,
        'target_type' => 'node',
        'target_id' => 4,
        'name' => 'executor',
        'runtime' => 'docker',
        'working_directory' => '/app',
        'runtime_config' => [
            'image' => 'executor:latest',
            'command' => ['executor'],
            'environment' => [],
            'ports' => ['127.0.0.1:4788:80/tcp'],
            'volumes' => [],
        ],
        'restart_policy' => 'unless-stopped',
        'keep_alive' => false,
        'desired_state' => 'running',
        'status' => 'active',
        'runtime_status' => 'running',
        'failed_step' => null,
        'error_code' => null,
    ];
}

it('resolves destructive subjects but refuses automation without independent consent', function (
    string $command,
    string $running,
    bool $json,
): void {
    $mock = MockClient::global([ShowRouteRequest::class => route_mock_response()]);
    $arguments = ['route' => '3', '--no-interaction' => true];

    if ($json) {
        $arguments['--json'] = true;
    }

    expect(Artisan::call($command, $arguments))->toBe(1);
    $output = Artisan::output();
    expect($output)->toContain('Supply --yes to confirm this operation.')
        ->not->toContain($running, "\e[");
    expect($mock->getLastRequest())->toBeInstanceOf(ShowRouteRequest::class)
        ->and($mock->getRecordedResponses())->toHaveCount(1);

    if ($json) {
        expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toBe([
            'error' => [
                'code' => 'input.confirmation_required',
                'message' => 'Supply --yes to confirm this operation.',
                'request_id' => null,
            ],
        ]);
    }
})->with([
    'route:destroy' => ['route:destroy', 'Removing Route'],
    'route:target:unset' => ['route:target:unset', 'Clearing Route target'],
])->with([false, true]);

it('preserves lookup failures before destructive consent without sending a mutation', function (
    string $command,
    string $running,
    bool $json,
): void {
    $mock = MockClient::global([
        ShowRouteRequest::class => MockResponse::make([
            'error' => ['code' => 'http.404', 'message' => 'Resource not found.', 'details' => []],
        ], 404),
    ]);
    $arguments = ['route' => '3', '--no-interaction' => true];

    if ($json) {
        $arguments['--json'] = true;
    }

    expect(Artisan::call($command, $arguments))->toBe(1);
    $output = Artisan::output();
    expect($output)->toContain('Resource not found.')
        ->not->toContain('Supply --yes', $running, "\e[");
    expect($mock->getLastRequest())->toBeInstanceOf(ShowRouteRequest::class)
        ->and($mock->getRecordedResponses())->toHaveCount(1);

    if ($json) {
        expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toBe([
            'error' => ['code' => 'http.404', 'message' => 'Resource not found.', 'request_id' => null],
        ]);
    }
})->with([
    'route:destroy' => ['route:destroy', 'Removing Route'],
    'route:target:unset' => ['route:target:unset', 'Clearing Route target'],
])->with([false, true]);

it('renders an explicit empty list and preserves the empty machine collection', function (bool $json): void {
    MockClient::global([
        ListRoutesRequest::class => MockResponse::make(['data' => [], 'meta' => ['request_id' => route_request_id()]]),
    ]);

    expect(Artisan::call('route:list', ['--json' => $json]))->toBe(0);
    $output = Artisan::output();
    expect($output)->not->toContain("\e[");

    if ($json) {
        expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR))->toBe([
            'routes' => [], 'request_id' => route_request_id(),
        ]);
    } else {
        expect($output)->toContain('No Routes found.', route_request_id())->not->toContain('Operation failed.');
    }
})->with([false, true]);

it('keeps every ordered target in human list and detail output at narrow widths', function (int $columns): void {
    $original = getenv('COLUMNS');
    putenv('COLUMNS='.$columns);
    $payload = [...route_payload(), 'targets' => [
        ['id' => 12, 'app_instance_id' => 712, 'position' => 0],
        ['id' => 13, 'app_instance_id' => 934, 'position' => 1],
        ['id' => 14, 'app_instance_id' => 856, 'position' => 2],
    ]];
    MockClient::global([
        ShowRouteRequest::class => MockResponse::make(['data' => $payload, 'meta' => ['request_id' => route_request_id()]]),
        ListRoutesRequest::class => MockResponse::make(['data' => [$payload], 'meta' => ['request_id' => route_request_id()]]),
    ]);

    try {
        foreach (['route:list' => [], 'route:show' => ['route' => '11']] as $command => $arguments) {
            expect(Artisan::call($command, $arguments))->toBe(0);
            $output = Artisan::output();
            expect($output)->toContain('712', '934', '856')->not->toContain("\e[");
            expect(strpos($output, '712'))->toBeLessThan(strpos($output, '934'));
            expect(strpos($output, '934'))->toBeLessThan(strpos($output, '856'));

            foreach (explode("\n", $output) as $line) {
                expect(TerminalText::width($line))->toBeLessThanOrEqual($columns);
            }
        }
    } finally {
        putenv($original === false ? 'COLUMNS' : 'COLUMNS='.$original);
    }
})->with([24, 80, 160]);

it('renders Route replacement and failed-step metadata without claiming publication success', function (): void {
    $payload = [...route_payload(),
        'generation_basis_node_id' => 42,
        'replaces_route_id' => 45,
        'replaced_by_route_id' => 46,
        'replacement_step' => 'publish_replacement',
        'target_set_step' => 'apply_targets',
        'failed_step' => 'configure_ingress',
        'error_code' => 'route.ingress_failed',
        'status' => 'failed',
    ];
    MockClient::global([
        ShowRouteRequest::class => MockResponse::make(['data' => $payload, 'meta' => ['request_id' => route_request_id()]]),
    ]);

    expect(Artisan::call('route:show', ['route' => '11']))->toBe(0);
    expect(Artisan::output())->toContain(
        'Generation basis Node', '42', 'Replaces Route', '45', 'Replaced by Route', '46',
        'Replacement step', 'publish_replacement', 'Target set step', 'apply_targets',
        'Failed step', 'configure_ingress', 'route.ingress_failed', 'failed',
    )->not->toContain('Published Route');
});
