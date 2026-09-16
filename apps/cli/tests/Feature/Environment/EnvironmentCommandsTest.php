<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Environment\ImportAppInstanceEnvironmentRequest;
use Orbit\Sdk\Requests\Environment\SynchronizeAppInstanceEnvironmentRequest;
use Orbit\Sdk\Requests\Environment\UpdateAppInstanceEnvironmentRequest;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-environment-'.Str::uuid();
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

describe('environment request wiring', function (): void {
    /** @param array{command:string,arguments:array<string,mixed>,request_class:class-string,path:string,body:array<string,mixed>,operation:string} $case */
    it('sends one typed request with the exact domain selector and operation body', function (array $case): void {
        $mock = MockClient::global([
            $case['request_class'] => environment_cli_response($case['operation']),
        ]);

        $this
            ->artisan($case['command'], $case['arguments'])
            ->assertExitCode(0);

        expect($mock->getLastRequest())
            ->toBeInstanceOf($case['request_class'])
            ->and($mock->getLastPendingRequest()?->getUrl())
            ->toBe("https://10.44.0.1{$case['path']}")
            ->and($mock->getLastRequest()?->body()->all())
            ->toBe($case['body'])
            ->and($mock->getRecordedResponses())
            ->toHaveCount(1);
    })->with([
        'import without replacement' => [[
            'command' => 'env:import',
            'arguments' => ['--instance' => 'app.com'],
            'request_class' => ImportAppInstanceEnvironmentRequest::class,
            'path' => '/api/v1/instances/app.com/environment/import',
            'body' => [],
            'operation' => 'import',
        ]],
        'import with replacement' => [[
            'command' => 'env:import',
            'arguments' => ['--instance' => 'app.com', '--replace' => true],
            'request_class' => ImportAppInstanceEnvironmentRequest::class,
            'path' => '/api/v1/instances/app.com/environment/import',
            'body' => ['replace' => true],
            'operation' => 'import',
        ]],
        'update' => [[
            'command' => 'env:update',
            'arguments' => ['--instance' => 'app.com', '--key' => 'APP_DEBUG', '--value' => 'true'],
            'request_class' => UpdateAppInstanceEnvironmentRequest::class,
            'path' => '/api/v1/instances/app.com/environment/APP_DEBUG',
            'body' => ['value' => 'true'],
            'operation' => 'update',
        ]],
        'synchronize' => [[
            'command' => 'env:sync',
            'arguments' => ['--instance' => 'app.com'],
            'request_class' => SynchronizeAppInstanceEnvironmentRequest::class,
            'path' => '/api/v1/instances/app.com/environment/sync',
            'body' => [],
            'operation' => 'sync',
        ]],
    ]);

    it('accepts a numeric AppInstance ID for every operation', function (
        string $command,
        array $arguments,
        string $requestClass,
        string $operation,
        string $suffix,
    ): void {
        $mock = MockClient::global([
            $requestClass => environment_cli_response($operation),
        ]);

        $this
            ->artisan($command, ['--instance' => '17', ...$arguments])
            ->assertExitCode(0);

        expect($mock->getLastPendingRequest()?->getUrl())
            ->toBe("https://10.44.0.1/api/v1/instances/17/environment/{$suffix}")
            ->and($mock->getRecordedResponses())
            ->toHaveCount(1);
    })->with([
        'import' => ['env:import', [], ImportAppInstanceEnvironmentRequest::class, 'import', 'import'],
        'update' => [
            'env:update',
            ['--key' => 'APP_DEBUG', '--value' => 'true'],
            UpdateAppInstanceEnvironmentRequest::class,
            'update',
            'APP_DEBUG',
        ],
        'synchronize' => ['env:sync', [], SynchronizeAppInstanceEnvironmentRequest::class, 'sync', 'sync'],
    ]);
});

describe('environment value preservation', function (): void {
    it('preserves the explicitly supplied update value as an exact string', function (string $value): void {
        $mock = MockClient::global([
            UpdateAppInstanceEnvironmentRequest::class => environment_cli_response('update'),
        ]);

        $this
            ->artisan('env:update', [
                '--instance' => 'app.com',
                '--key' => 'EXACT_VALUE',
                '--value' => $value,
            ])
            ->assertExitCode(0);

        expect($mock->getLastRequest()?->body()->all())->toBe(['value' => $value]);
    })->with([
        'empty string' => [''],
        'false string' => ['false'],
        'zero string' => ['0'],
        'newlines' => ["first\nsecond"],
        'Route domain placeholder' => ['https://{{app_instance.domain}}'],
    ]);

    it('refuses every missing required option before an HTTP request', function (
        string $command,
        array $arguments,
        string $code,
    ): void {
        $mock = MockClient::global();

        $exitCode = Artisan::call($command, [
            ...$arguments,
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)->toBe(1);
        expect(trim(Artisan::output()))
            ->toBe(environment_cli_error_json($code));
        expect($mock->getLastPendingRequest())->toBeNull();
    })->with([
        'import selector' => ['env:import', [], 'env.instance_required'],
        'update selector' => ['env:update', ['--key' => 'APP_DEBUG', '--value' => 'true'], 'env.instance_required'],
        'update key' => ['env:update', ['--instance' => 'app.com', '--value' => 'true'], 'env.key_required'],
        'update value' => ['env:update', ['--instance' => 'app.com', '--key' => 'APP_DEBUG'], 'env.value_required'],
        'sync selector' => ['env:sync', [], 'env.instance_required'],
    ]);
});

describe('environment output', function (): void {
    it('renders a complete value-free human result and states that a store leaves the workload file unchanged', function (): void {
        MockClient::global([
            UpdateAppInstanceEnvironmentRequest::class => environment_cli_response('update'),
        ]);

        expect(Artisan::call('env:update', [
            '--instance' => 'app.com',
            '--key' => 'PRIVATE_VALUE',
            '--value' => 'environment-secret-sentinel',
        ]))->toBe(0);
        $output = Artisan::output();
        expect($output)->toContain('AppInstance ID');
        expect($output)->toContain('17');
        expect($output)->toContain('Operation');
        expect($output)->toContain('update');
        expect($output)->toContain('Changed');
        expect($output)->toContain('true');
        expect($output)->toContain('Stored keys');
        expect($output)->toContain('3');
        expect($output)->toContain('Workload file');
        expect($output)->toContain('unchanged');
        expect($output)->toContain(environment_cli_request_id());
        expect($output)->not->toContain('environment-secret-sentinel');
    });

    it('renders the exact value-free JSON store result', function (): void {
        MockClient::global([
            ImportAppInstanceEnvironmentRequest::class => environment_cli_response('import'),
        ]);

        $this
            ->artisan('env:import', ['--instance' => 'app.com', '--json' => true])
            ->expectsOutput(environment_cli_result_json('import', workloadFileChanged: false))
            ->assertExitCode(0);
    });

    it('reports synchronization without claiming cache or process refreshes', function (): void {
        MockClient::global([
            SynchronizeAppInstanceEnvironmentRequest::class => environment_cli_response('sync', changed: false),
        ]);

        expect(Artisan::call('env:sync', ['--instance' => 'app.com']))->toBe(0);
        $output = Artisan::output();
        expect($output)->toContain('AppInstance ID');
        expect($output)->toContain('17');
        expect($output)->toContain('Operation');
        expect($output)->toContain('sync');
        expect($output)->toContain('Changed');
        expect($output)->toContain('false');
        expect($output)->toContain('Stored keys');
        expect($output)->toContain('3');
        expect($output)->toContain(environment_cli_request_id());
        expect($output)->not->toContain('cache');
        expect($output)->not->toContain('restart');
        expect($output)->not->toContain('refresh');
    });
});

describe('environment failures', function (): void {
    it('renders a bounded Gateway failure with its request ID and without the submitted value', function (): void {
        MockClient::global([
            UpdateAppInstanceEnvironmentRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => 'env.configuration_invalid',
                        'message' => 'The complete AppInstance environment configuration is invalid.',
                        'details' => ['value' => 'environment-secret-sentinel'],
                    ],
                ],
                422,
                ['X-Orbit-Request-Id' => environment_cli_request_id()],
            ),
        ]);

        $exitCode = Artisan::call('env:update', [
            '--instance' => 'app.com',
            '--key' => 'PRIVATE_VALUE',
            '--value' => 'environment-secret-sentinel',
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)->toBe(1);
        expect(trim(Artisan::output()))
            ->toBe(environment_cli_error_json(
                code: 'env.configuration_invalid',
                message: 'The complete AppInstance environment configuration is invalid.',
                requestId: environment_cli_request_id(),
            ))
            ->not->toContain('environment-secret-sentinel')
            ->not->toContain('details')
            ->not->toContain('Gateway environment operation failed with HTTP status');
    });

    it('renders redacted configuration details for an invalid key and drops submitted values', function (): void {
        MockClient::global([
            UpdateAppInstanceEnvironmentRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => 'env.configuration_invalid',
                        'message' => 'The complete AppInstance environment configuration is invalid.',
                        'details' => [
                            'key' => 'BAD KEY',
                            'rule' => 'key',
                            'value' => 'environment-secret-sentinel',
                        ],
                    ],
                ],
                422,
                ['X-Orbit-Request-Id' => environment_cli_request_id()],
            ),
        ]);

        $exitCode = Artisan::call('env:update', [
            '--instance' => 'app.com',
            '--key' => 'BAD KEY',
            '--value' => 'environment-secret-sentinel',
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)->toBe(1);
        expect(trim(Artisan::output()))
            ->toBe(environment_cli_error_json(
                code: 'env.configuration_invalid',
                message: 'The complete AppInstance environment configuration is invalid.',
                requestId: environment_cli_request_id(),
                details: ['key' => 'BAD KEY', 'rule' => 'key'],
            ))
            ->not->toContain('environment-secret-sentinel');
    });

    it('renders redacted placeholder-rule details in human output', function (): void {
        MockClient::global([
            UpdateAppInstanceEnvironmentRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => 'env.configuration_invalid',
                        'message' => 'The complete AppInstance environment configuration is invalid.',
                        'details' => [
                            'key' => 'KEY',
                            'rule' => 'placeholder',
                            'placeholder' => '{{instance.domain}}',
                            'value' => 'environment-secret-sentinel',
                        ],
                    ],
                ],
                422,
                ['X-Orbit-Request-Id' => environment_cli_request_id()],
            ),
        ]);

        $exitCode = Artisan::call('env:update', [
            '--instance' => 'app.com',
            '--key' => 'KEY',
            '--value' => 'environment-secret-sentinel',
            '--no-interaction' => true,
        ]);

        expect($exitCode)->toBe(1);
        $output = trim(Artisan::output());
        expect($output)->toContain('The complete AppInstance environment configuration is invalid.');
        expect($output)->toContain('key: KEY');
        expect($output)->toContain('rule: placeholder');
        expect($output)->toContain('placeholder: {{instance.domain}}');
        expect($output)->toContain('Request ID: '.environment_cli_request_id());
        expect($output)->not->toContain('environment-secret-sentinel');
    });

    it('renders the Gateway import-conflict message instead of an HTTP status wrapper', function (): void {
        MockClient::global([
            ImportAppInstanceEnvironmentRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => 'env.import_conflict',
                        'message' => 'The import contains keys that are already stored.',
                        'details' => [],
                    ],
                ],
                409,
                ['X-Orbit-Request-Id' => environment_cli_request_id()],
            ),
        ]);

        $exitCode = Artisan::call('env:import', [
            '--instance' => '17',
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)->toBe(1);
        expect(trim(Artisan::output()))
            ->toBe(environment_cli_error_json(
                code: 'env.import_conflict',
                message: 'The import contains keys that are already stored.',
                requestId: environment_cli_request_id(),
            ))
            ->not->toContain('Gateway environment operation failed with HTTP status');
    });

    it('renders the Gateway not-found message instead of an HTTP status wrapper', function (): void {
        MockClient::global([
            ImportAppInstanceEnvironmentRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => 'http.404',
                        'message' => 'Resource not found.',
                        'details' => [],
                    ],
                ],
                404,
                ['X-Orbit-Request-Id' => environment_cli_request_id()],
            ),
        ]);

        $exitCode = Artisan::call('env:import', [
            '--instance' => '999999',
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)->toBe(1);
        expect(trim(Artisan::output()))
            ->toBe(environment_cli_error_json(
                code: 'http.404',
                message: 'Resource not found.',
                requestId: environment_cli_request_id(),
            ))
            ->not->toContain('Gateway environment operation failed with HTTP status');
    });

    it('renders a bounded transport failure without exception-chain diagnostics or the submitted value', function (): void {
        MockClient::global([
            UpdateAppInstanceEnvironmentRequest::class => static function (PendingRequest $pendingRequest): never {
                throw new FatalRequestException(
                    new RuntimeException('transport failed with environment-secret-sentinel'),
                    $pendingRequest,
                );
            },
        ]);

        $exitCode = Artisan::call('env:update', [
            '--instance' => 'app.com',
            '--key' => 'PRIVATE_VALUE',
            '--value' => 'environment-secret-sentinel',
            '--json' => true,
            '--no-interaction' => true,
            '--verbose' => true,
        ]);

        expect($exitCode)->toBe(1);
        expect(trim(Artisan::output()))
            ->toBe(environment_cli_error_json(
                code: 'gateway.request_failed',
                message: 'Gateway environment operation failed before receiving a response.',
            ))
            ->not->toContain('environment-secret-sentinel')
            ->not->toContain('FatalRequestException')
            ->not->toContain('RuntimeException');
    });
});

function environment_cli_response(string $operation, bool $changed = true): MockResponse
{
    return MockResponse::make([
        'data' => [
            'app_instance_id' => 17,
            'operation' => $operation,
            'changed' => $changed,
            'key_count' => 3,
        ],
        'meta' => ['request_id' => environment_cli_request_id()],
    ]);
}

function environment_cli_result_json(string $operation, ?bool $workloadFileChanged = null): string
{
    $payload = [
        'app_instance_id' => 17,
        'operation' => $operation,
        'changed' => true,
        'key_count' => 3,
        'request_id' => environment_cli_request_id(),
    ];

    if ($workloadFileChanged !== null) {
        $payload['workload_file_changed'] = $workloadFileChanged;
    }

    return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

/** @param array<string, string> $details */
function environment_cli_error_json(
    string $code,
    string $message = 'Command input is invalid.',
    ?string $requestId = null,
    array $details = [],
): string {
    $messages = [
        'env.instance_required' => 'AppInstance ID or Route domain is required.',
        'env.key_required' => 'Environment key is required.',
        'env.value_required' => 'Environment value is required.',
    ];

    $error = [
        'code' => $code,
        'message' => $messages[$code] ?? $message,
    ];

    if ($details !== []) {
        $error['details'] = $details;
    }

    $error['request_id'] = $requestId;

    return json_encode(['error' => $error], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function environment_cli_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
