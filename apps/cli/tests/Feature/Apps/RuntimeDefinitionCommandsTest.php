<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Apps\CreateProcessDefinitionRequest;
use Orbit\Sdk\Requests\Apps\CreateScheduleDefinitionRequest;
use Orbit\Sdk\Requests\Apps\ListProcessDefinitionsRequest;
use Orbit\Sdk\Requests\Apps\ListScheduleDefinitionsRequest;
use Orbit\Sdk\Requests\Apps\RemoveProcessDefinitionRequest;
use Orbit\Sdk\Requests\Apps\RemoveScheduleDefinitionRequest;
use Orbit\Sdk\Requests\Apps\ReplaceProcessDefinitionRequest;
use Orbit\Sdk\Requests\Apps\ReplaceScheduleDefinitionRequest;
use Orbit\Sdk\Requests\Apps\ShowProcessDefinitionRequest;
use Orbit\Sdk\Requests\Apps\ShowScheduleDefinitionRequest;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-definitions-'.Str::uuid();
    $this->definitionFile = $this->orbitHome.'/definition.json';
    config()->set('orbit.home', $this->orbitHome);

    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));

    new Filesystem()->put($this->definitionFile, runtime_definition_cli_json());
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

it('lists each definition kind as command-safe JSON', function (
    string $command,
    string $requestClass,
    string $endpoint,
): void {
    $mock = MockClient::global([
        $requestClass => MockResponse::make(runtime_definition_cli_collection_envelope(includeCommand: true)),
    ]);
    $expected = json_encode([
        'definitions' => [runtime_definition_cli_public_data(includeCommand: false)],
        'request_id' => runtime_definition_cli_request_id(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    $this
        ->artisan($command, ['app' => '7', '--json' => true])
        ->expectsOutput($expected)
        ->doesntExpectOutputToContain('command-sentinel')
        ->assertExitCode(0);

    expect($mock->getLastRequest())
        ->toBeInstanceOf($requestClass)
        ->and($mock->getLastPendingRequest()?->getUrl())
        ->toBe("https://10.44.0.1{$endpoint}");
})->with([
    'processes' => [
        'app:process-definitions',
        ListProcessDefinitionsRequest::class,
        '/api/v1/apps/7/process-definitions',
    ],
    'Schedules' => [
        'app:schedule-definitions',
        ListScheduleDefinitionsRequest::class,
        '/api/v1/apps/7/schedule-definitions',
    ],
]);

it('lists command-safe definitions for humans with the request ID', function (): void {
    MockClient::global([
        ListProcessDefinitionsRequest::class => MockResponse::make(
            runtime_definition_cli_collection_envelope(includeCommand: true),
        ),
    ]);

    $this
        ->artisan('app:process-definitions', ['app' => '7'])
        ->expectsTable(
            ['ID', 'Name', 'Environments', 'Specification'],
            [[
                runtime_definition_cli_id(),
                'worker',
                'development, production',
                '{"runtime":"systemd","restart_policy":"on-failure"}',
            ]],
        )
        ->expectsOutput('Request ID: '.runtime_definition_cli_request_id())
        ->doesntExpectOutputToContain('command-sentinel')
        ->assertExitCode(0);
});

it('dispatches every singular definition mode before rendering the returned item', function (
    string $command,
    string $mode,
    string $requestClass,
    Method $method,
    string $endpoint,
): void {
    $mock = MockClient::global([
        $requestClass => MockResponse::make(runtime_definition_cli_item_envelope()),
    ]);
    $arguments = ['app' => '7', '--json' => true];

    if (in_array($mode, ['show', 'replace', 'remove'], strict: true)) {
        $arguments['--id'] = runtime_definition_cli_id();
    }

    if (in_array($mode, ['create', 'replace'], strict: true)) {
        $arguments['--file'] = $this->definitionFile;
    }

    if ($mode === 'remove') {
        $arguments['--remove'] = true;
    }

    $this
        ->artisan($command, $arguments)
        ->expectsOutput(runtime_definition_cli_json_output())
        ->assertExitCode(0);

    $request = $mock->getLastRequest();
    $pending = $mock->getLastPendingRequest();

    expect($request)
        ->toBeInstanceOf($requestClass)
        ->and($request?->getMethod())
        ->toBe($method)
        ->and($pending?->getUrl())
        ->toBe("https://10.44.0.1{$endpoint}");

    if (in_array($mode, ['create', 'replace'], strict: true)) {
        expect((string) $pending?->body())
            ->toBe(runtime_definition_cli_json())
            ->and($pending?->headers()->get('Content-Type'))
            ->toBe('application/json');
    } else {
        expect($pending?->body())->toBeNull();
    }
})->with([
    'show process' => [
        'app:process-definition',
        'show',
        ShowProcessDefinitionRequest::class,
        Method::GET,
        '/api/v1/apps/7/process-definitions/'.runtime_definition_cli_id(),
    ],
    'create process' => [
        'app:process-definition',
        'create',
        CreateProcessDefinitionRequest::class,
        Method::POST,
        '/api/v1/apps/7/process-definitions',
    ],
    'replace process' => [
        'app:process-definition',
        'replace',
        ReplaceProcessDefinitionRequest::class,
        Method::PUT,
        '/api/v1/apps/7/process-definitions/'.runtime_definition_cli_id(),
    ],
    'remove process' => [
        'app:process-definition',
        'remove',
        RemoveProcessDefinitionRequest::class,
        Method::DELETE,
        '/api/v1/apps/7/process-definitions/'.runtime_definition_cli_id(),
    ],
    'show Schedule' => [
        'app:schedule-definition',
        'show',
        ShowScheduleDefinitionRequest::class,
        Method::GET,
        '/api/v1/apps/7/schedule-definitions/'.runtime_definition_cli_id(),
    ],
    'create Schedule' => [
        'app:schedule-definition',
        'create',
        CreateScheduleDefinitionRequest::class,
        Method::POST,
        '/api/v1/apps/7/schedule-definitions',
    ],
    'replace Schedule' => [
        'app:schedule-definition',
        'replace',
        ReplaceScheduleDefinitionRequest::class,
        Method::PUT,
        '/api/v1/apps/7/schedule-definitions/'.runtime_definition_cli_id(),
    ],
    'remove Schedule' => [
        'app:schedule-definition',
        'remove',
        RemoveScheduleDefinitionRequest::class,
        Method::DELETE,
        '/api/v1/apps/7/schedule-definitions/'.runtime_definition_cli_id(),
    ],
]);

it('refuses incompatible singular options before HTTP', function (array $options): void {
    $mock = MockClient::global();

    $this
        ->artisan('app:process-definition', ['app' => '7', ...$options])
        ->expectsOutputToContain('Use --id to show, --file to create, both to replace, or --id with --remove to delete a definition.')
        ->assertExitCode(1);

    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    'no operation' => [[]],
    'remove without ID' => [['--remove' => true]],
    'remove with file' => [['--file' => '/tmp/unused.json', '--remove' => true]],
    'replace and remove' => [[
        '--id' => runtime_definition_cli_id(),
        '--file' => '/tmp/unused.json',
        '--remove' => true,
    ]],
]);

it('refuses invalid App IDs, definition UUIDs, and files before HTTP', function (
    array $arguments,
    string $message,
): void {
    $mock = MockClient::global();

    $this
        ->artisan('app:schedule-definition', $arguments)
        ->expectsOutputToContain($message)
        ->assertExitCode(1);

    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    'App ID' => [
        ['app' => 'zero', '--id' => runtime_definition_cli_id()],
        'App ID must be a positive integer.',
    ],
    'definition ID' => [
        ['app' => '7', '--id' => 'not-a-uuid'],
        'Definition ID must be a UUID.',
    ],
    'definition file' => [
        ['app' => '7', '--file' => '/not/readable/definition-secret.json'],
        'Definition file is not readable.',
    ],
]);

it('submits file content without executing its command', function (): void {
    $createdPath = $this->orbitHome.'/must-not-exist';
    $content = json_encode([
        'name' => 'worker',
        'environments' => ['development'],
        'spec' => [
            'runtime' => 'systemd',
            'command' => ['/usr/bin/touch', $createdPath],
        ],
    ], JSON_THROW_ON_ERROR);
    new Filesystem()->put($this->definitionFile, $content);
    $mock = MockClient::global([
        CreateProcessDefinitionRequest::class => MockResponse::make(runtime_definition_cli_item_envelope(), 201),
    ]);

    $this
        ->artisan('app:process-definition', ['app' => '7', '--file' => $this->definitionFile])
        ->expectsOutput('Request ID: '.runtime_definition_cli_request_id())
        ->assertExitCode(0);

    expect((string) $mock->getLastPendingRequest()?->body())
        ->toBe($content)
        ->and($createdPath)
        ->not->toBeFile();
});

it('preserves safe Gateway failures and their request IDs', function (): void {
    MockClient::global([
        ShowProcessDefinitionRequest::class => MockResponse::make([
            'error' => [
                'code' => 'process_definition.name_taken',
                'message' => 'The process definition name is already in use.',
                'details' => [],
            ],
        ], 409, ['X-Orbit-Request-Id' => runtime_definition_cli_request_id()]),
    ]);

    $this
        ->artisan('app:process-definition', [
            'app' => '7',
            '--id' => runtime_definition_cli_id(),
            '--json' => true,
        ])
        ->expectsOutput(json_encode([
            'error' => [
                'code' => 'process_definition.name_taken',
                'message' => 'The process definition name is already in use.',
                'request_id' => runtime_definition_cli_request_id(),
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->assertExitCode(1);
});

/** @return array<string, mixed> */
function runtime_definition_cli_collection_envelope(bool $includeCommand): array
{
    return [
        'data' => [runtime_definition_cli_gateway_data($includeCommand)],
        'meta' => ['request_id' => runtime_definition_cli_request_id()],
    ];
}

/** @return array<string, mixed> */
function runtime_definition_cli_item_envelope(): array
{
    return [
        'data' => runtime_definition_cli_gateway_data(includeCommand: true),
        'meta' => ['request_id' => runtime_definition_cli_request_id()],
    ];
}

/** @return array<string, mixed> */
function runtime_definition_cli_gateway_data(bool $includeCommand): array
{
    $spec = [
        'runtime' => 'systemd',
        'restart_policy' => 'on-failure',
    ];

    if ($includeCommand) {
        $spec['command'] = ['/usr/bin/command-sentinel'];
    }

    return [
        'id' => runtime_definition_cli_id(),
        'app_id' => 7,
        'name' => 'worker',
        'environments' => ['development', 'production'],
        'spec' => $spec,
    ];
}

/** @return array<string, mixed> */
function runtime_definition_cli_public_data(bool $includeCommand): array
{
    return runtime_definition_cli_gateway_data($includeCommand);
}

function runtime_definition_cli_json_output(): string
{
    return json_encode([
        ...runtime_definition_cli_gateway_data(includeCommand: true),
        'request_id' => runtime_definition_cli_request_id(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function runtime_definition_cli_json(): string
{
    return '{"name":"worker","environments":["development"],"spec":{"runtime":"systemd","command":["/usr/bin/php","artisan","queue:work"]}}';
}

function runtime_definition_cli_id(): string
{
    return '0199cc62-68f3-75b8-9f11-36fe92ac1f36';
}

function runtime_definition_cli_request_id(): string
{
    return '0199cc62-854a-7d2e-8e37-342c14451d1a';
}
