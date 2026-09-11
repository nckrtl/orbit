<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Deployments\DeployAppInstanceRequest;
use Orbit\Sdk\Requests\Deployments\ListAppInstanceReleasesRequest;
use Orbit\Sdk\Requests\Deployments\RollbackAppInstanceRequest;
use Orbit\Sdk\Requests\Deployments\ShowAppInstanceDeploymentConfigRequest;
use Orbit\Sdk\Requests\Deployments\UpdateAppInstanceDeploymentConfigRequest;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-deployments-'.Str::uuid();
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

describe('deployment configuration', function (): void {
    it('shows configuration through one typed SDK request', function (): void {
        $mock = MockClient::global([
            ShowAppInstanceDeploymentConfigRequest::class => deployment_cli_config_response(),
        ]);

        $this
            ->artisan('instance:deployment-config', ['instance' => '17'])
            ->expectsOutput('Branch: "main"')
            ->expectsOutput('Steps:')
            ->expectsOutput('- Name: "migrate"')
            ->expectsOutput('  Phase: before_activation')
            ->expectsOutput('  Command: "php artisan migrate --force"')
            ->expectsOutput('  Timeout: 300 seconds')
            ->expectsOutput('Request ID: '.deployment_cli_request_id())
            ->assertExitCode(0);

        expect($mock->getLastRequest())
            ->toBeInstanceOf(ShowAppInstanceDeploymentConfigRequest::class)
            ->and($mock->getLastPendingRequest()?->getUrl())
            ->toBe('https://10.44.0.1/api/v1/instances/17/deployment-config')
            ->and($mock->getRecordedResponses())
            ->toHaveCount(1);
    });

    it('submits one complete typed configuration and renders its JSON response', function (): void {
        $path = $this->orbitHome.'/deployment.json';
        new Filesystem()->put($path, json_encode([
            'branch' => 'release',
            'steps' => [
                [
                    'name' => 'install',
                    'phase' => 'before_activation',
                    'command' => 'composer install --no-dev',
                ],
                [
                    'name' => 'restart',
                    'phase' => 'after_activation',
                    'command' => 'php artisan queue:restart',
                    'timeout_seconds' => 45,
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $mock = MockClient::global([
            UpdateAppInstanceDeploymentConfigRequest::class => deployment_cli_config_response(
                branch: 'release',
                steps: [
                    deployment_cli_step('install', 'before_activation', 'composer install --no-dev', 300),
                    deployment_cli_step('restart', 'after_activation', 'php artisan queue:restart', 45),
                ],
            ),
        ]);

        $exitCode = Artisan::call('instance:deployment-config', [
            'instance' => '17',
            '--file' => $path,
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and(trim(Artisan::output()))
            ->toBe(json_encode([
                'branch' => 'release',
                'steps' => [
                    deployment_cli_step('install', 'before_activation', 'composer install --no-dev', 300),
                    deployment_cli_step('restart', 'after_activation', 'php artisan queue:restart', 45),
                ],
                'request_id' => deployment_cli_request_id(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
            ->and($mock->getLastRequest())
            ->toBeInstanceOf(UpdateAppInstanceDeploymentConfigRequest::class)
            ->and($mock->getLastRequest()?->body()->all())
            ->toBe([
                'branch' => 'release',
                'steps' => [
                    [
                        'name' => 'install',
                        'phase' => 'before_activation',
                        'command' => 'composer install --no-dev',
                    ],
                    [
                        'name' => 'restart',
                        'phase' => 'after_activation',
                        'command' => 'php artisan queue:restart',
                        'timeout_seconds' => 45,
                    ],
                ],
            ])
            ->and($mock->getRecordedResponses())
            ->toHaveCount(1);
    });

    it('rejects unreadable malformed or incomplete configuration without exposing input or sending a request', function (string $contents): void {
        $path = $this->orbitHome.'/private-command.json';
        new Filesystem()->put($path, $contents);
        $mock = MockClient::global();

        $exitCode = Artisan::call('instance:deployment-config', [
            'instance' => '17',
            '--file' => $path,
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(trim(Artisan::output()))
            ->toBe(deployment_cli_error(
                'deployment.config_file_invalid',
                'Deployment configuration file is invalid.',
            ))
            ->not->toContain('private-command', 'secret-deploy-command')
            ->and($mock->getLastPendingRequest())
            ->toBeNull();
    })->with([
        'malformed JSON' => ['{"branch":"main","command":"secret-deploy-command"'],
        'missing steps' => [json_encode(['branch' => 'main', 'secret' => 'secret-deploy-command'], JSON_THROW_ON_ERROR)],
        'wrong step type' => [json_encode(['branch' => 'main', 'steps' => ['secret-deploy-command']], JSON_THROW_ON_ERROR)],
        'unknown member' => [json_encode(['branch' => 'main', 'steps' => [], 'secret' => 'secret-deploy-command'], JSON_THROW_ON_ERROR)],
    ]);

    it('rejects an empty file option before sending a request', function (): void {
        $mock = MockClient::global();

        $exitCode = Artisan::call('instance:deployment-config', [
            'instance' => '17',
            '--file' => '',
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(trim(Artisan::output()))
            ->toBe(deployment_cli_error(
                'deployment.config_file_invalid',
                'Deployment configuration file is invalid.',
            ))
            ->and($mock->getLastPendingRequest())
            ->toBeNull();
    });
});

describe('retained releases', function (): void {
    it('lists the current retained releases through one typed SDK request', function (): void {
        $mock = MockClient::global([
            ListAppInstanceReleasesRequest::class => deployment_cli_releases_response(),
        ]);

        $this
            ->artisan('instance:releases', ['instance' => '17'])
            ->expectsOutput('Retained releases:')
            ->expectsOutput('- release-a')
            ->expectsOutput('- release-b (selected)')
            ->expectsOutput('Selected release: release-b')
            ->expectsOutput('Request ID: '.deployment_cli_request_id())
            ->assertExitCode(0);

        expect($mock->getLastRequest())
            ->toBeInstanceOf(ListAppInstanceReleasesRequest::class)
            ->and($mock->getRecordedResponses())
            ->toHaveCount(1);
    });

    it('renders the exact retained-release JSON response', function (): void {
        MockClient::global([
            ListAppInstanceReleasesRequest::class => deployment_cli_releases_response(),
        ]);

        $this
            ->artisan('instance:releases', ['instance' => '17', '--json' => true])
            ->expectsOutput(json_encode([
                'releases' => ['release-a', 'release-b'],
                'selected_release' => 'release-b',
                'request_id' => deployment_cli_request_id(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
            ->assertExitCode(0);
    });
});

describe('deployment streams', function (): void {
    it('renders phases and incremental application bytes safely for a human', function (): void {
        $bytes = "first\x1b[31m\xff\n";
        $mock = MockClient::global([
            DeployAppInstanceRequest::class => deployment_cli_stream_response([
                deployment_cli_phase(1, 'before_activation', 'migrate'),
                deployment_cli_output(2, 'stdout', $bytes),
                deployment_cli_output(3, 'stderr', "warning\r\n"),
                deployment_cli_result(4, 'succeeded', selectedRelease: 'release-b'),
            ]),
        ]);

        $exitCode = Artisan::call('instance:deploy', [
            'instance' => '17',
            '--no-interaction' => true,
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)
            ->toContain(
                'Phase: Before activation [migrate]',
                'stdout: "first\\u001b[31m\\ufffd\\n"',
                'stderr: "warning\\r\\n"',
                'Result: succeeded',
                'Selected release: release-b',
                'Request ID: '.deployment_cli_request_id(),
            )
            ->not->toContain("\x1b[31m", "\xff")
            ->and($mock->getRecordedResponses())
            ->toHaveCount(1)
            ->and($mock->getLastResponse()?->stream()->isReadable())
            ->toBeFalse();
    });

    it('emits only exact compact NDJSON events in JSON mode', function (): void {
        $events = [
            deployment_cli_phase(1, 'source_preparation'),
            deployment_cli_output(2, 'stdout', "first\0bytes\n"),
            deployment_cli_result(3, 'succeeded', selectedRelease: 'release-b'),
        ];
        MockClient::global([
            DeployAppInstanceRequest::class => deployment_cli_stream_response($events),
        ]);

        $exitCode = Artisan::call('instance:deploy', [
            'instance' => '17',
            '--json' => true,
            '--no-interaction' => true,
        ]);
        $lines = array_values(array_filter(explode("\n", trim(Artisan::output()))));

        expect($exitCode)->toBe(0)
            ->and($lines)->toHaveCount(3)
            ->and(array_map(
                static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
                $lines,
            ))->toBe($events)
            ->and(Artisan::output())->not->toContain('Phase:', 'Result:', 'Request ID:', '?');
    });

    it('uses one typed rollback request with only the selected release', function (): void {
        $mock = MockClient::global([
            RollbackAppInstanceRequest::class => deployment_cli_stream_response([
                deployment_cli_phase(1, 'rollback'),
                deployment_cli_result(2, 'succeeded', selectedRelease: 'release-a'),
            ]),
        ]);

        $this
            ->artisan('instance:rollback', [
                'instance' => '17',
                '--release' => 'release-a',
                '--json' => true,
            ])
            ->assertExitCode(0);

        expect($mock->getLastRequest())
            ->toBeInstanceOf(RollbackAppInstanceRequest::class)
            ->and($mock->getLastRequest()?->body()->all())
            ->toBe(['release' => 'release-a'])
            ->and($mock->getRecordedResponses())
            ->toHaveCount(1);
    });

    it('rejects a missing rollback release before sending a request', function (): void {
        $mock = MockClient::global();

        $exitCode = Artisan::call('instance:rollback', [
            'instance' => '17',
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(trim(Artisan::output()))
            ->toBe(deployment_cli_error(
                'deployment.release_required',
                'A retained release name is required.',
            ))
            ->and($mock->getLastPendingRequest())
            ->toBeNull();
    });

    it('returns failure and identifies the selected release from a failed terminal result', function (): void {
        $mock = MockClient::global([
            DeployAppInstanceRequest::class => deployment_cli_stream_response([
                deployment_cli_result(
                    1,
                    'failed',
                    failedStep: 'after_activation',
                    errorCode: 'deployment.step_failed',
                    selectedRelease: 'release-b',
                ),
            ]),
        ]);

        $this
            ->artisan('instance:deploy', ['instance' => '17'])
            ->expectsOutput('Result: failed')
            ->expectsOutput('Failed boundary: after_activation')
            ->expectsOutput('Error code: deployment.step_failed')
            ->expectsOutput('Selected release: release-b')
            ->expectsOutput('Request ID: '.deployment_cli_request_id())
            ->assertExitCode(1);

        expect($mock->getRecordedResponses())->toHaveCount(1);
    });

    it('rejects a truncated stream with a correlated safe error and no resubmission', function (): void {
        $mock = MockClient::global([
            DeployAppInstanceRequest::class => deployment_cli_stream_response([
                deployment_cli_phase(1, 'source_preparation'),
            ]),
        ]);

        $exitCode = Artisan::call('instance:deploy', [
            'instance' => '17',
            '--json' => true,
            '--no-interaction' => true,
        ]);
        $lines = array_values(array_filter(explode("\n", trim(Artisan::output()))));

        expect($exitCode)->toBe(1)
            ->and($lines)->toHaveCount(2)
            ->and(json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR))
            ->toBe(deployment_cli_phase(1, 'source_preparation'))
            ->and($lines[1])
            ->toBe(deployment_cli_error(
                'deployment.stream_invalid',
                'Gateway deployment stream is invalid.',
                deployment_cli_request_id(),
            ))
            ->and($mock->getRecordedResponses())
            ->toHaveCount(1)
            ->and($mock->getLastResponse()?->stream()->isReadable())
            ->toBeFalse();
    });

    it('preserves shared safe pre-admission errors and request IDs', function (): void {
        $mock = MockClient::global([
            DeployAppInstanceRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'deployment.busy',
                    'message' => "Deployment\0is busy.",
                    'details' => ['command' => 'secret-deploy-command'],
                ],
            ], 409, ['X-Orbit-Request-Id' => deployment_cli_request_id()]),
        ]);

        $exitCode = Artisan::call('instance:deploy', [
            'instance' => '17',
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(trim(Artisan::output()))
            ->toBe(deployment_cli_error(
                'deployment.busy',
                'Deployment is busy.',
                deployment_cli_request_id(),
            ))
            ->not->toContain('secret-deploy-command')
            ->and($mock->getRecordedResponses())
            ->toHaveCount(1);
    });

    it('closes an interrupted stream and never submits another deployment', function (): void {
        if (! defined('SIGINT') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX signals are unavailable.');
        }

        $response = new DeploymentCliInterruptingResponse;
        $mock = MockClient::global([
            DeployAppInstanceRequest::class => $response,
        ]);

        $exitCode = Artisan::call('instance:deploy', [
            'instance' => '17',
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(trim(Artisan::output()))
            ->toBe(deployment_cli_error(
                'deployment.interrupted',
                'Deployment interrupted.',
                deployment_cli_request_id(),
            ))
            ->and($response->stream?->eof())
            ->toBeTrue()
            ->and($mock->getRecordedResponses())
            ->toHaveCount(1);
    });
});

function deployment_cli_config_response(string $branch = 'main', ?array $steps = null): MockResponse
{
    return MockResponse::make([
        'data' => [
            'branch' => $branch,
            'steps' => $steps ?? [
                deployment_cli_step('migrate', 'before_activation', 'php artisan migrate --force', 300),
            ],
        ],
        'meta' => ['request_id' => deployment_cli_request_id()],
    ]);
}

/** @return array{name: string, phase: string, command: string, timeout_seconds: int} */
function deployment_cli_step(string $name, string $phase, string $command, int $timeout): array
{
    return [
        'name' => $name,
        'phase' => $phase,
        'command' => $command,
        'timeout_seconds' => $timeout,
    ];
}

function deployment_cli_releases_response(): MockResponse
{
    return MockResponse::make([
        'data' => [
            'releases' => ['release-a', 'release-b'],
            'selected_release' => 'release-b',
        ],
        'meta' => ['request_id' => deployment_cli_request_id()],
    ]);
}

/** @param list<array<string, mixed>> $events */
function deployment_cli_stream_response(array $events): MockResponse
{
    return MockResponse::make(
        implode('', array_map(
            static fn (array $event): string => json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n",
            $events,
        )),
        headers: deployment_cli_stream_headers(),
    );
}

/** @return array<string, mixed> */
function deployment_cli_phase(int $sequence, string $phase, ?string $stepName = null): array
{
    $event = [
        'type' => 'phase',
        'sequence' => $sequence,
        'request_id' => deployment_cli_request_id(),
        'phase' => $phase,
    ];

    if ($stepName !== null) {
        $event['step_name'] = $stepName;
    }

    return $event;
}

/** @return array<string, mixed> */
function deployment_cli_output(int $sequence, string $stream, string $data): array
{
    return [
        'type' => 'output',
        'sequence' => $sequence,
        'request_id' => deployment_cli_request_id(),
        'stream' => $stream,
        'data_base64' => base64_encode($data),
    ];
}

/** @return array<string, mixed> */
function deployment_cli_result(
    int $sequence,
    string $status,
    ?string $failedStep = null,
    ?string $errorCode = null,
    ?string $selectedRelease = null,
): array {
    return [
        'type' => 'result',
        'sequence' => $sequence,
        'request_id' => deployment_cli_request_id(),
        'status' => $status,
        'failed_step' => $failedStep,
        'error_code' => $errorCode,
        'selected_release' => $selectedRelease,
    ];
}

/** @return array<string, string> */
function deployment_cli_stream_headers(): array
{
    return [
        'Content-Type' => 'application/x-ndjson',
        'X-Orbit-Request-Id' => deployment_cli_request_id(),
    ];
}

function deployment_cli_error(string $code, string $message, ?string $requestId = null): string
{
    return json_encode([
        'error' => [
            'code' => $code,
            'message' => $message,
            'request_id' => $requestId,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function deployment_cli_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a847';
}

final class DeploymentCliInterruptingResponse extends MockResponse
{
    public ?PumpStream $stream = null;

    public function __construct()
    {
        parent::__construct('');
    }

    public function createPsrResponse(
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
    ): ResponseInterface {
        $sent = false;
        $this->stream = new PumpStream(static function () use (&$sent): ?string {
            if ($sent) {
                return null;
            }

            $sent = true;
            posix_kill(getmypid(), SIGINT);

            return 'ignored';
        });

        return new PsrResponse(200, deployment_cli_stream_headers(), $this->stream);
    }
}
