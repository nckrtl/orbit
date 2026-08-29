<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Tools\ListToolManagersRequest;
use Orbit\Sdk\Requests\Tools\ListToolsRequest;
use Orbit\Sdk\Requests\Tools\ShowToolRequest;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-tools-'.Str::uuid();
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

it('renders the complete manager table and exact request id', function (): void {
    $id = '11111111-1111-4111-8111-111111111111';
    $mock = MockClient::global([
        ListToolManagersRequest::class => MockResponse::make([
            'data' => [
                manager_data(['id' => 1, 'name' => 'apt', 'installed_version' => '2.8.1']),
                manager_data(['id' => 2, 'name' => 'composer']),
                manager_data(['id' => 3, 'name' => 'vp', 'installed_version' => '1.4.0']),
                manager_data([
                    'id' => 4,
                    'name' => 'legacy',
                    'status' => 'failed',
                    'failed_step' => 'install',
                    'error_code' => 'manager.failed',
                ]),
            ],
            'meta' => ['request_id' => $id],
        ]),
    ]);
    $this
        ->artisan('tool:manager:list', ['--node' => 12])
        ->expectsTable(['ID', 'Manager', 'Status', 'Version', 'Failed step', 'Error'], [
            [1, 'apt',      'active', '2.8.1', '-',       '-'],
            [2, 'composer', 'active', '-',     '-',       '-'],
            [3, 'vp',       'active', '1.4.0', '-',       '-'],
            [4, 'legacy',   'failed', '-',     'install', 'manager.failed'],
        ])
        ->expectsOutput("Request ID: {$id}")
        ->assertSuccessful();
    expect($mock->getLastRequest()?->getMethod())
        ->toBe(Method::GET)
        ->and($mock->getLastPendingRequest()?->getUrl())
        ->toBe('https://10.44.0.1/api/v1/tool-managers')
        ->and($mock->getLastRequest()?->query()->all())
        ->toBe(['node_id' => 12]);
});

it('renders the complete tool table with null and protected values', function (): void {
    $id = '22222222-2222-4222-8222-222222222222';
    $mock = MockClient::global([
        ListToolsRequest::class => MockResponse::make([
            'data' => [
                tool_data(),
                tool_data([
                    'id' => 42,
                    'manager' => 'composer',
                    'package' => 'vendor/tool',
                    'version_constraint' => '^1.2',
                    'protected' => true,
                    'status' => 'failed',
                    'installed_version' => '1.3.0',
                    'error_code' => 'tool.failed',
                ]),
            ],
            'meta' => ['request_id' => $id],
        ]),
    ]);
    $this
        ->artisan('tool:list', ['--node' => 12])
        ->expectsTable(['ID', 'Manager', 'Package', 'Constraint', 'Status', 'Version', 'Protected', 'Error'], [
            [41, 'vp',       '@openai/codex', '-',    'installed', '-',     'no',  '-'],
            [42, 'composer', 'vendor/tool',   '^1.2', 'failed',    '1.3.0', 'yes', 'tool.failed'],
        ])
        ->expectsOutput("Request ID: {$id}")
        ->assertSuccessful();
    expect($mock->getLastRequest()?->getMethod())
        ->toBe(Method::GET)
        ->and($mock->getLastPendingRequest()?->getUrl())
        ->toBe('https://10.44.0.1/api/v1/tools')
        ->and($mock->getLastRequest()?->query()->all())
        ->toBe(['node_id' => 12]);
});

it('renders every show field in DTO order', function (): void {
    $id = '33333333-3333-4333-8333-333333333333';
    $data = tool_data(['protected' => true]);
    $mock = MockClient::global([
        ShowToolRequest::class => MockResponse::make(['data' => $data, 'meta' => ['request_id' => $id]]),
    ]);
    $this
        ->artisan('tool:show', ['tool' => 41])
        ->expectsTable(['Field', 'Value'], [
            ['id',                 41],
            ['node_id',            12],
            ['manager',            'vp'],
            ['package',            '@openai/codex'],
            ['version_constraint', '-'],
            ['protected',          'yes'],
            ['status',             'installed'],
            ['installed_version',  '-'],
            ['failed_operation',   '-'],
            ['error_code',         '-'],
            ['outcome',            '-'],
        ])
        ->expectsOutput("Request ID: {$id}")
        ->assertSuccessful();
    expect($mock->getLastRequest()?->getMethod())
        ->toBe(Method::GET)
        ->and($mock->getLastPendingRequest()?->getUrl())
        ->toBe('https://10.44.0.1/api/v1/tools/41');
});

it('writes exact one-line DTO JSON for all read commands', function (
    string $command,
    string $class,
    array $payload,
    string $url,
    array $query,
): void {
    $id = '44444444-4444-4444-8444-444444444444';
    $mock = MockClient::global([$class => MockResponse::make(['data' => $payload, 'meta' => ['request_id' => $id]])]);
    $args = $command === 'tool:show' ? ['tool' => 41, '--json' => true] : ['--node' => 12, '--json' => true];
    expect(Artisan::call($command, $args))->toBe(0);
    $expected = json_encode(
        $command === 'tool:show'
            ? [...$payload, 'request_id' => $id]
            : [$command === 'tool:list' ? 'tools' : 'managers' => $payload, 'request_id' => $id],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
    );
    expect(trim(Artisan::output()))
        ->toBe($expected)
        ->not
        ->toContain("\n")
        ->and($mock->getLastPendingRequest()?->getUrl())
        ->toBe($url)
        ->and($mock->getLastRequest()?->query()->all())
        ->toBe($query);
})->with([
    [
        'tool:manager:list',
        ListToolManagersRequest::class,
        [manager_data()],
        'https://10.44.0.1/api/v1/tool-managers',
        ['node_id' => 12],
    ],
    [
        'tool:list',
        ListToolsRequest::class,
        [tool_data()],
        'https://10.44.0.1/api/v1/tools',
        ['node_id' => 12],
    ],
    ['tool:show', ShowToolRequest::class, tool_data(), 'https://10.44.0.1/api/v1/tools/41', []],
]);

it('rejects invalid input with exact JSON and sends no request', function (
    string $command,
    array $args,
    string $code,
    string $message,
): void {
    $mock = MockClient::global();
    $exit = Artisan::call($command, [...$args, '--json' => true]);
    expect($exit)
        ->toBe(1)
        ->and(trim(Artisan::output()))
        ->toBe(json_encode(['error' => [
            'code' => $code,
            'message' => $message,
            'request_id' => null,
        ]], JSON_THROW_ON_ERROR))
        ->and($mock->getLastPendingRequest())
        ->toBeNull();
})->with([
    ['tool:manager:list', ['--node' => 0], 'tool.node_id_invalid', 'Node ID must be a positive integer.'],
    ['tool:list', ['--node' => 0], 'tool.node_id_invalid', 'Node ID must be a positive integer.'],
    ['tool:show', ['tool' => 0], 'tool.id_invalid', 'Tool ID must be a positive integer.'],
]);

/** @param array<string, int|string|null> $overrides */
function manager_data(array $overrides = []): array
{
    return array_replace([
        'id' => 1,
        'node_id' => 12,
        'name' => 'apt',
        'status' => 'active',
        'installed_version' => null,
        'failed_step' => null,
        'error_code' => null,
    ], $overrides);
}

/** @param array<string, bool|int|string|null> $overrides */
function tool_data(array $overrides = []): array
{
    return array_replace([
        'id' => 41,
        'node_id' => 12,
        'manager' => 'vp',
        'package' => '@openai/codex',
        'version_constraint' => null,
        'protected' => false,
        'status' => 'installed',
        'installed_version' => null,
        'failed_operation' => null,
        'error_code' => null,
        'outcome' => null,
    ], $overrides);
}
