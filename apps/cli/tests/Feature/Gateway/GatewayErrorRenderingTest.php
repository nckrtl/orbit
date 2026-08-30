<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Activities\ListActivitiesRequest;
use Orbit\Sdk\Requests\Tools\InstallToolRequest;
use Orbit\Sdk\Requests\Tools\ListToolManagersRequest;
use Orbit\Sdk\Requests\Tools\ListToolsRequest;
use Orbit\Sdk\Requests\Tools\RemoveToolRequest;
use Orbit\Sdk\Requests\Tools\ShowToolRequest;
use Orbit\Sdk\Requests\Tools\UpdateToolRequest;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\InvalidGatewayDtoCommand;
use Tests\Support\InvalidGatewayDtoRequest;
use Tests\Support\UnexpectedGatewayDtoCommand;
use Tests\Support\UnexpectedGatewayDtoRequest;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-gateway-error-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
    ));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

it('renders one deterministic json envelope for a resource gateway error', function (): void {
    MockClient::global([
        ListActivitiesRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'gateway.unavailable',
                    'message' => 'Gateway is unavailable.',
                    'details' => ['trace_id' => 'fixture-secret'],
                ],
            ],
            503,
            ['X-Orbit-Request-Id' => gateway_error_request_id()],
        ),
    ]);
    $expectedPayload = [
        'error' => [
            'code' => 'gateway.unavailable',
            'message' => 'Gateway is unavailable.',
            'request_id' => gateway_error_request_id(),
        ],
    ];
    $expected = json_encode($expectedPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $exitCode = Artisan::call('activity:list', ['--json' => true]);
    $output = trim(Artisan::output());

    expect($exitCode)->toBe(1);
    expect($output)
        ->toBe($expected)
        ->not->toContain('details')
        ->not->toContain('fixture-secret');
    expect(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe($expectedPayload);
});

it('renders shared gateway errors safely for every tool command', function (
    string $command,
    array $arguments,
    string $requestClass,
): void {
    MockClient::global([
        $requestClass => MockResponse::make(
            [
                'error' => [
                    'code' => 'tool.manager_unavailable',
                    'message' => 'The tool manager is unavailable.',
                    'details' => ['manager_output' => 'validation-secret'],
                ],
            ],
            409,
            ['X-Orbit-Request-Id' => gateway_error_request_id()],
        ),
    ]);
    $expectedPayload = [
        'error' => [
            'code' => 'tool.manager_unavailable',
            'message' => 'The tool manager is unavailable.',
            'request_id' => gateway_error_request_id(),
        ],
    ];

    $exitCode = Artisan::call($command, [...$arguments, '--json' => true]);
    $output = trim(Artisan::output());

    expect($exitCode)->toBe(SymfonyCommand::FAILURE);
    expect($output)
        ->toBe(json_encode($expectedPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->not->toContain('details')
        ->not->toContain('validation-secret');
    expect(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
        ->toBe($expectedPayload);
})->with([
    'manager list' => ['tool:manager:list', ['--node' => '12'], ListToolManagersRequest::class],
    'tool list' => ['tool:list', ['--node' => '12'], ListToolsRequest::class],
    'tool show' => ['tool:show', ['tool' => '41'], ShowToolRequest::class],
    'tool install' => [
        'tool:install',
        ['package' => '@openai/codex', '--node' => '12', '--manager' => 'vp'],
        InstallToolRequest::class,
    ],
    'tool update' => ['tool:update', ['tool' => '41'], UpdateToolRequest::class],
    'tool remove' => ['tool:remove', ['tool' => '41'], RemoveToolRequest::class],
]);

it('renders malformed successful tool responses through the shared json boundary', function (): void {
    MockClient::global([
        ShowToolRequest::class => MockResponse::make([
            'data' => ['id' => 'validation-secret'],
            'meta' => ['request_id' => gateway_error_request_id()],
        ]),
    ]);
    $expected = json_encode(['error' => [
        'code' => 'gateway.invalid_response',
        'message' => 'Gateway response is invalid.',
        'request_id' => gateway_error_request_id(),
    ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    expect(Artisan::call('tool:show', ['tool' => '41', '--json' => true]))
        ->toBe(SymfonyCommand::FAILURE)
        ->and(trim(Artisan::output()))
        ->toBe($expected)
        ->not->toContain('validation-secret');
});

it('renders a bounded json envelope for a fatal transport error', function (): void {
    MockClient::global([
        ListActivitiesRequest::class => static function (PendingRequest $pendingRequest): never {
            throw new FatalRequestException(
                new RuntimeException('transport failed with token=transport-secret'),
                $pendingRequest,
            );
        },
    ]);
    $expectedPayload = [
        'error' => [
            'code' => 'gateway.unreachable',
            'message' => 'Could not reach the gateway.',
            'request_id' => null,
        ],
    ];
    $expected = json_encode($expectedPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $exitCode = Artisan::call('activity:list', ['--json' => true]);
    $output = trim(Artisan::output());

    expect($exitCode)->toBe(1);
    expect($output)
        ->toBe($expected)
        ->not->toContain('transport-secret');
    expect(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe($expectedPayload);
});

it('renders a safe json failure for a corrupted persisted gateway profile', function (): void {
    $filesystem = new Filesystem;
    $filesystem->put(
        $this->orbitHome.'/config.json',
        json_encode([
            'active_gateway' => 'test',
            'gateways' => [
                'test' => [
                    'url' => 'https://user:persisted-secret@10.70.0.1',
                    'ca_path' => null,
                ],
            ],
        ], JSON_THROW_ON_ERROR),
    );
    $filesystem->chmod($this->orbitHome.'/config.json', 0o600);
    $mock = MockClient::global();
    $expectedPayload = [
        'error' => [
            'code' => 'gateway.config_invalid',
            'message' => 'Orbit gateway configuration is invalid.',
            'request_id' => null,
        ],
    ];

    $exitCode = Artisan::call('gateway:status', ['--json' => true]);
    $output = trim(Artisan::output());

    expect($exitCode)->toBe(1);
    expect(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
        ->toBe($expectedPayload);
    expect($output)
        ->toBe(json_encode($expectedPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->not->toContain('persisted-secret')
        ->not->toContain('https://user:');
    expect($mock->getLastPendingRequest())->toBeNull();
});

it('replaces unsafe gateway error metadata in json output', function (): void {
    MockClient::global([
        ListActivitiesRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'API_TOKEN=metadata-secret',
                    'message' => 'API_TOKEN=message-secret',
                    'details' => ['previous' => 'exception-secret'],
                ],
            ],
            502,
            ['X-Orbit-Request-Id' => 'request-secret'],
        ),
    ]);
    $expected = json_encode([
        'error' => [
            'code' => 'gateway.request_failed',
            'message' => 'API_TOKEN=[REDACTED]',
            'request_id' => null,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $exitCode = Artisan::call('activity:list', ['--json' => true]);
    $output = trim(Artisan::output());

    expect($exitCode)->toBe(1);
    expect($output)
        ->toBe($expected)
        ->not->toContain('metadata-secret')
        ->not->toContain('message-secret')
        ->not->toContain('exception-secret')
        ->not->toContain('request-secret');
});

it('renders one bounded json envelope for an invalid gateway dto', function (): void {
    MockClient::global([
        InvalidGatewayDtoRequest::class => MockResponse::make(['data' => []]),
    ]);
    $command = app(InvalidGatewayDtoCommand::class);
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $expected = json_encode([
        'error' => [
            'code' => 'gateway.invalid_response',
            'message' => 'Gateway response is invalid.',
            'request_id' => null,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $exitCode = $tester->execute(['--json' => true], ['interactive' => false]);
    $output = trim($tester->getDisplay());

    expect($exitCode)->toBe(1);
    expect($output)
        ->toBe($expected)
        ->not->toContain('invalid-dto-secret');
    expect(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe([
        'error' => [
            'code' => 'gateway.invalid_response',
            'message' => 'Gateway response is invalid.',
            'request_id' => null,
        ],
    ]);
});

it('renders one bounded json envelope for an unexpected gateway dto type', function (): void {
    MockClient::global([
        UnexpectedGatewayDtoRequest::class => MockResponse::make(['data' => []]),
    ]);
    $command = app(UnexpectedGatewayDtoCommand::class);
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $expectedPayload = [
        'error' => [
            'code' => 'gateway.invalid_response',
            'message' => 'Gateway response is invalid.',
            'request_id' => null,
        ],
    ];

    $exitCode = $tester->execute(['--json' => true], ['interactive' => false]);
    $output = trim($tester->getDisplay());

    expect($exitCode)->toBe(1);
    expect($output)
        ->toBe(json_encode($expectedPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->not->toContain('unexpected-dto-secret');
    expect(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
        ->toBe($expectedPayload);
});

it('renders local validation failures through the exact json boundary', function (
    string $command,
    array $arguments,
    string $code,
    string $message,
): void {
    $mock = MockClient::global();
    $expectedPayload = [
        'error' => [
            'code' => $code,
            'message' => $message,
            'request_id' => null,
        ],
    ];
    $expected = json_encode($expectedPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $exitCode = Artisan::call($command, [...$arguments, '--json' => true]);
    $output = trim(Artisan::output());

    expect($exitCode)->toBe(1);
    expect($output)
        ->toBe($expected)
        ->not->toContain('validation-secret');
    expect(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
        ->toBe($expectedPayload);
    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    'activity limit' => [
        'activity:list',
        ['--limit' => '0'],
        'activity.limit_invalid',
        'Limit must be between 1 and 200.',
    ],
    'activity request ID' => [
        'activity:list',
        ['--request-id' => 'validation-secret'],
        'activity.request_id_invalid',
        'Request ID must be a UUID.',
    ],
    'positive ID helper' => [
        'activity:show',
        ['activity' => 'validation-secret'],
        'activity.id_invalid',
        'Activity ID must be a positive integer.',
    ],
    'string argument helper' => [
        'app:new',
        ['slug' => '', 'repository' => 'https://example.test/repository.git'],
        'app.slug_required',
        'App slug is required.',
    ],
    'app slug' => [
        'app:new',
        ['slug' => "validation\nsecret", 'repository' => 'https://example.test/repository.git'],
        'app.slug_invalid',
        'App slug is invalid.',
    ],
    'firewall node ID' => [
        'firewall:allow',
        ['name' => 'web', '--node' => '0', '--port' => '443'],
        'firewall.node_id_invalid',
        'Node ID must be a positive integer.',
    ],
    'firewall name' => [
        'firewall:allow',
        ['name' => 'validation-secret name', '--node' => '1', '--port' => '443'],
        'firewall.rule_name_invalid',
        'Firewall rule name is invalid.',
    ],
    'firewall protocol' => [
        'firewall:allow',
        ['name' => 'web', '--node' => '1', '--protocol' => 'validation-secret', '--port' => '443'],
        'firewall.protocol_invalid',
        'Firewall protocol must be tcp or udp.',
    ],
    'firewall source' => [
        'firewall:allow',
        ['name' => 'web', '--node' => '1', '--from' => 'validation-secret', '--port' => '443'],
        'firewall.source_invalid',
        'Firewall source must be any or a valid IPv4 or IPv6 address or CIDR.',
    ],
    'firewall port' => [
        'firewall:allow',
        ['name' => 'web', '--node' => '1', '--port' => 'validation-secret'],
        'firewall.port_invalid',
        'Firewall port must be from 1 to 65535 or an ordered range.',
    ],
    'instance runtime options' => [
        'instance:new',
        ['app' => '1', 'node' => '1', 'name' => 'web', '--document-root' => ''],
        'instance.options_required',
        'Document root and PHP version are required.',
    ],
    'PHP version helper' => [
        'instance:php',
        ['instance' => '1', 'version' => 'validation-secret'],
        'php.version_invalid',
        'PHP version must use major.minor format, for example 8.5.',
    ],
    'node arguments' => [
        'node:provision',
        ['name' => 'node', 'host' => 'node.test', '--ssh-port' => 'validation-secret'],
        'node.ssh_port_invalid',
        'SSH port must be an integer from 1 to 65535.',
    ],
    'node platform' => [
        'node:provision',
        ['name' => 'node', 'host' => 'node.test', '--platform' => 'validation-secret'],
        'node.platform_invalid',
        'Platform must be linux.',
    ],
    'node host key fingerprint' => [
        'node:provision',
        ['name' => 'node', 'host' => 'node.test', '--host-key-fingerprint' => 'validation-secret'],
        'node.host_key_fingerprint_invalid',
        'Host key fingerprint must use SSH SHA256 format: SHA256 followed by 43 base64 characters.',
    ],
    'node removal confirmation' => [
        'node:remove',
        ['node' => '1'],
        'node.confirmation_required',
        'Use --force to confirm node removal.',
    ],
    'process target selection' => [
        'process:add',
        ['name' => 'worker', '--command' => ['/usr/bin/php']],
        'process.target_invalid',
        'Select exactly one instance or workspace target.',
    ],
    'process target ID' => [
        'process:add',
        ['name' => 'worker', '--instance' => 'validation-secret', '--command' => ['/usr/bin/php']],
        'process.target_id_invalid',
        'Process target ID must be a positive integer.',
    ],
    'process runtime' => [
        'process:add',
        [
            'name' => 'worker',
            '--instance' => '1',
            '--runtime' => 'validation-secret',
            '--command' => ['/usr/bin/php'],
        ],
        'process.runtime_invalid',
        'Process runtime must be systemd or docker.',
    ],
    'process restart policy' => [
        'process:add',
        [
            'name' => 'worker',
            '--instance' => '1',
            '--command' => ['/usr/bin/php'],
            '--restart' => 'validation-secret',
        ],
        'process.restart_policy_invalid',
        'Invalid process restart policy.',
    ],
    'process environment' => [
        'process:add',
        [
            'name' => 'worker',
            '--instance' => '1',
            '--command' => ['/usr/bin/php'],
            '--environment' => ['validation-secret'],
        ],
        'process.environment_invalid',
        'Invalid environment value. Use NAME=VALUE.',
    ],
    'process volume' => [
        'process:add',
        [
            'name' => 'worker',
            '--instance' => '1',
            '--command' => ['/usr/bin/php'],
            '--volume' => ['validation-secret'],
        ],
        'process.volume_invalid',
        'Invalid volume. Use SOURCE:TARGET[:ro].',
    ],
    'process log lines' => [
        'process:logs',
        ['process' => '1', '--lines' => '0'],
        'process.log_lines_invalid',
        'Log lines must be between 1 and 1000.',
    ],
    'workspace checkout path' => [
        'workspace:new',
        ['instance' => '1', 'name' => 'workspace', '--path' => 'validation-secret'],
        'workspace.checkout_path_invalid',
        'Workspace checkout path must be a safe absolute path.',
    ],
    'multiple firewall values fail at the first error' => [
        'firewall:allow',
        [
            'name' => 'validation-secret name',
            '--node' => '0',
            '--protocol' => 'validation-secret',
            '--from' => 'validation-secret',
            '--port' => 'validation-secret',
        ],
        'firewall.node_id_invalid',
        'Node ID must be a positive integer.',
    ],
    'multiple instance values fail at the first error' => [
        'instance:new',
        ['app' => 'validation-secret', 'node' => '0', 'name' => ''],
        'app.id_invalid',
        'App ID must be a positive integer.',
    ],
    'multiple workspace values fail at the first error' => [
        'workspace:php',
        ['workspace' => 'validation-secret', 'version' => 'validation-secret'],
        'workspace.id_invalid',
        'Workspace ID must be a positive integer.',
    ],
    'node role list id' => [
        'node:role:list',
        ['node' => 'validation-secret'],
        'node.id_invalid',
        'Node ID must be a positive integer.',
    ],
    'node role add id' => [
        'node:role:add',
        ['node' => '0', 'role' => 'app-dev'],
        'node.id_invalid',
        'Node ID must be a positive integer.',
    ],
    'node role add empty role' => [
        'node:role:add',
        ['node' => '7', 'role' => ''],
        'node_role.role_required',
        'Role is required.',
    ],
    'node role remove id' => [
        'node:role:remove',
        ['node' => 'validation-secret', 'role' => 'app-dev', '--force' => true],
        'node.id_invalid',
        'Node ID must be a positive integer.',
    ],
    'node role remove empty role' => [
        'node:role:remove',
        ['node' => '7', 'role' => '', '--force' => true],
        'node_role.role_required',
        'Role is required.',
    ],
]);

it('renders console input failures through the exact json boundary', function (array $arguments): void {
    $command = app(\Illuminate\Contracts\Console\Kernel::class)->all()[$arguments['command']];
    $tester = new CommandTester($command);
    $expectedPayload = [
        'error' => [
            'code' => 'input.invalid',
            'message' => 'Command input is invalid.',
            'request_id' => null,
        ],
    ];

    $commandArguments = $arguments;
    unset($commandArguments['command']);

    $exitCode = $tester->execute($commandArguments, ['interactive' => false]);
    $output = trim($tester->getDisplay());

    expect($exitCode)->toBe(SymfonyCommand::FAILURE);
    expect($output)
        ->toBe(json_encode($expectedPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->not->toContain('validation-secret');
    expect(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
        ->toBe($expectedPayload);
})->with([
    'app show missing required argument' => [['command' => 'app:show', '--json' => true]],
    'app show unknown option' => [[
        'command' => 'app:show',
        'app' => '1',
        '--json' => true,
        '--validation-secret' => true,
    ]],
    'node role list missing required argument' => [['command' => 'node:role:list', '--json' => true]],
    'node role list unknown option' => [[
        'command' => 'node:role:list',
        'node' => '7',
        '--json' => true,
        '--validation-secret' => true,
    ]],
    'node role add missing required arguments' => [['command' => 'node:role:add', '--json' => true]],
    'node role add unknown option' => [[
        'command' => 'node:role:add',
        'node' => '7',
        'role' => 'app-dev',
        '--json' => true,
        '--validation-secret' => true,
    ]],
    'node role remove missing required arguments' => [['command' => 'node:role:remove', '--json' => true]],
    'node role remove unknown option' => [[
        'command' => 'node:role:remove',
        'node' => '7',
        'role' => 'app-dev',
        '--json' => true,
        '--validation-secret' => true,
    ]],
]);

function gateway_error_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
