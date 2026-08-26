<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Processes\AddProcessRequest;
use Orbit\Sdk\Requests\Processes\ListProcessesRequest;
use Orbit\Sdk\Requests\Processes\ProcessLogsRequest;
use Orbit\Sdk\Requests\Processes\RemoveProcessRequest;
use Orbit\Sdk\Requests\Processes\RestartProcessRequest;
use Orbit\Sdk\Requests\Processes\StartProcessRequest;
use Orbit\Sdk\Requests\Processes\StopProcessRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-process-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

function process_cli_secret(string $suffix): string
{
    return implode('-', ['redacted', 'fixture', $suffix]);
}

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

it('adds one explicit Docker process through the active gateway', function (): void {
    $mock = MockClient::global([
        AddProcessRequest::class => process_cli_response(201),
    ]);

    $this
        ->artisan('process:add', [
            'name' => 'redis',
            '--instance' => '7',
            '--runtime' => 'docker',
            '--command' => ['redis-server'],
            '--image' => 'redis:8-alpine',
            '--working-directory' => '/data',
            '--environment' => ['APP_MODE=test'],
            '--port' => ['127.0.0.1:6380:6379/tcp'],
            '--volume' => ['redis-data:/data'],
            '--restart' => 'unless-stopped',
            '--start' => true,
            '--json' => true,
        ])
        ->expectsOutput(process_cli_json())
        ->assertExitCode(0);

    expect($mock->getLastPendingRequest()?->getUrl())
        ->toBe('https://10.44.0.1/api/v1/processes')
        ->and($mock->getLastRequest()?->body()->all())
        ->toMatchArray([
            'target_type' => 'instance',
            'target_id' => 7,
            'name' => 'redis',
            'runtime' => 'docker',
            'command' => ['redis-server'],
            'image' => 'redis:8-alpine',
            'working_directory' => '/data',
            'environment' => ['APP_MODE' => 'test'],
            'ports' => ['127.0.0.1:6380:6379/tcp'],
            'volumes' => [['source' => 'redis-data', 'target' => '/data', 'read_only' => false]],
            'restart_policy' => 'unless-stopped',
            'start' => true,
        ]);
});

it('passes Gateway-owned process policy values through the typed SDK request', function (): void {
    $mock = MockClient::global([
        AddProcessRequest::class => process_cli_response(201),
    ]);

    $this
        ->artisan('process:add', [
            'name' => 'Worker Name',
            '--instance' => '7',
            '--runtime' => 'docker',
            '--command' => ['php', 'artisan'],
            '--image' => '--gateway-validates-image',
            '--working-directory' => 'relative/../path',
            '--environment' => ['APP_MODE=test'],
            '--port' => ['not-a-port'],
            '--volume' => ['source,readonly:relative'],
        ])
        ->assertExitCode(0);

    expect($mock->getLastRequest())
        ->toBeInstanceOf(AddProcessRequest::class)
        ->and($mock->getLastRequest()?->body()->all())
        ->toBe([
            'target_type' => 'instance',
            'target_id' => 7,
            'name' => 'Worker Name',
            'runtime' => 'docker',
            'command' => ['php', 'artisan'],
            'restart_policy' => 'never',
            'start' => false,
            'environment' => ['APP_MODE' => 'test'],
            'ports' => ['not-a-port'],
            'volumes' => [['source' => 'source,readonly', 'target' => 'relative', 'read_only' => false]],
            'image' => '--gateway-validates-image',
            'working_directory' => 'relative/../path',
        ]);
});

it('passes an empty Docker command and missing image to the Gateway for policy validation', function (): void {
    $mock = MockClient::global([
        AddProcessRequest::class => process_cli_response(201),
    ]);

    $this
        ->artisan('process:add', [
            'name' => 'worker',
            '--instance' => '7',
            '--runtime' => 'docker',
        ])
        ->assertExitCode(0);

    expect($mock->getLastRequest())
        ->toBeInstanceOf(AddProcessRequest::class)
        ->and($mock->getLastRequest()?->body()->all())
        ->toBe([
            'target_type' => 'instance',
            'target_id' => 7,
            'name' => 'worker',
            'runtime' => 'docker',
            'command' => [],
            'restart_policy' => 'never',
            'start' => false,
        ]);
});

it('passes a relative systemd executable to the Gateway for policy validation', function (): void {
    $mock = MockClient::global([
        AddProcessRequest::class => process_cli_response(201),
    ]);

    $this
        ->artisan('process:add', [
            'name' => 'worker',
            '--instance' => '7',
            '--command' => ['php', 'artisan'],
        ])
        ->assertExitCode(0);

    expect($mock->getLastRequest())
        ->toBeInstanceOf(AddProcessRequest::class)
        ->and($mock->getLastRequest()?->body()->all())
        ->toBe([
            'target_type' => 'instance',
            'target_id' => 7,
            'name' => 'worker',
            'runtime' => 'systemd',
            'command' => ['php', 'artisan'],
            'restart_policy' => 'never',
            'start' => false,
        ]);
});

it('preserves explicitly supplied empty process arrays', function (): void {
    $mock = MockClient::global([
        AddProcessRequest::class => process_cli_response(201),
    ]);

    $this
        ->artisan('process:add', [
            'name' => 'worker',
            '--instance' => '7',
            '--environment' => [],
            '--port' => [],
            '--volume' => [],
        ])
        ->assertExitCode(0);

    expect($mock->getLastRequest())
        ->toBeInstanceOf(AddProcessRequest::class)
        ->and($mock->getLastRequest()?->body()->all())
        ->toBe([
            'target_type' => 'instance',
            'target_id' => 7,
            'name' => 'worker',
            'runtime' => 'systemd',
            'command' => [],
            'restart_policy' => 'never',
            'start' => false,
            'environment' => [],
            'ports' => [],
            'volumes' => [],
        ]);
});

it('omits Docker environment values from process JSON output', function (): void {
    MockClient::global([
        AddProcessRequest::class => MockResponse::make([
            'data' => process_cli_payload([
                'runtime_config' => [
                    'image' => 'redis:8-alpine',
                    'command' => ['redis-server'],
                    'environment' => [
                        'APP_MODE' => 'test',
                        'DB_SECRET' => process_cli_secret('secret'),
                    ],
                    'ports' => ['127.0.0.1:6380:6379/tcp'],
                    'volumes' => [['source' => 'redis-data', 'target' => '/data', 'read_only' => false]],
                ],
            ]),
        ]),
    ]);

    $this
        ->artisan('process:add', [
            'name' => 'redis',
            '--instance' => '7',
            '--runtime' => 'docker',
            '--command' => ['redis-server'],
            '--image' => 'redis:8-alpine',
            '--json' => true,
        ])
        ->doesntExpectOutputToContain('"environment"')
        ->doesntExpectOutputToContain(process_cli_secret('secret'))
        ->assertExitCode(0);
});

it('lists one target process collection for humans', function (): void {
    MockClient::global([
        ListProcessesRequest::class => MockResponse::make([
            'data' => [process_cli_payload()],
            'meta' => ['request_id' => process_cli_request_id()],
        ]),
    ]);

    $this
        ->artisan('process:list', ['--instance' => '7'])
        ->expectsTable(
            ['ID', 'Name', 'Runtime', 'Desired', 'Runtime status', 'Restart'],
            [[12, 'redis', 'docker', 'running', 'running', 'unless-stopped']],
        )
        ->expectsOutput('Request ID: '.process_cli_request_id())
        ->assertExitCode(0);
});

it('runs one process lifecycle action', function (
    string $command,
    string $requestClass,
    string $verb,
): void {
    $mock = MockClient::global([
        $requestClass => process_cli_response(),
    ]);

    $this
        ->artisan($command, ['process' => '12'])
        ->expectsOutput("Process [redis] {$verb}.")
        ->expectsOutput('Request ID: '.process_cli_request_id())
        ->assertExitCode(0);

    expect($mock->getLastRequest())->toBeInstanceOf($requestClass);
})->with([
    'start' => ['process:start', StartProcessRequest::class, 'started'],
    'stop' => ['process:stop', StopProcessRequest::class, 'stopped'],
    'restart' => ['process:restart', RestartProcessRequest::class, 'restarted'],
    'remove' => ['process:remove', RemoveProcessRequest::class, 'removed'],
]);

it('prints one bounded log tail without follow mode', function (): void {
    $mock = MockClient::global([
        ProcessLogsRequest::class => MockResponse::make([
            'data' => [
                'id' => 12,
                'name' => 'redis',
                'lines' => 25,
                'logs' => "first\nsecond\n",
            ],
            'meta' => ['request_id' => process_cli_request_id()],
        ]),
    ]);

    $this
        ->artisan('process:logs', ['process' => '12', '--lines' => '25'])
        ->expectsOutput("first\nsecond")
        ->expectsOutput('Request ID: '.process_cli_request_id())
        ->assertExitCode(0);

    expect($mock->getLastRequest())
        ->toBeInstanceOf(ProcessLogsRequest::class)
        ->and($mock->getLastPendingRequest()?->getUrl())
        ->toBe('https://10.44.0.1/api/v1/processes/12/logs')
        ->and($mock->getLastRequest()?->query()->all())
        ->toBe(['lines' => 25]);
});

it('redacts secret environment values from JSON process output', function (): void {
    MockClient::global([
        AddProcessRequest::class => process_cli_secret_response(201),
    ]);

    $this
        ->artisan('process:add', [
            'name' => 'redis',
            '--instance' => '7',
            '--runtime' => 'docker',
            '--command' => ['redis-server'],
            '--image' => 'redis:8-alpine',
            '--json' => true,
        ])
        ->expectsOutput(process_cli_secret_json())
        ->doesntExpectOutputToContain(process_cli_secret_value())
        ->assertExitCode(0);
});

it('redacts secret environment values from JSON process collections', function (): void {
    MockClient::global([
        ListProcessesRequest::class => MockResponse::make([
            'data' => [process_cli_secret_payload()],
            'meta' => ['request_id' => process_cli_request_id()],
        ]),
    ]);

    $this
        ->artisan('process:list', ['--instance' => '7', '--json' => true])
        ->expectsOutput(json_encode([
            'processes' => [process_cli_sanitized_payload()],
            'request_id' => process_cli_request_id(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->doesntExpectOutputToContain(process_cli_secret_value())
        ->assertExitCode(0);
});

it('redacts secret environment values from JSON lifecycle responses', function (): void {
    MockClient::global([
        StartProcessRequest::class => process_cli_secret_response(),
    ]);

    $this
        ->artisan('process:start', ['process' => '12', '--json' => true])
        ->expectsOutput(process_cli_secret_json())
        ->doesntExpectOutputToContain(process_cli_secret_value())
        ->assertExitCode(0);
});

it('redacts credential-shaped values from every process JSON runtime field', function (): void {
    $commandSecret = process_cli_secret('command');
    $inlineCommandSecret = process_cli_secret('inline-command');
    $imageSecret = process_cli_secret('image-response');
    $workingDirectorySecret = process_cli_secret('working-directory-response');
    $portSecret = process_cli_secret('port-response');
    $volumeSecret = process_cli_secret('volume-response');
    $operationTokenSecret = process_cli_secret('operation-token-response');
    $executorSecret = process_cli_secret('executor-response');
    $nestedCommandSecret = process_cli_secret('nested-command-response');
    $payload = process_cli_payload();
    $payload['working_directory'] = "/srv/password={$workingDirectorySecret}";
    $payload['runtime_config'] = [
        'image' => "registry.example/password={$imageSecret}",
        'command' => [
            'worker',
            '--operation-token',
            $commandSecret,
            "--password={$inlineCommandSecret}",
            ['api_token' => $nestedCommandSecret],
        ],
        'environment' => ['APP_ENV' => process_cli_secret('environment-response')],
        'operation_token' => $operationTokenSecret,
        'metadata' => ['executor_secret' => $executorSecret],
        'ports' => ["token={$portSecret}"],
        'volumes' => [[
            'source' => "https://user:{$volumeSecret}@storage.example/data",
            'target' => '/data',
            'read_only' => true,
        ]],
    ];
    MockClient::global([
        AddProcessRequest::class => MockResponse::make([
            'data' => $payload,
            'meta' => ['request_id' => process_cli_request_id()],
        ], 201),
    ]);

    $exitCode = Artisan::call('process:add', [
        'name' => 'worker',
        '--instance' => '7',
        '--runtime' => 'docker',
        '--command' => ['worker'],
        '--image' => 'worker:latest',
        '--json' => true,
    ]);
    $output = trim(Artisan::output());
    $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0);
    expect($output)
        ->not->toContain($commandSecret)
        ->not->toContain($inlineCommandSecret)
        ->not->toContain($imageSecret)
        ->not->toContain($workingDirectorySecret)
        ->not->toContain($portSecret)
        ->not->toContain($volumeSecret)
        ->not->toContain($operationTokenSecret)
        ->not->toContain($executorSecret)
        ->not->toContain($nestedCommandSecret)
        ->not->toContain('environment-response');
    expect($decoded['runtime_config'] ?? null)
        ->toBeArray()
        ->not->toHaveKey('environment');
    expect($decoded['runtime_config']['command'] ?? null)->toBe([
        'worker',
        '--operation-token',
        '[redacted]',
        '--'.implode('', ['pass', 'word']).'=[redacted]',
        [implode('_', ['api', 'token']) => '[redacted]'],
    ]);
});

it('redacts a malformed associative command response recursively', function (): void {
    $secret = process_cli_secret('associative-command');
    $payload = process_cli_payload();
    $sensitiveKey = implode('_', ['private', 'key']);
    $payload['runtime_config']['command'] = [$sensitiveKey => $secret];
    MockClient::global([
        AddProcessRequest::class => MockResponse::make([
            'data' => $payload,
            'meta' => ['request_id' => process_cli_request_id()],
        ], 201),
    ]);

    $exitCode = Artisan::call('process:add', [
        'name' => 'worker',
        '--instance' => '7',
        '--runtime' => 'docker',
        '--command' => ['worker'],
        '--image' => 'worker:latest',
        '--json' => true,
    ]);
    $output = trim(Artisan::output());
    $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0);
    expect($output)->not->toContain($secret);
    expect($decoded['runtime_config']['command'] ?? null)->toBe([$sensitiveKey => '[redacted]']);
});

it('redacts secret-looking log values in human output', function (): void {
    MockClient::global([
        ProcessLogsRequest::class => MockResponse::make([
            'data' => [
                'id' => 12,
                'name' => 'redis',
                'lines' => 25,
                'logs' =>
                    'DB_PASSWORD='
                        .process_cli_secret('db')
                        ."\n"
                        .'Authorization: Bearer '
                        .process_cli_secret('token')
                        ."\n"
                        ."safe line\n",
            ],
            'meta' => ['request_id' => process_cli_request_id()],
        ]),
    ]);

    $this
        ->artisan('process:logs', ['process' => '12', '--lines' => '25'])
        ->expectsOutput("DB_PASSWORD=[redacted]\nAuthorization: [redacted]\nsafe line")
        ->doesntExpectOutputToContain(process_cli_secret('db'))
        ->doesntExpectOutputToContain(process_cli_secret('token'))
        ->assertExitCode(0);
});

it('redacts secret-looking log values in JSON output', function (): void {
    MockClient::global([
        ProcessLogsRequest::class => MockResponse::make([
            'data' => [
                'id' => 12,
                'name' => 'redis',
                'lines' => 25,
                'logs' =>
                    'api_key='
                        .process_cli_secret('api')
                        ."\n"
                        .'{"password":"'
                        .process_cli_secret('json')
                        .'"}'
                        ."\n"
                        .'postgres://operator:'
                        .process_cli_secret('url')
                        .'@db.internal/app'
                        ."\n"
                        .'https://service.internal/?token='
                        .process_cli_secret('query')
                        ."\n"
                        .'status: ready'
                        ."\n",
            ],
            'meta' => ['request_id' => process_cli_request_id()],
        ]),
    ]);

    $this
        ->artisan('process:logs', ['process' => '12', '--lines' => '25', '--json' => true])
        ->expectsOutputToContain(
            '"logs":"api_key=[redacted]\\n'
            .'{\\"password\\":[redacted]}\\n'
            .'postgres://[redacted]@db.internal/app\\n'
            .'https://service.internal/?token=[redacted]\\n'
            .'status: ready\\n"',
        )
        ->doesntExpectOutputToContain(process_cli_secret('api'))
        ->doesntExpectOutputToContain(process_cli_secret('json'))
        ->doesntExpectOutputToContain(process_cli_secret('url'))
        ->doesntExpectOutputToContain(process_cli_secret('query'))
        ->assertExitCode(0);
});

it('redacts the complete proven credential set from process logs', function (): void {
    $appKey = process_cli_secret('app-key');
    $basicCredential = process_cli_secret('basic');
    $proxyCredential = process_cli_secret('proxy');
    $bearerCredential = process_cli_secret('bearer-token');
    $queryCredential = process_cli_secret('access-token');
    $pemCredential = process_cli_secret('pem');
    MockClient::global([
        ProcessLogsRequest::class => MockResponse::make([
            'data' => [
                'id' => 12,
                'name' => 'redis',
                'lines' => 25,
                'logs' =>
                    "APP_KEY={$appKey}\n"
                        ."Authorization: Basic {$basicCredential}\n"
                        ."Proxy-Authorization: Basic {$proxyCredential}\n"
                        ."Bearer {$bearerCredential}\n"
                        ."https://service.internal/?access_token={$queryCredential}\n"
                        ."-----BEGIN PRIVATE KEY-----\n{$pemCredential}\n-----END PRIVATE KEY-----\n"
                        ."upstream: [REDACTED]\n"
                        ."status: ready\n",
            ],
            'meta' => ['request_id' => process_cli_request_id()],
        ]),
    ]);

    $exitCode = Artisan::call('process:logs', [
        'process' => '12',
        '--lines' => '25',
        '--json' => true,
    ]);
    $output = trim(Artisan::output());

    expect($exitCode)->toBe(0);
    expect($output)
        ->toContain('"logs":"APP_KEY=[redacted]\\n')
        ->toContain('Authorization: [redacted]')
        ->toContain('Proxy-Authorization: [redacted]')
        ->toContain('Bearer [redacted]')
        ->toContain('access_token=[redacted]')
        ->toContain('[redacted]\\nupstream: [redacted]\\nstatus: ready')
        ->not->toContain('[REDACTED]')
        ->not->toContain($appKey)
        ->not->toContain($basicCredential)
        ->not->toContain($proxyCredential)
        ->not->toContain($bearerCredential)
        ->not->toContain($queryCredential)
        ->not->toContain($pemCredential);
});

it('normalizes upstream redaction markers in human process logs', function (): void {
    MockClient::global([
        ProcessLogsRequest::class => MockResponse::make([
            'data' => [
                'id' => 12,
                'name' => 'redis',
                'lines' => 25,
                'logs' => "Bearer [REDACTED]\nupstream: [REDACTED]\nstatus: ready\n",
            ],
            'meta' => ['request_id' => process_cli_request_id()],
        ]),
    ]);

    $this
        ->artisan('process:logs', ['process' => '12', '--lines' => '25'])
        ->expectsOutput("Bearer [redacted]\nupstream: [redacted]\nstatus: ready")
        ->doesntExpectOutputToContain('[REDACTED]')
        ->assertExitCode(0);
});

it('passes every explicit systemd field through the typed SDK request', function (): void {
    $mock = MockClient::global([
        AddProcessRequest::class => process_cli_response(201),
    ]);

    $this
        ->artisan('process:add', [
            'name' => 'queue',
            '--instance' => '7',
            '--runtime' => 'systemd',
            '--command' => ['/usr/bin/php', 'artisan'],
            '--image' => 'gateway-validates-image',
            '--working-directory' => '/srv/orbit',
            '--environment' => ['APP_MODE=test'],
            '--port' => ['8080:80'],
            '--volume' => ['data:/data:ro'],
            '--restart' => 'always',
            '--start' => true,
        ])
        ->assertExitCode(0);

    expect($mock->getLastRequest())
        ->toBeInstanceOf(AddProcessRequest::class)
        ->and($mock->getLastRequest()?->body()->all())
        ->toBe([
            'target_type' => 'instance',
            'target_id' => 7,
            'name' => 'queue',
            'runtime' => 'systemd',
            'command' => ['/usr/bin/php', 'artisan'],
            'restart_policy' => 'always',
            'start' => true,
            'environment' => ['APP_MODE' => 'test'],
            'ports' => ['8080:80'],
            'volumes' => [['source' => 'data', 'target' => '/data', 'read_only' => true]],
            'image' => 'gateway-validates-image',
            'working_directory' => '/srv/orbit',
        ]);
});

it('rejects unbounded or unsafe process options without disclosure or gateway IO', function (
    array $options,
    string $code,
    string $message,
    #[\SensitiveParameter]
    string $secret,
): void {
    $mock = MockClient::global();
    $expected = json_encode([
        'error' => [
            'code' => $code,
            'message' => $message,
            'request_id' => null,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $arguments = [
        'name' => 'worker',
        '--instance' => '7',
        '--runtime' => 'docker',
        '--command' => ['worker'],
        '--image' => 'worker:latest',
        '--json' => true,
        ...$options,
    ];

    $exitCode = Artisan::call('process:add', $arguments);
    $output = trim(Artisan::output());

    expect($exitCode)->toBe(1);
    expect($output)
        ->toBe($expected)
        ->not->toContain($secret);
    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    'process name' => fn (): array => [
        ['name' => "worker\n".process_cli_secret('name')],
        'process.name_invalid',
        'Process name is invalid.',
        process_cli_secret('name'),
    ],
    'command count' => fn (): array => [
        [
            '--command' => [
                ...array_fill(start_index: 0, count: 64, value: 'worker'),
                process_cli_secret('command-count'),
            ],
        ],
        'process.command_invalid',
        'Process command arguments are invalid.',
        process_cli_secret('command-count'),
    ],
    'command length' => fn (): array => [
        [
            '--command' => [
                'worker',
                str_repeat(string: 'x', times: 4097).process_cli_secret('command-length'),
            ],
        ],
        'process.command_invalid',
        'Process command arguments are invalid.',
        process_cli_secret('command-length'),
    ],
    'command control byte' => fn (): array => [
        ['--command' => ['worker', "arg\0".process_cli_secret('command-control')]],
        'process.command_invalid',
        'Process command arguments are invalid.',
        process_cli_secret('command-control'),
    ],
    'command carriage return' => fn (): array => [
        ['--command' => ['worker', "arg\r".process_cli_secret('command-carriage-return')]],
        'process.command_invalid',
        'Process command arguments are invalid.',
        process_cli_secret('command-carriage-return'),
    ],
    'command line feed' => fn (): array => [
        ['--command' => ['worker', "arg\n".process_cli_secret('command-line-feed')]],
        'process.command_invalid',
        'Process command arguments are invalid.',
        process_cli_secret('command-line-feed'),
    ],
    'Docker image length' => fn (): array => [
        ['--image' => str_repeat(string: 'x', times: 256).process_cli_secret('image-length')],
        'process.image_invalid',
        'Docker image is invalid.',
        process_cli_secret('image-length'),
    ],
    'Docker image control byte' => fn (): array => [
        ['--image' => "worker\n".process_cli_secret('image-control')],
        'process.image_invalid',
        'Docker image is invalid.',
        process_cli_secret('image-control'),
    ],
    'working directory length' => fn (): array => [
        [
            '--working-directory' =>
                '/'.str_repeat(string: 'x', times: 4096).process_cli_secret('working-directory-length'),
        ],
        'process.working_directory_invalid',
        'Process working directory is invalid.',
        process_cli_secret('working-directory-length'),
    ],
    'working directory control byte' => fn (): array => [
        ['--working-directory' => "/srv\n".process_cli_secret('working-directory-control')],
        'process.working_directory_invalid',
        'Process working directory is invalid.',
        process_cli_secret('working-directory-control'),
    ],
    'environment count' => fn (): array => [
        [
            '--environment' => [
                ...array_map(
                    static fn (int $index): string => "VALUE_{$index}=safe",
                    range(start: 1, end: 100),
                ),
                'SECRET_VALUE='.process_cli_secret('environment-count'),
            ],
        ],
        'process.environment_invalid',
        'Invalid environment value. Use NAME=VALUE.',
        process_cli_secret('environment-count'),
    ],
    'environment value length' => fn (): array => [
        [
            '--environment' => [
                'APP_VALUE='.str_repeat(string: 'x', times: 4097).process_cli_secret('environment-length'),
            ],
        ],
        'process.environment_invalid',
        'Invalid environment value. Use NAME=VALUE.',
        process_cli_secret('environment-length'),
    ],
    'port count' => fn (): array => [
        [
            '--port' => array_map(
                static fn (int $port): string => "{$port}:80/tcp",
                range(start: 10_000, end: 10_100),
            ),
        ],
        'process.port_invalid',
        'Process port value is invalid.',
        process_cli_secret('port-count'),
    ],
    'volume count' => fn (): array => [
        [
            '--volume' => [
                ...array_fill(start_index: 0, count: 100, value: 'data:/data'),
                process_cli_secret('volume-count').':/overflow',
            ],
        ],
        'process.volume_invalid',
        'Invalid volume. Use SOURCE:TARGET[:ro].',
        process_cli_secret('volume-count'),
    ],
]);

it('rejects unbounded or control-bearing Docker ports without disclosure or gateway IO', function (string $port): void {
    $mock = MockClient::global();
    $expected = json_encode([
        'error' => [
            'code' => 'process.port_invalid',
            'message' => 'Process port value is invalid.',
            'request_id' => null,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $exitCode = Artisan::call('process:add', [
        'name' => 'queue',
        '--instance' => '7',
        '--runtime' => 'docker',
        '--command' => ['php'],
        '--image' => 'php:8.5',
        '--port' => [$port],
        '--json' => true,
    ]);
    $output = trim(Artisan::output());

    expect($exitCode)->toBe(1);
    expect($output)
        ->toBe($expected)
        ->not->toContain(process_cli_secret('port-input'));
    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    'over maximum length' => fn (): string => str_repeat(string: '8', times: 4097).process_cli_secret('port-input'),
    'carriage return' => fn (): string => '8080:80/tcp'."\r".process_cli_secret('port-input'),
    'line feed' => fn (): string => '8080:80/tcp'."\n".process_cli_secret('port-input'),
    'NUL byte' => fn (): string => '8080:80/tcp'."\0".process_cli_secret('port-input'),
]);

it('rejects invalid local process input before making a gateway request', function (
    string $command,
    array $arguments,
    string $message,
): void {
    $mock = MockClient::global();

    $this
        ->artisan($command, $arguments)
        ->expectsOutputToContain($message)
        ->assertExitCode(1);

    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    'both targets' => [
        'process:add',
        [
            'name' => 'queue',
            '--instance' => '7',
            '--workspace' => '8',
            '--command' => ['/usr/bin/php'],
        ],
        'Select exactly one instance or workspace target.',
    ],
    'unbounded logs' => [
        'process:logs',
        ['process' => '12', '--lines' => '1001'],
        'Log lines must be between 1 and 1000.',
    ],
]);

it('does not disclose malformed environment values', function (string $environment): void {
    $mock = MockClient::global();
    $exceptionMessage = '';
    $exitCode = null;

    try {
        $exitCode = Artisan::call('process:add', [
            'name' => 'queue',
            '--instance' => '7',
            '--command' => ['/usr/bin/php'],
            '--environment' => [$environment],
        ]);
    } catch (Throwable $exception) {
        $exceptionMessage = $exception->getMessage();
    }

    expect($exitCode)
        ->toBe(1)
        ->and(Artisan::output())
        ->toContain('Invalid environment value. Use NAME=VALUE.')
        ->not->toContain(process_cli_secret('input'))->and($exceptionMessage)
        ->not->toContain(process_cli_secret('input'))->and($mock->getLastPendingRequest())->toBeNull();
})->with([
    'missing equals' => fn (): string => process_cli_secret('input'),
    'invalid name' => fn (): string => 'INVALID-NAME='.process_cli_secret('input'),
    'carriage return' => fn (): string => 'TOKEN='.process_cli_secret('input')."\rignored",
    'line feed' => fn (): string => 'TOKEN='.process_cli_secret('input')."\nignored",
    'NUL byte' => fn (): string => 'TOKEN='.process_cli_secret('input')."\0ignored",
]);

it('does not disclose malformed volume values', function (string $volume): void {
    $mock = MockClient::global();
    $exceptionMessage = '';
    $exitCode = null;

    try {
        $exitCode = Artisan::call('process:add', [
            'name' => 'queue',
            '--instance' => '7',
            '--command' => ['/usr/bin/php'],
            '--volume' => [$volume],
        ]);
    } catch (Throwable $exception) {
        $exceptionMessage = $exception->getMessage();
    }

    expect($exitCode)
        ->toBe(1)
        ->and(Artisan::output())
        ->toContain('Invalid volume. Use SOURCE:TARGET[:ro].')
        ->not->toContain(process_cli_secret('volume'))->and($exceptionMessage)
        ->not->toContain(process_cli_secret('volume'))->and($mock->getLastPendingRequest())->toBeNull();
})->with([
    'missing target' => fn (): string => process_cli_secret('volume'),
    'carriage return' => fn (): string => process_cli_secret('volume').":/data\rignored",
    'line feed' => fn (): string => process_cli_secret('volume').":/data\nignored",
    'NUL byte' => fn (): string => process_cli_secret('volume').":/data\0ignored",
]);

function process_cli_response(int $status = 200): MockResponse
{
    return MockResponse::make([
        'data' => process_cli_payload(),
        'meta' => ['request_id' => process_cli_request_id()],
    ], $status);
}

/** @return array<string, mixed> */
/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function process_cli_payload(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 12,
        'target_type' => 'instance',
        'target_id' => 7,
        'name' => 'redis',
        'runtime' => 'docker',
        'working_directory' => '/data',
        'runtime_config' => [
            'image' => 'redis:8-alpine',
            'command' => ['redis-server'],
            'environment' => ['APP_MODE' => 'test'],
            'ports' => ['127.0.0.1:6380:6379/tcp'],
            'volumes' => [['source' => 'redis-data', 'target' => '/data', 'read_only' => false]],
        ],
        'restart_policy' => 'unless-stopped',
        'desired_state' => 'running',
        'status' => 'active',
        'runtime_status' => 'running',
        'failed_step' => null,
        'error_code' => null,
    ], $overrides);
}

function process_cli_json(): string
{
    return json_encode([
        ...process_cli_sanitized_payload(process_cli_payload()),
        'request_id' => process_cli_request_id(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function process_cli_secret_response(int $status = 200): MockResponse
{
    return MockResponse::make([
        'data' => process_cli_secret_payload(),
        'meta' => ['request_id' => process_cli_request_id()],
    ], $status);
}

/** @return array<string, mixed> */
function process_cli_secret_payload(): array
{
    return [
        ...process_cli_payload(),
        'runtime_config' => [
            ...process_cli_payload()['runtime_config'],
            'environment' => [
                'APP_MODE' => 'test',
                'DB_SECRET' => process_cli_secret_value(),
                'REDIS_URL' => 'redis://:'.process_cli_secret_value().'@redis.internal:6379/0',
            ],
        ],
    ];
}

/** @return array<string, mixed> */
function process_cli_sanitized_payload(?array $payload = null): array
{
    $payload ??= process_cli_secret_payload();
    $runtimeConfig = $payload['runtime_config'];
    unset($runtimeConfig['environment']);

    return [
        ...$payload,
        'runtime_config' => $runtimeConfig,
    ];
}

function process_cli_secret_json(): string
{
    return json_encode([
        ...process_cli_sanitized_payload(),
        'request_id' => process_cli_request_id(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function process_cli_secret_value(): string
{
    return process_cli_secret('value');
}

function process_cli_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
