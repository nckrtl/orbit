<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Schedules\ActivateScheduleRequest;
use Orbit\Sdk\Requests\Schedules\AddScheduleRequest;
use Orbit\Sdk\Requests\Schedules\ListSchedulesRequest;
use Orbit\Sdk\Requests\Schedules\RemoveScheduleRequest;
use Orbit\Sdk\Requests\Schedules\RunScheduleRequest;
use Orbit\Sdk\Requests\Schedules\ScheduleLogsRequest;
use Orbit\Sdk\Requests\Schedules\ShowScheduleRequest;
use Orbit\Sdk\Responses\Schedules\ScheduleLogsResponse;
use Orbit\Sdk\Responses\Schedules\ScheduleResponse;
use Orbit\Sdk\Responses\Schedules\SchedulesResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Command\Command;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-schedule-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/tmp/ca.pem',
    ));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

it('adds one Node Schedule through exactly one typed request and renders human output', function (): void {
    $mock = MockClient::global([
        AddScheduleRequest::class => schedule_cli_response([
            'target_type' => 'node',
            'target_id' => 3,
            'desired_timer_state' => 'enabled',
        ], 201),
    ]);

    $this
        ->artisan('schedule:add', schedule_cli_add_arguments(['--node' => '3']))
        ->expectsTable(['Field', 'Value'], schedule_cli_item_rows([
            'target_type' => 'node',
            'target_id' => 3,
            'desired_timer_state' => 'enabled',
        ]))
        ->assertExitCode(Command::SUCCESS);

    $mock->assertSentCount(1, AddScheduleRequest::class);
    expect($mock->getLastPendingRequest()?->getUrl())
        ->toBe('https://10.44.0.1/api/v1/schedules')
        ->and($mock->getLastRequest()?->body()->all())
        ->toBe([
            'target_type' => 'node',
            'target_id' => 3,
            'name' => 'daily-report',
            'calendar' => 'daily',
            'command' => 'php artisan report:send',
            'timeout_seconds' => 900,
        ]);
});

it('adds one stopped AppInstance Schedule and renders exact json', function (): void {
    $payload = schedule_cli_payload([
        'desired_timer_state' => 'disabled',
        'status' => 'active',
    ]);
    $mock = MockClient::global([
        AddScheduleRequest::class => schedule_cli_response($payload, 201),
    ]);

    $this
        ->artisan('schedule:add', schedule_cli_add_arguments([
            '--instance' => '7',
            '--no-start' => true,
            '--json' => true,
        ]))
        ->expectsOutput(json_encode(
            ScheduleResponse::fromGatewayData($payload, schedule_cli_request_id())->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        ))
        ->assertExitCode(Command::SUCCESS);

    $mock->assertSentCount(1, AddScheduleRequest::class);
    expect($mock->getLastRequest()?->body()->all())
        ->toBe([
            'target_type' => 'instance',
            'target_id' => 7,
            'name' => 'daily-report',
            'calendar' => 'daily',
            'command' => 'php artisan report:send',
            'timeout_seconds' => 900,
            'start' => false,
        ]);
});

it('omits start when an AppInstance Schedule uses the default enabled state', function (): void {
    $mock = MockClient::global([
        AddScheduleRequest::class => schedule_cli_response(status: 201),
    ]);

    expect(Artisan::call('schedule:add', schedule_cli_add_arguments(['--instance' => '7'])))
        ->toBe(Command::SUCCESS);

    $mock->assertSentCount(1, AddScheduleRequest::class);
    expect($mock->getLastRequest()?->body()->all())->not->toHaveKey('start');
});

it('lists the unfiltered collection without command text in human output', function (): void {
    $item = schedule_cli_payload(includeCommand: false);
    $mock = MockClient::global([
        ListSchedulesRequest::class => MockResponse::make([
            'data' => [$item],
            'meta' => ['request_id' => schedule_cli_request_id()],
        ]),
    ]);

    $this
        ->artisan('schedule:list')
        ->expectsTable(
            ['UUID', 'Target', 'Name', 'Calendar', 'Desired timer', 'Lifecycle', 'Last run'],
            [[
                schedule_cli_uuid(),
                'instance:7',
                'daily-report',
                'daily',
                'disabled',
                'active',
                'never',
            ]],
        )
        ->expectsOutput('Request ID: '.schedule_cli_request_id())
        ->doesntExpectOutputToContain('php artisan report:send')
        ->assertExitCode(Command::SUCCESS);

    $mock->assertSentCount(1, ListSchedulesRequest::class);
    expect($mock->getLastRequest()?->query()->all())->toBe([]);
});

it('lists the exact typed collection in json without command text', function (): void {
    $item = schedule_cli_payload(includeCommand: false);
    $response = SchedulesResponse::fromGatewayData([$item], schedule_cli_request_id());
    $mock = MockClient::global([
        ListSchedulesRequest::class => MockResponse::make([
            'data' => [$item],
            'meta' => ['request_id' => schedule_cli_request_id()],
        ]),
    ]);

    $this
        ->artisan('schedule:list', ['--json' => true])
        ->expectsOutput(json_encode($response->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->doesntExpectOutputToContain('command')
        ->assertExitCode(Command::SUCCESS);

    $mock->assertSentCount(1, ListSchedulesRequest::class);
});

it('sends one UUID request and renders separate timer and lifecycle states', function (
    string $command,
    string $requestClass,
): void {
    $mock = MockClient::global([
        $requestClass => schedule_cli_response(),
    ]);

    $this
        ->artisan($command, ['schedule' => schedule_cli_uuid()])
        ->expectsTable(['Field', 'Value'], schedule_cli_item_rows())
        ->assertExitCode(Command::SUCCESS);

    $mock->assertSentCount(1, $requestClass);
    expect($mock->getLastPendingRequest()?->getUrl())->toBe(
        'https://10.44.0.1/api/v1/schedules/'.schedule_cli_uuid().schedule_cli_endpoint_suffix($command),
    );
})->with([
    'show' => ['schedule:show', ShowScheduleRequest::class],
    'run' => ['schedule:run', RunScheduleRequest::class],
    'remove' => ['schedule:remove', RemoveScheduleRequest::class],
    'activate' => ['schedule:activate', ActivateScheduleRequest::class],
]);

it('renders one UUID operation response in exact json', function (string $command, string $requestClass): void {
    $payload = schedule_cli_payload();
    $response = ScheduleResponse::fromGatewayData($payload, schedule_cli_request_id());
    $mock = MockClient::global([
        $requestClass => schedule_cli_response(),
    ]);

    $this
        ->artisan($command, ['schedule' => schedule_cli_uuid(), '--json' => true])
        ->expectsOutput(json_encode($response->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->assertExitCode(Command::SUCCESS);

    $mock->assertSentCount(1, $requestClass);
})->with([
    'show' => ['schedule:show', ShowScheduleRequest::class],
    'run' => ['schedule:run', RunScheduleRequest::class],
    'remove' => ['schedule:remove', RemoveScheduleRequest::class],
    'activate' => ['schedule:activate', ActivateScheduleRequest::class],
]);

it('renders only the bounded log output returned by the Gateway for humans', function (): void {
    $mock = MockClient::global([
        ScheduleLogsRequest::class => schedule_cli_logs_response(),
    ]);

    $this
        ->artisan('schedule:logs', ['schedule' => schedule_cli_uuid(), '--lines' => '25'])
        ->expectsOutput("first journald line\nsecond journald line")
        ->expectsOutput('Request ID: '.schedule_cli_request_id())
        ->doesntExpectOutputToContain('not returned')
        ->assertExitCode(Command::SUCCESS);

    $mock->assertSentCount(1, ScheduleLogsRequest::class);
    expect($mock->getLastRequest()?->query()->all())->toBe(['lines' => 25]);
});

it('renders the exact typed log response in json', function (): void {
    $data = schedule_cli_logs_payload();
    $response = ScheduleLogsResponse::fromGatewayData($data, schedule_cli_request_id());
    $mock = MockClient::global([
        ScheduleLogsRequest::class => schedule_cli_logs_response(),
    ]);

    $this
        ->artisan('schedule:logs', [
            'schedule' => schedule_cli_uuid(),
            '--lines' => '25',
            '--json' => true,
        ])
        ->expectsOutput(json_encode($response->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->assertExitCode(Command::SUCCESS);

    $mock->assertSentCount(1, ScheduleLogsRequest::class);
});

it('applies explicit selector validation before HTTP in every output and interaction mode', function (
    string $mode,
    array $arguments,
    string $code,
    string $message,
): void {
    $mock = MockClient::global();
    $arguments = schedule_cli_add_arguments($arguments);

    if ($mode === 'json') {
        $arguments['--json'] = true;
    }

    if ($mode === 'non-interactive') {
        $arguments['--no-interaction'] = true;
    }

    $exitCode = Artisan::call('schedule:add', $arguments);
    $output = trim(Artisan::output());

    expect($exitCode)->toBe(Command::FAILURE);
    expect($output)
        ->toContain($message)
        ->not->toContain('Select', 'Choose', '?');
    if ($mode === 'json') {
        expect(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe([
            'error' => [
                'code' => $code,
                'message' => $message,
                'request_id' => null,
            ],
        ]);
    }
    expect($mock->getLastPendingRequest())->toBeNull();
})->with(function (): array {
    $cases = [
        'neither selector' => [[], 'schedule.target_required', 'Exactly one of --node or --instance is required.'],
        'both selectors' => [[
            '--node' => '3',
            '--instance' => '7',
        ], 'schedule.target_conflict', 'Use only one of --node or --instance.'],
        'malformed Node ID' => [[
            '--node' => 'edge',
        ], 'schedule.node_id_invalid', 'Node ID must be a positive integer.'],
        'zero Node ID' => [[
            '--node' => '0',
        ], 'schedule.node_id_invalid', 'Node ID must be a positive integer.'],
        'negative AppInstance ID' => [[
            '--instance' => '-7',
        ], 'schedule.instance_id_invalid', 'AppInstance ID must be a positive integer.'],
        'malformed AppInstance ID' => [[
            '--instance' => '7.5',
        ], 'schedule.instance_id_invalid', 'AppInstance ID must be a positive integer.'],
        'Node with AppInstance option' => [[
            '--node' => '3',
            '--no-start' => true,
        ], 'schedule.option_invalid', 'The --no-start option requires --instance.'],
    ];
    $modes = ['interactive', 'non-interactive', 'json'];
    $datasets = [];

    foreach ($modes as $mode) {
        foreach ($cases as $case => [$arguments, $code, $message]) {
            $datasets["{$mode}: {$case}"] = [$mode, $arguments, $code, $message];
        }
    }

    return $datasets;
});

it('rejects malformed Schedule UUIDs and log bounds before HTTP', function (
    string $command,
    array $arguments,
    string $message,
): void {
    $mock = MockClient::global();

    expect(Artisan::call($command, [...$arguments, '--json' => true]))->toBe(Command::FAILURE);
    expect(trim(Artisan::output()))
        ->toContain($message)
        ->not->toContain('not-a-uuid');
    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    'show UUID' => ['schedule:show', ['schedule' => 'not-a-uuid'], 'Schedule UUID is invalid.'],
    'run UUID' => ['schedule:run', ['schedule' => 'not-a-uuid'], 'Schedule UUID is invalid.'],
    'logs UUID' => ['schedule:logs', ['schedule' => 'not-a-uuid'], 'Schedule UUID is invalid.'],
    'remove UUID' => ['schedule:remove', ['schedule' => 'not-a-uuid'], 'Schedule UUID is invalid.'],
    'activate UUID' => ['schedule:activate', ['schedule' => 'not-a-uuid'], 'Schedule UUID is invalid.'],
    'logs lower bound' => ['schedule:logs', [
        'schedule' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        '--lines' => '0',
    ], 'Log lines must be between 1 and 1000.'],
    'logs upper bound' => ['schedule:logs', [
        'schedule' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        '--lines' => '1001',
    ], 'Log lines must be between 1 and 1000.'],
]);

it('renders shared safe Gateway failures for every Schedule operation', function (
    string $command,
    string $requestClass,
    array $arguments,
): void {
    $requestId = '0198e15d-16c4-7855-8eb2-182b53ad28bb';
    $mock = MockClient::global([
        $requestClass => MockResponse::make([
            'error' => [
                'code' => 'schedule.unavailable',
                'message' => 'Schedule operation is unavailable.',
                'details' => ['command' => 'secret command'],
            ],
        ], 503, ['X-Orbit-Request-Id' => $requestId]),
    ]);

    expect(Artisan::call($command, [...$arguments, '--json' => true]))->toBe(Command::FAILURE);
    expect(json_decode(trim(Artisan::output()), associative: true, flags: JSON_THROW_ON_ERROR))->toBe([
        'error' => [
            'code' => 'schedule.unavailable',
            'message' => 'Schedule operation is unavailable.',
            'request_id' => $requestId,
        ],
    ]);
    expect(Artisan::output())->not->toContain('secret command', 'details');
    $mock->assertSentCount(1, $requestClass);
})->with([
    'add' => ['schedule:add', AddScheduleRequest::class, schedule_cli_add_arguments(['--node' => '3'])],
    'list' => ['schedule:list', ListSchedulesRequest::class, []],
    'show' => ['schedule:show', ShowScheduleRequest::class, ['schedule' => schedule_cli_uuid()]],
    'run' => ['schedule:run', RunScheduleRequest::class, ['schedule' => schedule_cli_uuid()]],
    'logs' => ['schedule:logs', ScheduleLogsRequest::class, ['schedule' => schedule_cli_uuid()]],
    'remove' => ['schedule:remove', RemoveScheduleRequest::class, ['schedule' => schedule_cli_uuid()]],
    'activate' => ['schedule:activate', ActivateScheduleRequest::class, ['schedule' => schedule_cli_uuid()]],
]);

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function schedule_cli_add_arguments(array $overrides = []): array
{
    return [
        'name' => 'daily-report',
        '--calendar' => 'daily',
        '--command' => 'php artisan report:send',
        '--timeout' => '900',
        ...$overrides,
    ];
}

/** @param array<string, mixed> $overrides */
function schedule_cli_response(array $overrides = [], int $status = 200): MockResponse
{
    return MockResponse::make([
        'data' => schedule_cli_payload($overrides),
        'meta' => ['request_id' => schedule_cli_request_id()],
    ], $status);
}

function schedule_cli_logs_response(): MockResponse
{
    return MockResponse::make([
        'data' => schedule_cli_logs_payload(),
        'meta' => ['request_id' => schedule_cli_request_id()],
    ]);
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function schedule_cli_payload(array $overrides = [], bool $includeCommand = true): array
{
    $data = [
        'id' => schedule_cli_uuid(),
        'target_type' => 'instance',
        'target_id' => 7,
        'name' => 'daily-report',
        'calendar' => 'daily',
        'timeout_seconds' => 900,
        'desired_timer_state' => 'disabled',
        'status' => 'active',
        'failed_step' => null,
        'error_code' => null,
        'last_run_at' => null,
        'last_run_status' => null,
    ];

    if ($includeCommand) {
        $data['command'] = 'php artisan report:send';
    }

    return [...$data, ...$overrides];
}

/** @return array{id:string,name:string,lines:int,output:string,truncated:bool} */
function schedule_cli_logs_payload(): array
{
    return [
        'id' => schedule_cli_uuid(),
        'name' => 'daily-report',
        'lines' => 25,
        'output' => "first journald line\nsecond journald line\n",
        'truncated' => false,
    ];
}

/** @param array<string, mixed> $overrides
 * @return list<array{string, int|string}>
 */
function schedule_cli_item_rows(array $overrides = []): array
{
    $data = schedule_cli_payload($overrides);

    return [
        ['UUID', (string) $data['id']],
        ['Target', "{$data['target_type']}:{$data['target_id']}"],
        ['Name', (string) $data['name']],
        ['Calendar', (string) $data['calendar']],
        ['Command', (string) $data['command']],
        ['Timeout seconds', (int) $data['timeout_seconds']],
        ['Desired timer state', (string) $data['desired_timer_state']],
        ['Lifecycle state', (string) $data['status']],
        ['Failed step', '—'],
        ['Error code', '—'],
        ['Last run at', '—'],
        ['Last run status', '—'],
        ['Request ID', schedule_cli_request_id()],
    ];
}

function schedule_cli_endpoint_suffix(string $command): string
{
    return match ($command) {
        'schedule:run' => '/run',
        'schedule:activate' => '/activate',
        default => '',
    };
}

function schedule_cli_uuid(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}

function schedule_cli_request_id(): string
{
    return '0198e15d-16c4-7855-8eb2-182b53ad28ba';
}
