<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Tools\InstallToolRequest;
use Orbit\Sdk\Requests\Tools\ListToolManagersRequest;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-install-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', '/tmp/ca.pem'));
});
afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

function install_payload(string $outcome = 'applied'): array
{
    return [
        'id' => 41,
        'node_id' => 12,
        'manager' => 'vp',
        'package' => '@openai/codex',
        'version_constraint' => null,
        'protected' => false,
        'status' => 'installed',
        'installed_version' => '0.150.0',
        'failed_operation' => null,
        'error_code' => null,
        'outcome' => $outcome,
    ];
}

it('prompts with active sorted managers and renders applied output', function (): void {
    $id = '11111111-1111-4111-8111-111111111111';
    $mock = MockClient::global([
        ListToolManagersRequest::class => MockResponse::make([
            'data' => [
                [
                    'id' => 1,
                    'node_id' => 12,
                    'name' => 'vp',
                    'status' => 'active',
                    'installed_version' => null,
                    'failed_step' => null,
                    'error_code' => null,
                ],
                [
                    'id' => 2,
                    'node_id' => 12,
                    'name' => 'apt',
                    'status' => 'active',
                    'installed_version' => null,
                    'failed_step' => null,
                    'error_code' => null,
                ],
                [
                    'id' => 3,
                    'node_id' => 12,
                    'name' => 'composer',
                    'status' => 'active',
                    'installed_version' => null,
                    'failed_step' => null,
                    'error_code' => null,
                ],
                [
                    'id' => 4,
                    'node_id' => 12,
                    'name' => 'broken',
                    'status' => 'failed',
                    'installed_version' => null,
                    'failed_step' => 'connect',
                    'error_code' => 'manager.unavailable',
                ],
            ],
            'meta' => ['request_id' => $id],
        ]),
        InstallToolRequest::class => MockResponse::make(['data' => install_payload(), 'meta' => ['request_id' => $id]]),
    ]);
    $this
        ->artisan('tool:install', ['--node' => 12])
        ->expectsChoice('Tool manager', 'vp', ['apt', 'composer', 'vp'])
        ->expectsQuestion('Package', '@openai/codex')
        ->expectsOutput('Tool [@openai/codex] installed with [vp].')
        ->expectsOutput("Request ID: {$id}")
        ->assertSuccessful();

    [$managerResponse, $installResponse] = $mock->getRecordedResponses();
    $managerRequest = $managerResponse->getPendingRequest();
    $installRequest = $installResponse->getPendingRequest();

    expect($managerRequest->getRequest())
        ->toBeInstanceOf(ListToolManagersRequest::class)
        ->and($managerRequest->getRequest()->getMethod())
        ->toBe(Method::GET)
        ->and($managerRequest->getUrl())
        ->toBe('https://10.44.0.1/api/v1/tool-managers')
        ->and($managerRequest->query()->all())
        ->toBe(['node_id' => 12])
        ->and($installRequest->getRequest())
        ->toBeInstanceOf(InstallToolRequest::class)
        ->and($installRequest->getRequest()->getMethod())
        ->toBe(Method::POST)
        ->and($installRequest->getUrl())
        ->toBe('https://10.44.0.1/api/v1/tools')
        ->and($installRequest->body()->all())
        ->toBe([
            'node_id' => 12,
            'manager' => 'vp',
            'package' => '@openai/codex',
        ]);
});

it('sends supplied manager and constraint without manager lookup', function (): void {
    $id = '33333333-3333-4333-8333-333333333333';
    $mock = MockClient::global([
        InstallToolRequest::class => MockResponse::make(['data' => install_payload(), 'meta' => ['request_id' => $id]]),
    ]);
    $this->artisan('tool:install', [
        'package' => '@openai/codex',
        '--node' => 12,
        '--manager' => 'unlisted',
        '--constraint' => '^1.2',
        '--json' => true,
    ])->assertSuccessful();
    $request = $mock->getLastPendingRequest();
    expect($request?->getMethod()->value)
        ->toBe('POST')
        ->and($request?->getUrl())
        ->toBe('https://10.44.0.1/api/v1/tools')
        ->and($request?->body()->all())
        ->toBe([
            'node_id' => 12,
            'manager' => 'unlisted',
            'package' => '@openai/codex',
            'version_constraint' => '^1.2',
        ]);
});

it('rejects missing manager and package in JSON mode before HTTP', function (): void {
    $mock = MockClient::global();
    $this->artisan('tool:install', ['--node' => 12, 'package' => 'pkg', '--json' => true])->assertExitCode(1);
    $this->artisan('tool:install', ['--node' => 12, '--manager' => 'vp', '--json' => true])->assertExitCode(1);
    expect($mock->getLastPendingRequest())->toBeNull();
});

it('rejects empty, control, and oversized packages before HTTP', function (string $package): void {
    $mock = MockClient::global();
    $this->artisan('tool:install', ['package' => $package, '--node' => 12, '--manager' => 'vp'])->assertExitCode(1);
    expect($mock->getLastPendingRequest())->toBeNull();
})->with([
    'empty' => '',
    'control' => "bad\tname",
    'oversized' => str_repeat(string: 'x', times: 256),
]);

it('passes supplied manager through and writes unchanged JSON', function (): void {
    $id = '22222222-2222-4222-8222-222222222222';
    MockClient::global([
        InstallToolRequest::class => MockResponse::make([
            'data' => install_payload('unchanged'),
            'meta' => ['request_id' => $id],
        ]),
    ]);
    expect(Artisan::call('tool:install', [
        'package' => '@openai/codex',
        '--node' => 12,
        '--manager' => 'custom',
        '--json' => true,
    ]))
        ->toBe(0);
    expect(trim(Artisan::output()))->toBe(json_encode(array_merge(install_payload('unchanged'), [
        'request_id' => $id,
    ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
});

it('renders unchanged human output with its request ID', function (): void {
    $id = '88888888-8888-4888-8888-888888888888';
    MockClient::global([
        InstallToolRequest::class => MockResponse::make([
            'data' => install_payload('unchanged'),
            'meta' => ['request_id' => $id],
        ]),
    ]);

    $this
        ->artisan('tool:install', [
            'package' => '@openai/codex',
            '--node' => 12,
            '--manager' => 'vp',
        ])
        ->expectsOutput('Tool [@openai/codex] is already installed with [vp].')
        ->expectsOutput("Request ID: {$id}")
        ->assertSuccessful();
});

it('rejects missing inputs invalid packages and nodes before HTTP', function (): void {
    $mock = MockClient::global();
    $this->artisan('tool:install', ['--node' => 12, '--json' => true])->assertExitCode(1);
    $this->artisan('tool:install', ['--node' => 12, '--manager' => 'vp', 'package' => "bad\nname"])->assertExitCode(1);
    $this->artisan('tool:install', ['--node' => 0, '--manager' => 'vp', 'package' => 'pkg'])->assertExitCode(1);
    expect($mock->getLastPendingRequest())->toBeNull();
});

it('uses noninteractive manager and package rules without prompting', function (): void {
    $mock = MockClient::global();

    $missingManager = app(\App\Commands\Tools\InstallToolCommand::class);
    $missingManager->setLaravel(app());
    $managerTester = new CommandTester($missingManager);
    $managerTester->execute(['--node' => '12'], ['interactive' => false]);

    $missingPackage = app(\App\Commands\Tools\InstallToolCommand::class);
    $missingPackage->setLaravel(app());
    $packageTester = new CommandTester($missingPackage);
    $packageTester->execute(['--node' => '12', '--manager' => 'vp'], ['interactive' => false]);

    expect($managerTester->getStatusCode())
        ->toBe(1)
        ->and($managerTester->getDisplay())
        ->toContain('Tool manager is required.')
        ->and($packageTester->getStatusCode())
        ->toBe(1)
        ->and($packageTester->getDisplay())
        ->toContain('Package is required.');
    $mock->assertNothingSent();
});

it('renders manager lookup failures safely and does not install', function (): void {
    $id = '44444444-4444-4444-8444-444444444444';
    $mock = MockClient::global([
        ListToolManagersRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'tool.manager_unavailable',
                    'message' => 'Manager unavailable.',
                    'details' => ['manager_output' => 'validation-secret'],
                ],
            ],
            503,
            ['X-Orbit-Request-Id' => $id],
        ),
    ]);
    $this
        ->artisan('tool:install', ['--node' => 12])
        ->expectsOutput('Manager unavailable.')
        ->expectsOutput("Request ID: {$id}")
        ->assertExitCode(1);
    $mock->assertSentCount(1, ListToolManagersRequest::class);
});

it('returns manager required when no active manager rows exist', function (): void {
    $mock = MockClient::global([
        ListToolManagersRequest::class => MockResponse::make([
            'data' => [],
            'meta' => ['request_id' => '55555555-5555-4555-8555-555555555555'],
        ]),
    ]);
    $this
        ->artisan('tool:install', ['--node' => 12])
        ->expectsOutput('No active tool manager is available.')
        ->assertExitCode(1);
    $mock->assertSentCount(1, ListToolManagersRequest::class);
});

it('rejects unsupported successful outcomes as invalid response JSON', function (): void {
    $id = '66666666-6666-4666-8666-666666666666';
    MockClient::global([
        InstallToolRequest::class => MockResponse::make([
            'data' => install_payload('blocked_by_constraint'),
            'meta' => ['request_id' => $id],
        ]),
    ]);
    $this
        ->artisan('tool:install', ['package' => 'pkg', '--node' => 12, '--manager' => 'vp', '--json' => true])
        ->expectsOutput(json_encode(['error' => [
            'code' => 'gateway.invalid_response',
            'message' => 'Gateway response is invalid.',
            'request_id' => $id,
        ]], JSON_THROW_ON_ERROR))
        ->assertExitCode(1);
});

it('renders both constraint failures as one line JSON envelopes', function (string $code, string $message): void {
    $id = '77777777-7777-4777-8777-777777777777';
    MockClient::global([
        InstallToolRequest::class => MockResponse::make(['error' => ['code' => $code, 'message' => $message]], 422, [
            'X-Orbit-Request-Id' => $id,
        ]),
    ]);
    $this
        ->artisan('tool:install', ['package' => 'pkg', '--node' => 12, '--manager' => 'vp', '--json' => true])
        ->expectsOutput(json_encode(['error' => [
            'code' => $code,
            'message' => $message,
            'request_id' => $id,
        ]], JSON_THROW_ON_ERROR))
        ->assertExitCode(1);
})->with([
    ['tool.constraint_invalid',         'Tool version constraint is invalid.'],
    ['tool.version_constraint_blocked', 'Tool install blocked by the version constraint.'],
]);
