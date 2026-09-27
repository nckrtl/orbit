<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Extensions\EnableExtensionRequest;
use Orbit\Sdk\Requests\Extensions\ListExtensionsRequest;
use Orbit\Sdk\Requests\Tasks\ListTaskGroupsRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    extension_commands_reset_discovery();
    $this->callerOrbitHome = config('orbit.home');
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-extensions-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
    app()->forgetInstance(GatewayConfigRepository::class);
    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test', url: 'https://10.44.0.1', caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    extension_commands_reset_discovery();
    new Filesystem()->deleteDirectory($this->orbitHome);
    config()->set('orbit.home', $this->callerOrbitHome);
    app()->forgetInstance(GatewayConfigRepository::class);
});

it('follows the gateway switch through the public command kernel', function (): void {
    $mock = MockClient::global([
        ListExtensionsRequest::class => MockResponse::make([
            'data' => ['tasks' => false, 'proxycli' => false], 'meta' => ['request_id' => 'list-id'],
        ]),
        EnableExtensionRequest::class => MockResponse::make([
            'data' => ['name' => 'tasks', 'enabled' => true], 'meta' => ['request_id' => 'enable-id'],
        ]),
    ]);

    $enableExit = Artisan::call('extension:enable', [
        'extension' => 'tasks',
        '--json' => true,
    ]);
    $enableOutput = Artisan::output();
    expect($enableExit)->toBe(0)
        ->and(trim($enableOutput))->toBe('{"extension":"tasks","enabled":true}')
        ->and($enableOutput)->not->toContain('Orbit extension');

    [$listExit, $listOutput] = extension_commands_run(['list', '--format=json']);
    $descriptor = json_decode($listOutput, true, flags: JSON_THROW_ON_ERROR);
    $names = array_column($descriptor['commands'], 'name');
    $taskCommands = array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, 'tasks:')));

    expect($listExit)->toBe(0)
        ->and($taskCommands)->toBe(['tasks:status'])
        ->and($names)->not->toContain('proxycli:status', 'proxycli:setup', 'proxycli:teardown');

    [$defaultExit, $defaultOutput] = extension_commands_run([]);
    expect($defaultExit)->toBe(0)
        ->and($defaultOutput)->toContain('tasks:status')
        ->not->toContain('tasks:create', 'proxycli:setup', 'proxycli:teardown');

    foreach ([['list', '--raw'], ['list', '--format=xml']] as $arguments) {
        [, $output] = extension_commands_run($arguments);
        expect($output)->not->toContain('tasks:create', 'tasks:list', 'proxycli:setup', 'proxycli:teardown');
    }

    foreach ([['help', 'tasks:list'], ['tasks:list', '--help']] as $arguments) {
        [, $output] = extension_commands_run($arguments);
        expect($output)->not->toContain('List task groups.', '--project=', '--status=');
    }

    $mock->assertSentCount(1, ListExtensionsRequest::class);
    $mock->assertSentCount(1, EnableExtensionRequest::class);
    expect($mock->findResponseByRequest(EnableExtensionRequest::class)?->getPendingRequest()->getUrl())
        ->toBe('https://10.44.0.1/api/v1/extensions/tasks/enable');
});

function extension_commands_reset_discovery(): void
{
    $service = 'App\\Services\\Extensions\\GatewayExtensionState';

    if (class_exists($service)) {
        $service::reset();
    }
}

/** @param list<string> $arguments @return array{int, string} */
function extension_commands_run(array $arguments): array
{
    $output = new BufferedOutput;
    $status = app(ConsoleKernel::class)->handle(
        new StringInput(implode(' ', array_map('escapeshellarg', $arguments))),
        $output,
    );

    return [$status, $output->fetch()];
}

it('renders Gateway extension state as JSON', function (): void {
    MockClient::global([
        ListExtensionsRequest::class => MockResponse::make([
            'data' => ['tasks' => true, 'proxycli' => false], 'meta' => ['request_id' => 'list-id'],
        ]),
    ]);

    $exit = Artisan::call('extension:list', ['--json' => true]);
    $output = Artisan::output();
    expect($exit)->toBe(0)
        ->and(trim($output))->toBe('{"extensions":[{"extension":"tasks","enabled":true},{"extension":"proxycli","enabled":false}]}');
});

it('renders Gateway extension state as a human table', function (): void {
    MockClient::global([
        ListExtensionsRequest::class => MockResponse::make([
            'data' => ['tasks' => true, 'proxycli' => false], 'meta' => ['request_id' => 'list-id'],
        ]),
    ]);

    $exit = Artisan::call('extension:list');
    $output = Artisan::output();
    expect($exit)->toBe(0)
        ->and($output)->toContain('EXTENSION', 'enabled', 'disabled');
});

it('preserves correlation and safe details for a Gateway switch refusal', function (): void {
    $requestId = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
    MockClient::global([
        EnableExtensionRequest::class => MockResponse::make([
            'error' => [
                'code' => 'extension.disabled',
                'message' => 'The tasks extension is disabled.',
                'details' => ['id' => 17, 'secret' => 'must-not-leak'],
            ],
        ], 409, ['X-Orbit-Request-Id' => $requestId]),
    ]);

    $this->artisan('extension:enable', ['extension' => 'tasks', '--json' => true])
        ->assertExitCode(1)
        ->expectsOutput('{"error":{"code":"extension.disabled","message":"The tasks extension is disabled.","details":{"id":17},"request_id":"'.$requestId.'"}}')
        ->doesntExpectOutputToContain('must-not-leak');
});

it('returns the Gateway refusal when a disabled extension command is invoked directly', function (): void {
    $mock = MockClient::global([
        ListExtensionsRequest::class => MockResponse::make([
            'data' => ['tasks' => false, 'proxycli' => false], 'meta' => ['request_id' => 'list-id'],
        ]),
        ListTaskGroupsRequest::class => MockResponse::make([
            'error' => [
                'code' => 'extension.disabled',
                'message' => 'The tasks extension is disabled.',
                'request_id' => 'disabled-id',
            ],
        ], 409),
    ]);

    $this->artisan('tasks:list', ['--json' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('extension.disabled');

    $mock->assertSentCount(1, ListTaskGroupsRequest::class);
});

it('replays the recorded extension API responses as CLI contracts', function (): void {
    run_extension_contract('extensions/extensions-list/disabled', 'extension:list', [], 'extensions/extensions-list/disabled');
    run_extension_contract('extensions/extensions-enable/enabled', 'extension:enable', ['extension' => 'tasks'], 'extensions/extensions-enable/enabled');
    run_extension_contract('extensions/extensions-enable/unknown-slug', 'extension:enable', ['extension' => 'unknown'], 'extensions/extensions-enable/unknown-slug', 1);
    run_extension_contract('extensions/extensions-disable/disabled', 'extension:disable', ['extension' => 'proxycli'], 'extensions/extensions-disable/disabled');
});

/** @param array<string, mixed> $arguments */
function run_extension_contract(string $fixture, string $command, array $arguments, string $expected, int $exitCode = 0): void
{
    foreach (['human.txt' => [], 'json' => ['--json' => true]] as $extension => $mode) {
        MockClient::destroyGlobal();
        extension_commands_reset_discovery();
        MockClient::global(gateway_fixture_mock($fixture));

        expect(Artisan::call($command, [...$arguments, ...$mode]))->toBe($exitCode);
        expect_output(Artisan::output(), "{$expected}.{$extension}");
    }
}
