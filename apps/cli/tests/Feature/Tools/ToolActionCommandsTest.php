<?php

declare(strict_types=1);

use App\Commands\Tools\RemoveToolCommand;
use App\Commands\Tools\UpdateToolCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Support\Console\ProgressOutcome;
use App\Support\Console\ProgressState;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Tools\RemoveToolRequest;
use Orbit\Sdk\Requests\Tools\ShowToolRequest;
use Orbit\Sdk\Requests\Tools\UpdateToolRequest;
use Orbit\Sdk\Responses\Tools\ToolResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->previousColumns = getenv('COLUMNS');
    putenv('COLUMNS=200');
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-tool-action-'.Str::uuid();
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
    if ($this->previousColumns === false) {
        putenv('COLUMNS');
    } else {
        putenv('COLUMNS='.$this->previousColumns);
    }
});

it('rejects a blocked update with no constraint as an invalid response', function (): void {
    MockClient::global([
        UpdateToolRequest::class => MockResponse::make([
            'data' => tool_action_data(['package' => 'curl', 'manager' => 'apt', 'outcome' => 'blocked_by_constraint']),
            'meta' => ['request_id' => '77777777-7777-4777-8777-777777777777'],
        ]),
    ]);
    $this
        ->artisan('tool:update', ['tool' => '41'])
        ->expectsOutput('Gateway response is invalid.')
        ->expectsOutput('Request ID: 77777777-7777-4777-8777-777777777777')
        ->doesntExpectOutputToContain('Updated Tool.')
        ->assertExitCode(1);
});

it('writes exact update DTO JSON', function (): void {
    $id = '88888888-8888-4888-8888-888888888888';
    MockClient::global([
        UpdateToolRequest::class => MockResponse::make([
            'data' => tool_action_data([
                'version_constraint' => '^0.150',
                'installed_version' => '0.151.0',
                'outcome' => 'applied',
            ]),
            'meta' => ['request_id' => $id],
        ]),
    ]);
    $this
        ->artisan('tool:update', ['tool' => '41', '--json' => true])
        ->expectsOutput(json_encode([
            ...tool_action_data([
                'version_constraint' => '^0.150',
                'installed_version' => '0.151.0',
                'outcome' => 'applied',
            ]),
            'request_id' => $id,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->doesntExpectOutput('Tool [')
        ->assertSuccessful();
});

it('renders JSON constraint failures with exact codes and request IDs', function (): void {
    foreach ([
        ['tool.version_constraint_blocked', 'Tool update blocked by the version constraint.'],
        ['tool.constraint_invalid',         'Tool version constraint is invalid.'],
    ] as [$code, $message]) {
        $id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        MockClient::destroyGlobal();
        MockClient::global([
            UpdateToolRequest::class => MockResponse::make(
                [
                    'error' => ['code' => $code, 'message' => $message],
                ],
                422,
                ['X-Orbit-Request-Id' => $id],
            ),
        ]);
        $this
            ->artisan('tool:update', ['tool' => '41', '--json' => true])
            ->expectsOutput(json_encode(['error' => [
                'code' => $code,
                'message' => $message,
                'request_id' => $id,
            ]], JSON_THROW_ON_ERROR))
            ->assertExitCode(1);
    }
});

it('updates a tool and renders its request ID in the detail tree', function (): void {
    $mock = MockClient::global([
        UpdateToolRequest::class => MockResponse::make(
            [
                'data' => tool_action_data(['version_constraint' => '^0.150', 'installed_version' => '0.151.0', 'outcome' => 'applied']),
                'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
            ],
            200,
            ['X-Request-Id' => 'req-update-41'],
        ),
    ]);

    [$exit, $output] = tool_action_cli_display('tool:update', ['tool' => '41']);

    expect($exit)->toBe(0)
        ->and($output)->toContain('Tool [@openai/codex] updated.')
        ->and($output)->toContain('11111111-1111-4111-8111-111111111111');

    expect($mock->getLastRequest())
        ->toBeInstanceOf(UpdateToolRequest::class)
        ->and($mock->getLastRequest()?->getMethod())
        ->toBe(Method::POST)
        ->and($mock->getLastPendingRequest()?->getUrl())
        ->toBe('https://10.44.0.1/api/v1/tools/41/update')
        ->and($mock->getLastPendingRequest()?->query()->all())
        ->toBeEmpty()
        ->and($mock->getLastPendingRequest()?->body())
        ->toBeNull();
});

it('removes a tool as one typed request and writes one DTO JSON line', function (): void {
    $mock = MockClient::global([
        RemoveToolRequest::class => MockResponse::make(
            [
                'data' => tool_action_data(['manager' => 'apt', 'package' => 'curl', 'status' => 'removed', 'outcome' => 'applied']),
                'meta' => ['request_id' => '22222222-2222-4222-8222-222222222222'],
            ],
            200,
            ['X-Request-Id' => 'req-remove-41'],
        ),
    ]);

    $this
        ->artisan('tool:remove', ['tool' => '41', '--yes' => true, '--json' => true])
        ->expectsOutput(json_encode([
            ...tool_action_data(['manager' => 'apt', 'package' => 'curl', 'status' => 'removed', 'outcome' => 'applied']),
            'request_id' => '22222222-2222-4222-8222-222222222222',
        ], JSON_UNESCAPED_SLASHES))
        ->assertSuccessful();

    expect($mock->getLastRequest())
        ->toBeInstanceOf(RemoveToolRequest::class)
        ->and($mock->getLastRequest()?->getMethod())
        ->toBe(Method::DELETE)
        ->and($mock->getLastPendingRequest()?->getUrl())
        ->toBe('https://10.44.0.1/api/v1/tools/41')
        ->and($mock->getLastPendingRequest()?->query()->all())
        ->toBeEmpty()
        ->and($mock->getLastPendingRequest()?->body())
        ->toBeNull();
});

it('resolves the Tool before refusing JSON removal without --yes', function (): void {
    $mock = MockClient::global([
        ShowToolRequest::class => MockResponse::make([
            'data' => tool_action_data(['manager' => 'apt', 'package' => 'curl']),
            'meta' => ['request_id' => '99999999-9999-4999-8999-999999999999'],
        ]),
    ]);

    $exitCode = Artisan::call('tool:remove', ['tool' => '41', '--json' => true]);

    expect($exitCode)
        ->toBe(1)
        ->and(json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR))
        ->toBe([
            'error' => [
                'code' => 'input.confirmation_required',
                'message' => 'Supply --yes to confirm this operation.',
                'request_id' => null,
            ],
        ]);

    $mock->assertSent(ShowToolRequest::class);
    $mock->assertNotSent(RemoveToolRequest::class);
});

it('fails with the not-found code before any prompt for an unknown Tool', function (): void {
    $mock = MockClient::global([
        ShowToolRequest::class => MockResponse::make(
            ['error' => ['code' => 'tool.not_found', 'message' => 'The tool was not found.']],
            404,
            ['X-Orbit-Request-Id' => '88888888-8888-4888-8888-888888888888'],
        ),
    ]);

    $exitCode = Artisan::call('tool:remove', ['tool' => '999', '--json' => true]);

    expect($exitCode)
        ->toBe(1)
        ->and(json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR))
        ->toBe([
            'error' => [
                'code' => 'tool.not_found',
                'message' => 'The tool was not found.',
                'request_id' => '88888888-8888-4888-8888-888888888888',
            ],
        ]);

    $mock->assertSent(ShowToolRequest::class);
    $mock->assertNotSent(RemoveToolRequest::class);
});

it('rejects a non-positive tool ID before sending HTTP', function (): void {
    $mock = MockClient::global();

    $this
        ->artisan('tool:update', ['tool' => '0'])
        ->expectsOutput('Tool ID must be a positive integer.')
        ->assertExitCode(1);
    $this
        ->artisan('tool:remove', ['tool' => '-1'])
        ->expectsOutput('Tool ID must be a positive integer.')
        ->assertExitCode(1);

    expect($mock->getLastRequest())->toBeNull();
});

it('renders unchanged and constraint-blocked update outcomes exactly', function (): void {
    foreach ([
        ['unchanged', 'Tool [curl] is already current.', '33333333-3333-4333-8333-333333333333'],
        [
            'blocked_by_constraint',
            'Tool [curl] update blocked by constraint [^8].',
            '44444444-4444-4444-8444-444444444444',
        ],
    ] as [$outcome, $message, $requestId]) {
        MockClient::destroyGlobal();
        MockClient::global([
            UpdateToolRequest::class => MockResponse::make([
                'data' => tool_action_data([
                    'manager' => 'apt',
                    'package' => 'curl',
                    'version_constraint' => '^8',
                    'installed_version' => '8.0',
                    'outcome' => $outcome,
                ]),
                'meta' => ['request_id' => $requestId],
            ]),
        ]);

        [$exit, $output] = tool_action_cli_display('tool:update', ['tool' => '41']);
        $flat = preg_replace('/[ \t]+/', ' ', $output) ?? $output;

        expect($exit)->toBe(0)
            ->and($output)->toContain($message)
            ->and($flat)->toContain("Request ID {$requestId}");
    }
});

it('classifies Tool action outcomes into truthful progress states', function (): void {
    $update = app(UpdateToolCommand::class);
    $remove = app(RemoveToolCommand::class);
    $updateState = new ReflectionMethod($update, 'resultState');
    $removeState = new ReflectionMethod($remove, 'resultState');

    $tool = static fn (array $overrides): ToolResponse => ToolResponse::fromGatewayData(
        tool_action_data($overrides),
        '11111111-1111-4111-8111-111111111111',
    );

    $unchanged = $updateState->invoke($update, $tool(['outcome' => 'unchanged']));
    $blocked = $updateState->invoke($update, $tool(['outcome' => 'blocked_by_constraint', 'version_constraint' => '^8']));

    expect($updateState->invoke($update, $tool(['outcome' => 'applied'])))
        ->toBe(ProgressState::Success)
        ->and($unchanged)->toBeInstanceOf(ProgressOutcome::class)
        ->and($unchanged->state)->toBe(ProgressState::Skipped)
        ->and($unchanged->footer)->not->toBeEmpty()
        ->and($blocked)->toBeInstanceOf(ProgressOutcome::class)
        ->and($blocked->state)->toBe(ProgressState::Warning)
        ->and($blocked->footer)->not->toBeEmpty()
        ->and($removeState->invoke($remove, $tool(['outcome' => 'applied'])))
        ->toBe(ProgressState::Success);
});

it('settles the progress row and footer truthfully for every update outcome', function (): void {
    foreach ([
        ['applied', '● Updated Tool', 'Updated Tool.'],
        ['unchanged', '● Update Tool', 'Tool already up to date.'],
        ['blocked_by_constraint', '● Updated Tool', 'Update blocked by constraint.'],
    ] as [$outcome, $row, $footer]) {
        MockClient::destroyGlobal();
        MockClient::global([
            UpdateToolRequest::class => MockResponse::make([
                'data' => tool_action_data([
                    'manager' => 'apt',
                    'package' => 'curl',
                    'version_constraint' => '^8',
                    'outcome' => $outcome,
                ]),
                'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
            ]),
        ]);

        [$exit, $output] = tool_action_cli_display('tool:update', ['tool' => '41']);

        expect($exit)->toBe(0)
            ->and($output)->toContain($row)
            ->and($output)->toContain($footer);
    }

    MockClient::destroyGlobal();
    MockClient::global([
        UpdateToolRequest::class => MockResponse::make([
            'data' => tool_action_data(['manager' => 'apt', 'package' => 'curl', 'outcome' => 'unexpected']),
            'meta' => ['request_id' => '22222222-2222-4222-8222-222222222222'],
        ]),
    ]);

    [$exit, $output] = tool_action_cli_display('tool:update', ['tool' => '41']);

    expect($exit)->toBe(1)
        ->and($output)->toContain('Operation failed.')
        ->and($output)->not->toContain('Updated Tool.')
        ->and($output)->not->toContain('Update Tool.');
});

it('renders a successful remove of a failed version-probe tool', function (string $failedOperation): void {
    MockClient::global([
        RemoveToolRequest::class => MockResponse::make([
            'data' => tool_action_data([
                'id' => 110,
                'manager' => 'brew',
                'package' => 'not-a-formula',
                'status' => 'failed',
                'failed_operation' => $failedOperation,
                'error_code' => 'tool.version_probe_failed',
                'outcome' => 'applied',
            ]),
            'meta' => ['request_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'],
        ]),
    ]);

    [$exit, $output] = tool_action_cli_display('tool:remove', ['tool' => '110', '--yes' => true]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('Tool [not-a-formula] removed.')
        ->and($output)->toContain('cccccccc-cccc-4ccc-8ccc-cccccccccccc');
})->with([
    'install' => ['install'],
    'remove' => ['remove'],
    'update' => ['update'],
]);

it('renders the exact human remove message and request ID', function (): void {
    MockClient::global([
        RemoveToolRequest::class => MockResponse::make([
            'data' => tool_action_data(['manager' => 'apt', 'package' => 'curl', 'status' => 'removed', 'outcome' => 'applied']),
            'meta' => ['request_id' => '55555555-5555-4555-8555-555555555555'],
        ]),
    ]);

    [$exit, $output] = tool_action_cli_display('tool:remove', ['tool' => '41', '--yes' => true]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('Tool [curl] removed.')
        ->and($output)->toContain('55555555-5555-4555-8555-555555555555');
});

it('makes no mutation and keeps the retained Tool row when removal is refused', function (): void {
    $mock = MockClient::global([
        ShowToolRequest::class => MockResponse::make([
            'data' => tool_action_data(['manager' => 'apt', 'package' => 'curl']),
            'meta' => ['request_id' => '99999999-9999-4999-8999-999999999999'],
        ]),
    ]);

    [$exit, $output] = tool_action_cli_display('tool:remove', ['tool' => '41']);

    expect($exit)->toBe(1)
        ->and($output)->toContain('Supply --yes to confirm this operation.');
    $mock->assertSent(ShowToolRequest::class);
    $mock->assertNotSent(RemoveToolRequest::class);
});

it('rejects nonnumeric IDs for both actions before HTTP', function (): void {
    $mock = MockClient::global();
    foreach (['tool:update', 'tool:remove'] as $command) {
        $this->artisan($command, ['tool' => 'abc'])->assertExitCode(1);
    }
    expect($mock->getLastRequest())->toBeNull();
});

it('renders invalid successful outcomes with the response request ID', function (): void {
    MockClient::global([
        UpdateToolRequest::class => MockResponse::make([
            'data' => tool_action_data(['manager' => 'apt', 'package' => 'curl', 'outcome' => 'unexpected']),
            'meta' => ['request_id' => '66666666-6666-4666-8666-666666666666'],
        ]),
    ]);
    $this
        ->artisan('tool:update', ['tool' => '41'])
        ->expectsOutput('Gateway response is invalid.')
        ->expectsOutput('Request ID: 66666666-6666-4666-8666-666666666666')
        ->assertExitCode(1);
});

it('rejects an invalid successful remove outcome', function (): void {
    MockClient::global([
        RemoveToolRequest::class => MockResponse::make([
            'data' => tool_action_data(['manager' => 'apt', 'package' => 'curl', 'outcome' => 'unexpected']),
            'meta' => ['request_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'],
        ]),
    ]);
    $this
        ->artisan('tool:remove', ['tool' => '41', '--yes' => true])
        ->expectsOutput('Gateway response is invalid.')
        ->expectsOutput('Request ID: bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')
        ->assertExitCode(1);
});

/**
 * @param  array<string, mixed>  $arguments
 * @return array{0: int, 1: string}
 */
function tool_action_cli_display(string $command, array $arguments = []): array
{
    $tester = new CommandTester(app(Kernel::class)->all()[$command]);

    return [$tester->execute($arguments, ['interactive' => false]), $tester->getDisplay(true)];
}

/** @param array<string, bool|int|string|null> $overrides */
function tool_action_data(array $overrides = []): array
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
