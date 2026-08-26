<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\GatewayRootCaClient;
use Orbit\Sdk\Requests\Apps\CreateAppRequest;
use Orbit\Sdk\Requests\Processes\AddProcessRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/** @mago-expect lint:halstead Security-boundary assertions stay visible together. */
describe('gateway object-state boundary', function (): void {
    it('hides app request credentials while preserving the exact fake transport body', function (): void {
        $repositoryCredential = gateway_object_state_credential('repository');
        $defaultCredential = gateway_object_state_credential('defaults');
        $repositoryUrl = "https://operator:{$repositoryCredential}@git.example.test/orbit.git?access_token={$repositoryCredential}";
        $defaults = [
            'services' => [[
                'name' => 'web',
                'environment' => [
                    'DATABASE_URL' => "postgres://orbit:{$defaultCredential}@database.test/orbit",
                    'APP_KEY' => $defaultCredential,
                ],
            ]],
        ];
        $expectedBody = [
            'name' => 'Orbit',
            'slug' => 'orbit',
            'repository_url' => $repositoryUrl,
            'defaults' => $defaults,
        ];
        $request = new CreateAppRequest(
            slug: 'orbit',
            repositoryUrl: $repositoryUrl,
            name: 'Orbit',
            defaults: $defaults,
        );
        $needles = [
            'repository credential' => $repositoryCredential,
            'defaults credential' => $defaultCredential,
            'repository URL' => $repositoryUrl,
        ];
        $debugBeforeBody = gateway_object_state_debug_outputs($request);

        expect(gateway_object_state_leaks($debugBeforeBody, $needles))->toBeEmpty();
        expect($request->body()->all())->toBe($expectedBody);

        $debugAfterBody = gateway_object_state_debug_outputs($request);
        expect(gateway_object_state_leaks($debugAfterBody, $needles))->toBeEmpty();
        $serializationException = gateway_object_state_serialization_exception($request);

        expect($serializationException->getMessage())->toBe('Orbit gateway requests cannot be serialized.');
        expect(gateway_object_state_leaks([
            'serialization message' => $serializationException->getMessage(),
            'serialization string' => (string) $serializationException,
            'serialization SDK trace' => gateway_object_state_sdk_trace($serializationException),
        ], $needles))->toBeEmpty();

        $constructorException = gateway_object_state_app_constructor_exception($repositoryUrl, $defaults);
        $constructorTrace = gateway_object_state_sdk_trace($constructorException);

        expect($constructorTrace)->toContain('SensitiveParameterValue');
        expect(gateway_object_state_leaks([
            'constructor message' => $constructorException->getMessage(),
            'constructor string' => (string) $constructorException,
            'constructor SDK trace' => $constructorTrace,
        ], $needles))->toBeEmpty();

        $mockClient = new MockClient([MockResponse::make(['data' => []])]);
        $connector = new GatewayConnector('https://gateway.test');
        $connector->withMockClient($mockClient);
        $connector->send($request);

        expect($mockClient->getLastPendingRequest()?->body()?->all())->toBe($expectedBody);
    });

    it('hides process environment values while preserving the exact fake transport body', function (): void {
        $environmentCredential = gateway_object_state_credential('environment');
        $environment = [
            'APP_KEY' => $environmentCredential,
            'DATABASE_URL' => "postgres://orbit:{$environmentCredential}@database.test/orbit",
        ];
        $expectedBody = [
            'target_type' => 'instance',
            'target_id' => 7,
            'name' => 'worker',
            'runtime' => 'docker',
            'command' => ['php', 'artisan', 'queue:work'],
            'restart_policy' => 'unless-stopped',
            'start' => true,
            'environment' => $environment,
            'image' => 'orbit-worker:latest',
        ];
        $request = new AddProcessRequest(
            targetType: 'instance',
            targetId: 7,
            name: 'worker',
            runtime: 'docker',
            command: ['php', 'artisan', 'queue:work'],
            image: 'orbit-worker:latest',
            environment: $environment,
            restartPolicy: 'unless-stopped',
            start: true,
        );
        $needles = [
            'environment credential' => $environmentCredential,
            'database URL' => $environment['DATABASE_URL'],
        ];
        $debugBeforeBody = gateway_object_state_debug_outputs($request);

        expect(gateway_object_state_leaks($debugBeforeBody, $needles))->toBeEmpty();
        expect($request->body()->all())->toBe($expectedBody);

        $debugAfterBody = gateway_object_state_debug_outputs($request);
        expect(gateway_object_state_leaks($debugAfterBody, $needles))->toBeEmpty();
        $serializationException = gateway_object_state_serialization_exception($request);

        expect($serializationException->getMessage())->toBe('Orbit gateway requests cannot be serialized.');
        expect(gateway_object_state_leaks([
            'serialization message' => $serializationException->getMessage(),
            'serialization string' => (string) $serializationException,
            'serialization SDK trace' => gateway_object_state_sdk_trace($serializationException),
        ], $needles))->toBeEmpty();

        $constructorException = gateway_object_state_process_constructor_exception($environment);
        $constructorTrace = gateway_object_state_sdk_trace($constructorException);

        expect($constructorTrace)->toContain('SensitiveParameterValue');
        expect(gateway_object_state_leaks([
            'constructor message' => $constructorException->getMessage(),
            'constructor string' => (string) $constructorException,
            'constructor SDK trace' => $constructorTrace,
        ], $needles))->toBeEmpty();

        $mockClient = new MockClient([MockResponse::make(['data' => []])]);
        $connector = new GatewayConnector('https://gateway.test');
        $connector->withMockClient($mockClient);
        $connector->send($request);

        expect($mockClient->getLastPendingRequest()?->body()?->all())->toBe($expectedBody);
    });

    it('rejects an unsafe connector URL before a diagnostic operation can receive it', function (string $operation): void {
        $credential = gateway_object_state_credential($operation);
        $control = "line\r\nX-Orbit-Token: {$credential}";
        $query = http_build_query(
            [
                'defaults' => [
                    'services' => [[
                        'name' => 'gateway',
                        'token' => $credential,
                    ]],
                ],
                'control' => $control,
            ],
            encoding_type: PHP_QUERY_RFC3986,
        );
        $gatewayUrl = "https://operator:{$credential}@gateway.test?{$query}";

        try {
            gateway_object_state_connector_operation($operation, $gatewayUrl);
            $this->fail('Expected unsafe gateway origin rejection.');
        } catch (InvalidArgumentException $exception) {
            $sdkTrace = gateway_object_state_sdk_trace($exception);

            expect($exception->getMessage())
                ->toBe('Gateway connector requires a safe HTTPS origin.')
                ->and($sdkTrace)
                ->toContain('SensitiveParameterValue');
            expect(gateway_object_state_leaks([
                'exception message' => $exception->getMessage(),
                'exception string' => (string) $exception,
                'exception SDK trace' => $sdkTrace,
            ], [
                'credential' => $credential,
                'control' => $control,
                'query' => $query,
                'gateway URL' => $gatewayUrl,
                'encoded control' => rawurlencode($control),
            ]))->toBeEmpty();
        }
    })->with([
        'print_r' => ['print_r'],
        'var_dump' => ['var_dump'],
        'serialize' => ['serialize'],
    ]);

    it('normalizes safe HTTPS connector origins', function (string $origin, string $expected): void {
        expect(new GatewayConnector($origin)->resolveBaseUrl())->toBe($expected);
    })->with([
        'domain' => ['https://gateway.test/', 'https://gateway.test'],
        'case-insensitive HTTPS scheme' => ['HTTPS://gateway.test/', 'https://gateway.test'],
        'IPv4 and port' => ['https://10.44.0.1:8443', 'https://10.44.0.1:8443'],
        'IPv6' => ['https://[fd00::1]', 'https://[fd00::1]'],
    ]);

    it('hides connector trust and resolver state while preserving transport config', function (): void {
        $caPathCredential = gateway_object_state_credential('ca-path');
        $resolverCredential = gateway_object_state_credential('resolver');
        $caPemPath = "/private/{$caPathCredential}/gateway-root.pem";
        $requestIdResolver = static fn (): string => $resolverCredential === ''
            ? '11111111-1111-4111-8111-111111111111'
            : '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
        $connector = new GatewayConnector(
            baseUrl: 'https://gateway.test',
            caPemPath: $caPemPath,
            requestIdResolver: $requestIdResolver,
        );

        $needles = [
            'CA path credential' => $caPathCredential,
            'CA PEM path' => $caPemPath,
            'resolver credential' => $resolverCredential,
        ];
        $debugOutput = gateway_object_state_debug_outputs($connector);
        expect(gateway_object_state_leaks($debugOutput, $needles))->toBeEmpty();
        expect($connector->config()->all())->toMatchArray([
            'allow_redirects' => false,
            'connect_timeout' => 10,
            'timeout' => 900,
            'verify' => $caPemPath,
        ]);
        $serializationException = gateway_object_state_serialization_exception($connector);

        expect($serializationException->getMessage())->toBe('Orbit gateway connectors cannot be serialized.');
        expect(gateway_object_state_leaks([
            'serialization message' => $serializationException->getMessage(),
            'serialization string' => (string) $serializationException,
            'serialization SDK trace' => gateway_object_state_sdk_trace($serializationException),
        ], $needles))->toBeEmpty();

        $constructorException = gateway_object_state_connector_constructor_exception(
            caPemPath: $caPemPath,
            requestIdResolver: $requestIdResolver,
        );
        $constructorTrace = gateway_object_state_sdk_trace($constructorException);

        expect($constructorTrace)->toContain('SensitiveParameterValue');
        expect(gateway_object_state_leaks([
            'constructor message' => $constructorException->getMessage(),
            'constructor string' => (string) $constructorException,
            'constructor SDK trace' => $constructorTrace,
        ], $needles))->toBeEmpty();
    });

    it('hides root CA resolver state and rejects client serialization before bootstrap', function (): void {
        $credential = gateway_object_state_credential('root-ca-resolver');
        $client = new GatewayRootCaClient(
            requestIdResolver: static fn (): string => $credential === ''
                ? '11111111-1111-4111-8111-111111111111'
                : '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        );

        $needles = ['resolver credential' => $credential];
        $debugOutput = gateway_object_state_debug_outputs($client);
        expect(gateway_object_state_leaks($debugOutput, $needles))->toBeEmpty();
        $serializationException = gateway_object_state_serialization_exception($client);

        expect($serializationException->getMessage())->toBe('Orbit gateway root CA clients cannot be serialized.');
        expect(gateway_object_state_leaks([
            'serialization message' => $serializationException->getMessage(),
            'serialization string' => (string) $serializationException,
            'serialization SDK trace' => gateway_object_state_sdk_trace($serializationException),
        ], $needles))->toBeEmpty();
    });

    it('rejects inherited raw Saloon debugging', function (string $target, string $operation): void {
        $transport = $target === 'request'
            ? new CreateAppRequest('orbit', 'https://git.example.test/orbit.git')
            : new GatewayConnector('https://gateway.test');

        expect(fn (): object => gateway_object_state_raw_debug_operation($transport, $operation))
            ->toThrow(LogicException::class, 'Orbit SDK raw transport debugging is disabled.');
    })->with([
        'request debug' => ['request', 'debug'],
        'request debugRequest' => ['request', 'debugRequest'],
        'request debugResponse' => ['request', 'debugResponse'],
        'connector debug' => ['connector', 'debug'],
        'connector debugRequest' => ['connector', 'debugRequest'],
        'connector debugResponse' => ['connector', 'debugResponse'],
    ]);

    it('rejects crafted transport object unserialization', function (string $class, string $message): void {
        $serialized = sprintf('O:%d:"%s":0:{}', strlen($class), $class);

        expect(fn (): mixed => unserialize($serialized))
            ->toThrow(LogicException::class, $message);
    })->with([
        'request' => [CreateAppRequest::class, 'Orbit gateway requests cannot be unserialized.'],
        'connector' => [GatewayConnector::class, 'Orbit gateway connectors cannot be unserialized.'],
        'root CA client' => [GatewayRootCaClient::class, 'Orbit gateway root CA clients cannot be unserialized.'],
    ]);
});

function gateway_object_state_credential(string $label): string
{
    return "{$label}-object-state-credential";
}

/** @return array{print_r: string, var_dump: string} */
function gateway_object_state_debug_outputs(object $value): array
{
    ob_start();
    /** @mago-expect lint:no-debug-symbols Executing regression captures the diagnostic boundary. */
    var_dump($value);
    $varDump = ob_get_clean();

    if (! is_string($varDump)) {
        throw new RuntimeException('Could not capture gateway object debug output.');
    }

    return [
        'print_r' => print_r($value, return: true),
        'var_dump' => $varDump,
    ];
}

function gateway_object_state_serialization_exception(
    GatewayRequest|GatewayConnector|GatewayRootCaClient $transport,
): LogicException {
    try {
        serialize($transport);
    } catch (LogicException $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected gateway request serialization to fail closed.');
}

/** @param array<string, mixed> $defaults */
function gateway_object_state_app_constructor_exception(string $repositoryUrl, array $defaults): TypeError
{
    try {
        new CreateAppRequest(
            slug: 'orbit',
            repositoryUrl: $repositoryUrl,
            name: [],
            defaults: $defaults,
        );
    } catch (TypeError $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected the app request constructor to reject an invalid name.');
}

/** @param array<string, string> $environment */
function gateway_object_state_process_constructor_exception(array $environment): TypeError
{
    try {
        new AddProcessRequest(
            targetType: 'instance',
            targetId: 7,
            name: 'worker',
            runtime: 'docker',
            command: ['php', 'artisan', 'queue:work'],
            environment: $environment,
            start: [],
        );
    } catch (TypeError $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected the process request constructor to reject an invalid start value.');
}

/** @param Closure(): string $requestIdResolver */
function gateway_object_state_connector_constructor_exception(
    string $caPemPath,
    Closure $requestIdResolver,
): TypeError {
    try {
        new GatewayConnector(
            baseUrl: 'https://gateway.test',
            caPemPath: $caPemPath,
            timeout: [],
            requestIdResolver: $requestIdResolver,
        );
    } catch (TypeError $exception) {
        return $exception;
    }

    throw new RuntimeException('Expected the gateway connector constructor to reject an invalid timeout.');
}

function gateway_object_state_connector_operation(string $operation, string $gatewayUrl): void
{
    match ($operation) {
        'print_r' => print_r(new GatewayConnector($gatewayUrl), return: true),
        /** @mago-expect lint:no-debug-symbols Executing regression exercises pre-diagnostic rejection. */
        'var_dump' => var_dump(new GatewayConnector($gatewayUrl)),
        'serialize' => serialize(new GatewayConnector($gatewayUrl)),
        default => throw new InvalidArgumentException('Unknown connector diagnostic operation.'),
    };
}

function gateway_object_state_raw_debug_operation(object $transport, string $operation): object
{
    if (! $transport instanceof GatewayRequest && ! $transport instanceof GatewayConnector) {
        throw new InvalidArgumentException('Unknown gateway transport object.');
    }

    return match ($operation) {
        'debug' => $transport->debug(),
        'debugRequest' => $transport->debugRequest(),
        'debugResponse' => $transport->debugResponse(),
        default => throw new InvalidArgumentException('Unknown gateway debug operation.'),
    };
}

function gateway_object_state_sdk_trace(Throwable $exception): string
{
    $frames = array_values(array_filter(
        $exception->getTrace(),
        static fn (array $frame): bool => (
            array_key_exists('class', $frame)
            && is_string($frame['class'])
            && str_starts_with($frame['class'], 'Orbit\\Sdk\\')
        ),
    ));

    return print_r($frames, return: true);
}

/**
 * @param array<string, string> $surfaces
 * @param array<string, string> $needles
 * @return list<string>
 */
function gateway_object_state_leaks(array $surfaces, array $needles): array
{
    $leaks = [];

    foreach ($surfaces as $surface => $diagnostics) {
        foreach ($needles as $label => $needle) {
            if (! str_contains($diagnostics, $needle)) {
                continue;
            }

            $leaks[] = "{$surface}: {$label}";
        }
    }

    return $leaks;
}
