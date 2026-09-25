<?php

declare(strict_types=1);

use App\Commands\Tools\InstallToolCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Tools\InstallToolRequest;
use Orbit\Sdk\Responses\Tools\ToolManagerResponse;
use Orbit\Sdk\Responses\Tools\ToolManagersResponse;
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

    $tester = new CommandTester(app(Kernel::class)->all()['tool:install']);
    $exit = $tester->execute([
        'package' => '@openai/codex',
        '--node' => 12,
        '--manager' => 'vp',
    ], ['interactive' => false]);
    $output = $tester->getDisplay(true);
    $flat = preg_replace('/[ \t]+/', ' ', $output) ?? $output;

    expect($exit)->toBe(0)
        ->and($output)->toContain('Tool [@openai/codex] is already installed with [vp].')
        ->and($output)->toContain('Tool already installed.')
        ->and($output)->toContain('● Install Tool')
        ->and($output)->not->toContain('● Installed Tool')
        ->and($flat)->toContain("Request ID {$id}");
});

it('does not settle the row as installed before an invalid outcome fails', function (): void {
    $id = '55555555-5555-4555-8555-555555555555';
    MockClient::global([
        InstallToolRequest::class => MockResponse::make([
            'data' => install_payload('blocked_by_constraint'),
            'meta' => ['request_id' => $id],
        ]),
    ]);

    $tester = new CommandTester(app(Kernel::class)->all()['tool:install']);
    $exit = $tester->execute([
        'package' => '@openai/codex',
        '--node' => 12,
        '--manager' => 'vp',
    ], ['interactive' => false]);
    $output = $tester->getDisplay(true);

    expect($exit)->toBe(1)
        ->and($output)->toContain('Operation failed.')
        ->and($output)->toContain('Gateway response is invalid.')
        ->and($output)->not->toContain('Installed Tool.');
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

    $missingManager = app(InstallToolCommand::class);
    $missingManager->setLaravel(app());
    $managerTester = new CommandTester($missingManager);
    $managerTester->execute(['--node' => '12'], ['interactive' => false]);

    $missingPackage = app(InstallToolCommand::class);
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

it('surfaces the persisted tool id from a version-probe failure', function (): void {
    $id = '99999999-9999-4999-8999-999999999999';
    MockClient::global([
        InstallToolRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'tool.version_probe_failed',
                    'message' => 'The tool manager operation failed.',
                    'details' => [
                        'step' => 'install',
                        'outcome' => 'manager_failed',
                        'id' => 110,
                        'manager_output' => 'validation-secret',
                    ],
                ],
            ],
            502,
            ['X-Orbit-Request-Id' => $id],
        ),
    ]);

    $this
        ->artisan('tool:install', [
            'package' => 'totally-fake',
            '--node' => 12,
            '--manager' => 'brew',
            '--json' => true,
        ])
        ->expectsOutput(json_encode(['error' => [
            'code' => 'tool.version_probe_failed',
            'message' => 'The tool manager operation failed.',
            'details' => ['id' => 110, 'step' => 'install', 'outcome' => 'manager_failed'],
            'request_id' => $id,
        ]], JSON_THROW_ON_ERROR))
        ->doesntExpectOutputToContain('validation-secret')
        ->doesntExpectOutputToContain('manager_output')
        ->assertExitCode(1);
});

it('prints the persisted tool id for a human version-probe failure', function (): void {
    $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    MockClient::global([
        InstallToolRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'tool.version_probe_failed',
                    'message' => 'The tool manager operation failed.',
                    'details' => [
                        'step' => 'install',
                        'outcome' => 'manager_failed',
                        'id' => 110,
                    ],
                ],
            ],
            502,
            ['X-Orbit-Request-Id' => $id],
        ),
    ]);

    $this
        ->artisan('tool:install', [
            'package' => 'totally-fake',
            '--node' => 12,
            '--manager' => 'brew',
        ])
        ->expectsOutput('The tool manager operation failed.')
        ->expectsOutput('id: 110')
        ->expectsOutput('step: install')
        ->expectsOutput('outcome: manager_failed')
        ->expectsOutput("Request ID: {$id}")
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

it('excludes a failed manager from the install data list', function (): void {
    $managers = new ToolManagersResponse([
        tool_manager_response('apt', 'active'),
        tool_manager_response('composer', 'uninstalled'),
        tool_manager_response('brew', 'failed'),
    ], '11111111-1111-4111-8111-111111111111');

    $method = new ReflectionMethod(InstallToolCommand::class, 'eligibleManagerRows');
    $rows = $method->invoke(null, $managers);

    expect($rows)->toHaveKeys(['apt', 'composer'])
        ->and($rows)->not->toHaveKey('brew');
});

it('reports no supported manager when every manager is ineligible', function (): void {
    $managers = new ToolManagersResponse([
        tool_manager_response('brew', 'failed'),
    ], '11111111-1111-4111-8111-111111111111');

    $method = new ReflectionMethod(InstallToolCommand::class, 'eligibleManagerRows');
    $rows = $method->invoke(null, $managers);

    expect($rows)->toBe([]);
});

function tool_manager_response(string $name, string $status): ToolManagerResponse
{
    return new ToolManagerResponse(
        id: 1,
        nodeId: 12,
        name: $name,
        status: $status,
        installedVersion: null,
        failedStep: $status === 'failed' ? 'install' : null,
        errorCode: null,
        requestId: '11111111-1111-4111-8111-111111111111',
    );
}
