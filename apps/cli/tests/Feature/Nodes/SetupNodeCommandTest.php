<?php

declare(strict_types=1);

use App\Commands\Nodes\SetupNodeCommand;
use App\Data\GatewayProfile;
use App\Data\NodeSetupExecutionResult;
use App\Data\NodeSetupFacts;
use App\Exceptions\LocalNodeSetupException;
use App\Repositories\GatewayConfigRepository;
use App\Services\NodeSetup\ControllingTerminal;
use App\Services\NodeSetup\MacOsAppDevSetupRunner;
use App\Support\LocalDiagnosticRedactor;
use Illuminate\Console\Signals;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\FetchAppDevSetupScriptRequest;
use Orbit\Sdk\Requests\Nodes\SubmitAppDevSetupResultRequest;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

beforeEach(function (): void {
    Signals::resolveAvailabilityUsing(static fn (): bool => false);
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-setup-'.Str::uuid();
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

it('rejects every role except app-dev before terminal or gateway IO', function (): void {
    expect(class_exists(SetupNodeCommand::class))->toBeTrue();

    [$terminal, $runner] = bind_setup_dependencies();
    $mock = MockClient::global();

    $this
        ->artisan('node:setup', ['role' => 'database'])
        ->expectsOutputToContain('Only the app-dev role supports local setup.')
        ->assertExitCode(1);

    expect($mock->getLastPendingRequest())
        ->toBeNull()
        ->and($terminal->availabilityChecks)
        ->toBe(0)
        ->and($runner->runs)
        ->toBe(0);
});

it('rejects noninteractive setup before terminal or gateway IO', function (): void {
    [$terminal, $runner] = bind_setup_dependencies();
    $mock = MockClient::global();

    $this
        ->artisan('node:setup', ['role' => 'app-dev', '--no-interaction' => true, '--json' => true])
        ->expectsOutput(setup_failure_json(
            'node.setup_confirmation_required',
            'Interactive confirmation through a controlling terminal is required.',
        ))
        ->assertExitCode(1);

    expect($mock->getLastPendingRequest())
        ->toBeNull()
        ->and($terminal->availabilityChecks)
        ->toBe(0)
        ->and($runner->runs)
        ->toBe(0);
});

it('rejects a non-Darwin platform before terminal or gateway IO', function (): void {
    [$terminal, $runner] = bind_setup_dependencies(platform: 'linux');
    $mock = MockClient::global();

    $this
        ->artisan('node:setup', ['role' => 'app-dev', '--json' => true])
        ->expectsOutput(setup_failure_json(
            'node.setup_platform_invalid',
            'Local app-dev setup requires macOS.',
        ))
        ->assertExitCode(1);

    expect($mock->getLastPendingRequest())
        ->toBeNull()
        ->and($terminal->availabilityChecks)
        ->toBe(0)
        ->and($runner->runs)
        ->toBe(0);
});

it('rejects an unavailable local identity before terminal or gateway IO', function (): void {
    [$terminal, $runner] = bind_setup_dependencies(identity: null);
    $mock = MockClient::global();

    $this
        ->artisan('node:setup', ['role' => 'app-dev', '--json' => true])
        ->expectsOutput(setup_failure_json(
            'node.setup_identity_unavailable',
            'Could not determine the current local user.',
        ))
        ->assertExitCode(1);

    expect($mock->getLastPendingRequest())
        ->toBeNull()
        ->and($terminal->availabilityChecks)
        ->toBe(0)
        ->and($runner->runs)
        ->toBe(0);
});

it('requires a readable and writable controlling terminal before gateway IO', function (): void {
    [$terminal, $runner] = bind_setup_dependencies(terminalAvailable: false);
    $mock = MockClient::global();

    $this
        ->artisan('node:setup', ['role' => 'app-dev', '--json' => true])
        ->expectsOutput(setup_failure_json(
            'node.setup_confirmation_required',
            'Interactive confirmation through a controlling terminal is required.',
        ))
        ->assertExitCode(1);

    expect($mock->getLastPendingRequest())
        ->toBeNull()
        ->and($terminal->availabilityChecks)
        ->toBe(1)
        ->and($runner->runs)
        ->toBe(0);
});

it('declines after one script request and preserves its request ID', function (): void {
    [$terminal, $runner] = bind_setup_dependencies(confirmed: false);
    $mock = MockClient::global([
        FetchAppDevSetupScriptRequest::class => setup_script_response(),
    ]);

    $this
        ->artisan('node:setup', ['role' => 'app-dev', '--json' => true])
        ->expectsOutput(setup_failure_json(
            'node.setup_cancelled',
            'Local setup was cancelled.',
            setup_script_request_id(),
        ))
        ->assertExitCode(1);

    expect($mock->getRecordedResponses())
        ->toHaveCount(1)
        ->and($terminal->confirmations)
        ->toBe(1)
        ->and($runner->runs)
        ->toBe(0);
});

it('submits a bounded local result and renders the exact active lifecycle output', function (): void {
    [$terminal, $runner] = bind_setup_dependencies(
        executionResult: new NodeSetupExecutionResult(0, 'setup complete'),
    );
    $mock = MockClient::global([
        FetchAppDevSetupScriptRequest::class => setup_script_response(),
        SubmitAppDevSetupResultRequest::class => setup_result_response(),
    ]);

    expect(app(MacOsAppDevSetupRunner::class))->toBe($runner);

    $output = setup_call(['role' => 'app-dev']);

    expect($runner->runs)
        ->toBe(1)
        ->and($output['output'])
        ->toContain('Role [app-dev] is active on node [mini].')
        ->toContain('Request ID: '.setup_result_request_id())
        ->and($output['exit'])
        ->toBe(0)
        ->and($mock->getRecordedResponses())
        ->toHaveCount(2)
        ->and($mock->getLastRequest())
        ->toBeInstanceOf(SubmitAppDevSetupResultRequest::class)
        ->and($mock->getLastRequest()?->body()->all())
        ->toBe(['exit_code' => 0, 'diagnostics' => 'setup complete'])
        ->and($runner->receivedScript)
        ->toBe("#!/bin/bash\necho setup");
});

it('writes exactly one typed JSON success object to stdout', function (): void {
    [$terminal] = bind_setup_dependencies();
    MockClient::global([
        FetchAppDevSetupScriptRequest::class => setup_script_response(),
        SubmitAppDevSetupResultRequest::class => setup_result_response(),
    ]);
    $expected = setup_node_role_payload();
    $expected['assignment']['status'] = 'active';
    $expected['assignment']['local_action_required'] = false;
    $expected['assignment']['local_command'] = null;
    $expected['request_id'] = setup_result_request_id();

    $output = setup_call(['role' => 'app-dev', '--json' => true]);

    expect($output['exit'])
        ->toBe(0)
        ->and($output['output'])
        ->toBe(json_encode($expected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
        ->and(substr_count($output['output'], "\n"))
        ->toBe(0)
        ->and($terminal->confirmations)
        ->toBe(1);
});

it('uses system-derived facts in the caller-derived script request', function (): void {
    bind_setup_dependencies(confirmed: false);
    $mock = MockClient::global([
        FetchAppDevSetupScriptRequest::class => setup_script_response(),
    ]);

    $this->artisan('node:setup', ['role' => 'app-dev'])->assertExitCode(1);

    expect($mock->getLastRequest())
        ->toBeInstanceOf(FetchAppDevSetupScriptRequest::class)
        ->and($mock->getLastRequest()?->body()->all())
        ->toBe([
            'platform' => 'darwin',
            'architecture' => 'arm64',
            'username' => 'mini-user',
            'home_directory' => '/Users/mini-user',
        ]);
});

it('derives the local identity from POSIX instead of environment variables', function (): void {
    $oldUser = getenv('USER');
    $oldHome = getenv('HOME');
    putenv('USER=untrusted-environment-user');
    putenv('HOME=/untrusted/environment/home');

    try {
        $identity = new NodeSetupFacts()->identity();
        $expected = posix_getpwuid(posix_geteuid());

        expect($identity)->toBe([
            'username' => $expected['name'],
            'home_directory' => $expected['dir'],
        ]);
    } finally {
        putenv($oldUser === false ? 'USER' : 'USER='.$oldUser);
        putenv($oldHome === false ? 'HOME' : 'HOME='.$oldHome);
    }
});

it('submits nonzero and handled interrupt results as the second request', function (
    NodeSetupExecutionResult $result,
    int $expectedExitCode,
): void {
    bind_setup_dependencies(executionResult: $result);
    $mock = MockClient::global([
        FetchAppDevSetupScriptRequest::class => setup_script_response(),
        SubmitAppDevSetupResultRequest::class => setup_result_response(),
    ]);

    $this->artisan('node:setup', ['role' => 'app-dev'])->assertExitCode(0);

    expect($mock->getRecordedResponses())
        ->toHaveCount(2)
        ->and($mock->getLastRequest()?->body()->all())
        ->toBe(['exit_code' => $expectedExitCode, 'diagnostics' => 'bounded diagnostics']);
})->with([
    'nonzero' => [new NodeSetupExecutionResult(9, 'bounded diagnostics'), 9],
    'handled SIGINT' => [new NodeSetupExecutionResult(130, 'bounded diagnostics', true), 130],
]);

it('reports a local start failure after one request with the script request ID', function (): void {
    [$terminal, $runner] = bind_setup_dependencies(
        runnerException: new LocalNodeSetupException('private temp path and argv'),
    );
    $mock = MockClient::global([
        FetchAppDevSetupScriptRequest::class => setup_script_response(),
    ]);

    $output = setup_call(['role' => 'app-dev', '--json' => true]);

    expect($output['exit'])
        ->toBe(1)
        ->and($output['output'])
        ->toBe(setup_failure_json('node.setup_local_failed', 'Local setup failed.', setup_script_request_id()))
        ->not->toContain('private temp path')
        ->not->toContain('argv')
        ->not->toContain('echo setup')->and($mock->getRecordedResponses())->toHaveCount(
            1,
        )->and($terminal->confirmations)->toBe(1)->and($runner->runs)->toBe(1);
});

it('preserves a script gateway failure request ID and makes one request', function (): void {
    bind_setup_dependencies();
    $mock = MockClient::global([
        FetchAppDevSetupScriptRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'node.role_setup_not_ready',
                    'message' => 'Node role setup is not ready.',
                    'details' => ['failed_step' => 'wireguard-projection'],
                ],
            ],
            409,
            ['X-Orbit-Request-Id' => setup_script_request_id()],
        ),
    ]);

    $output = setup_call(['role' => 'app-dev', '--json' => true]);

    expect($output['exit'])
        ->toBe(1)
        ->and(json_decode($output['output'], associative: true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['error' => [
            'code' => 'node.role_setup_not_ready',
            'message' => 'Node role setup is not ready.',
            'request_id' => setup_script_request_id(),
        ]])
        ->and($output['output'])
        ->not
        ->toContain('wireguard-projection')
        ->and($mock->getRecordedResponses())
        ->toHaveCount(1);
});

it('uses a null request ID for a script transport failure', function (): void {
    bind_setup_dependencies();
    $requests = 0;
    MockClient::global([
        FetchAppDevSetupScriptRequest::class => static function (PendingRequest $request) use (&$requests): never {
            $requests++;
            throw new FatalRequestException(new RuntimeException('private transport failure'), $request);
        },
    ]);

    $this
        ->artisan('node:setup', ['role' => 'app-dev', '--json' => true])
        ->expectsOutput(setup_failure_json('gateway.unreachable', 'Could not reach the gateway.'))
        ->assertExitCode(1);

    expect($requests)->toBe(1);
});

it('maps a malformed successful script DTO to the exact safe failure without retry', function (bool $json): void {
    bind_setup_dependencies();
    $mock = MockClient::global([
        FetchAppDevSetupScriptRequest::class => MockResponse::make([
            'data' => ['role' => 'app-dev', 'summary' => 'Safe summary', 'script' => ''],
            'meta' => ['request_id' => setup_script_request_id()],
        ]),
    ]);

    $output = setup_call(['role' => 'app-dev', '--json' => $json]);

    expect($output['exit'])
        ->toBe(1)
        ->and($output['output'])
        ->{$json ? 'toBe' : 'toContain'}(
            $json
                ? setup_failure_json('gateway.invalid_response', 'Gateway response is invalid.')
                : 'Gateway response is invalid.',
        )
        ->not
        ->toContain('invalid app-dev setup script')
        ->and($mock->getRecordedResponses())
        ->toHaveCount(1);
})->with(['human' => false, 'JSON' => true]);

it('preserves a result gateway failure request ID after exactly two requests', function (): void {
    bind_setup_dependencies();
    $mock = MockClient::global([
        FetchAppDevSetupScriptRequest::class => setup_script_response(),
        SubmitAppDevSetupResultRequest::class => MockResponse::make(
            [
                'error' => [
                    'code' => 'macos.setup_failed',
                    'message' => 'Local macOS setup failed.',
                    'details' => ['failed_step' => 'local-setup'],
                ],
            ],
            422,
            ['X-Orbit-Request-Id' => setup_result_request_id()],
        ),
    ]);

    $output = setup_call(['role' => 'app-dev', '--json' => true]);

    expect($output['exit'])
        ->toBe(1)
        ->and(json_decode($output['output'], associative: true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['error' => [
            'code' => 'macos.setup_failed',
            'message' => 'Local macOS setup failed.',
            'request_id' => setup_result_request_id(),
        ]])
        ->and($mock->getRecordedResponses())
        ->toHaveCount(2);
});

it('uses a null request ID for a result transport failure after exactly two requests', function (): void {
    bind_setup_dependencies();
    $resultRequests = 0;
    $mock = MockClient::global([
        FetchAppDevSetupScriptRequest::class => setup_script_response(),
        SubmitAppDevSetupResultRequest::class => static function (PendingRequest $request) use (
            &$resultRequests,
        ): never {
            $resultRequests++;
            throw new FatalRequestException(new RuntimeException('private result transport failure'), $request);
        },
    ]);

    $this
        ->artisan('node:setup', ['role' => 'app-dev', '--json' => true])
        ->expectsOutput(setup_failure_json('gateway.unreachable', 'Could not reach the gateway.'))
        ->assertExitCode(1);

    expect($mock->getRecordedResponses())->toHaveCount(1)->and($resultRequests)->toBe(1);
});

it('maps a malformed successful result DTO to the exact safe failure without retry', function (bool $json): void {
    bind_setup_dependencies();
    $mock = MockClient::global([
        FetchAppDevSetupScriptRequest::class => setup_script_response(),
        SubmitAppDevSetupResultRequest::class => MockResponse::make([
            'data' => [
                ...setup_node_role_payload(),
                'assignment' => [
                    ...setup_node_role_payload()['assignment'],
                    'status' => 'unexpected',
                ],
            ],
            'meta' => ['request_id' => setup_result_request_id()],
        ]),
    ]);

    $output = setup_call(['role' => 'app-dev', '--json' => $json]);

    expect($output['exit'])
        ->toBe(1)
        ->and($output['output'])
        ->{$json ? 'toBe' : 'toContain'}(
            $json
                ? setup_failure_json('gateway.invalid_response', 'Gateway response is invalid.')
                : 'Gateway response is invalid.',
        )
        ->not
        ->toContain('invalid node role')
        ->and($mock->getRecordedResponses())
        ->toHaveCount(2);
})->with(['human' => false, 'JSON' => true]);

/**
 * @param array{username: string, home_directory: string}|null $identity
 * @return array{object, object}
 *
 * @mago-expect lint:excessive-parameter-list The shared fixture exposes each local setup boundary independently.
 */
function bind_setup_dependencies(
    string $platform = 'darwin',
    ?array $identity = ['username' => 'mini-user', 'home_directory' => '/Users/mini-user'],
    bool $terminalAvailable = true,
    bool $confirmed = true,
    ?NodeSetupExecutionResult $executionResult = null,
    ?Throwable $runnerException = null,
): array {
    app()->instance(NodeSetupFacts::class, new class($platform, $identity) extends NodeSetupFacts {
        public function __construct(
            private string $fakePlatform,
            private ?array $fakeIdentity,
        ) {}

        public function platform(): string
        {
            return $this->fakePlatform;
        }

        public function architecture(): string
        {
            return 'arm64';
        }

        public function identity(): ?array
        {
            return $this->fakeIdentity;
        }
    });

    $terminal = new class($terminalAvailable, $confirmed) extends ControllingTerminal {
        public int $availabilityChecks = 0;

        public int $confirmations = 0;

        public function __construct(
            private bool $available,
            private bool $confirmed,
        ) {}

        public function isAvailable(): bool
        {
            $this->availabilityChecks++;

            return $this->available;
        }

        public function confirm(string $summary): bool
        {
            $this->confirmations++;

            return $this->confirmed;
        }
    };
    app()->instance(ControllingTerminal::class, $terminal);

    $result = $executionResult ?? new NodeSetupExecutionResult(0, 'bounded diagnostics');
    $runner = new class($result, $runnerException) extends MacOsAppDevSetupRunner {
        public int $runs = 0;

        public ?string $receivedScript = null;

        public function __construct(
            private NodeSetupExecutionResult $fakeResult,
            private ?Throwable $fakeException,
        ) {
            parent::__construct(new LocalDiagnosticRedactor);
        }

        public function run(string $script, ControllingTerminal $terminal): NodeSetupExecutionResult
        {
            $this->runs++;
            $this->receivedScript = $script;

            if ($this->fakeException instanceof Throwable) {
                throw $this->fakeException;
            }

            return $this->fakeResult;
        }
    };
    app()->instance(MacOsAppDevSetupRunner::class, $runner);

    return [$terminal, $runner];
}

function setup_script_response(): MockResponse
{
    return MockResponse::make([
        'data' => [
            'role' => 'app-dev',
            'summary' => 'Install the app-dev role on this Mac.',
            'script' => "#!/bin/bash\necho setup",
        ],
        'meta' => ['request_id' => setup_script_request_id()],
    ]);
}

function setup_result_response(): MockResponse
{
    $payload = setup_node_role_payload();
    $payload['assignment']['status'] = 'active';
    $payload['assignment']['local_action_required'] = false;
    $payload['assignment']['local_command'] = null;

    return MockResponse::make([
        'data' => $payload,
        'meta' => ['request_id' => setup_result_request_id()],
    ]);
}

/** @return array<string, mixed> */
function setup_node_role_payload(): array
{
    return [
        'node_id' => 2,
        'node_name' => 'mini',
        'assignment' => [
            'role' => 'app-dev',
            'status' => 'provisioning',
            'failed_step' => null,
            'error_code' => null,
            'local_action_required' => true,
            'local_command' => 'orbit node:setup app-dev',
        ],
    ];
}

function setup_script_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a811';
}

function setup_result_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a822';
}

function setup_failure_json(string $code, string $message, ?string $requestId = null): string
{
    return json_encode(['error' => [
        'code' => $code,
        'message' => $message,
        'request_id' => $requestId,
    ]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

/** @param array<string, mixed> $arguments
 *  @return array{exit: int, output: string}
 */
function setup_call(array $arguments): array
{
    $exit = Artisan::call('node:setup', $arguments);

    return ['exit' => $exit, 'output' => trim(Artisan::output())];
}
