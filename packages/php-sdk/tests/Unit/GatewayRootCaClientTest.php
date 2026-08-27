<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRootCaClient;
use Orbit\Sdk\Requests\Gateway\FetchRootCaCertificateRequest;
use Orbit\Sdk\Responses\Gateway\RootCaCertificateResponse;
use Orbit\Sdk\Support\GatewayRequestId;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('fetches only the root CA endpoint through a non-verifying HTTPS bootstrap transport', function (): void {
    $mockClient = MockClient::global([
        FetchRootCaCertificateRequest::class => gateway_root_ca_mock_response(),
    ]);

    $response = new GatewayRootCaClient()->fetch('https://10.44.0.1:8443');
    $pendingRequest = $mockClient->getLastPendingRequest();
    /** @mago-expect analysis:mixed-assignment Saloon header values are dynamically typed. */
    $requestId = $pendingRequest?->headers()->get('X-Orbit-Request-Id');

    expect($response)
        ->toBeInstanceOf(RootCaCertificateResponse::class)
        ->and($pendingRequest?->getUrl())
        ->toBe('https://10.44.0.1:8443/api/v1/ca/root')
        ->and($requestId)
        ->toBeString()
        ->and(GatewayRequestId::fromTransport($requestId))
        ->toBe($requestId)
        ->and($requestId)
        ->toMatch('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD')
        ->and($pendingRequest?->config()->all())
        ->toMatchArray([
            'allow_redirects' => false,
            'connect_timeout' => 10,
            'timeout' => 10,
            'verify' => false,
        ]);
});

it('uses an injected request ID resolver for the first bootstrap request', function (): void {
    $requestId = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
    $mockClient = MockClient::global([
        FetchRootCaCertificateRequest::class => gateway_root_ca_mock_response(),
    ]);

    new GatewayRootCaClient(
        requestIdResolver: static fn (): string => $requestId,
    )->fetch('https://10.44.0.1:8443');

    expect($mockClient->getLastPendingRequest()?->headers()->get('X-Orbit-Request-Id'))
        ->toBe($requestId);
});

it('discards resolver state after one valid-origin bootstrap attempt', function (
    Closure $response,
    ?string $firstFailureClass,
): void {
    $credential = substr(hash('sha256', __METHOD__), offset: 0, length: 20);
    $resolverState = new class($credential) {
        public function __construct(
            public readonly string $credential,
        ) {}

        public function requestId(): string
        {
            return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
        }
    };
    $weakResolverState = WeakReference::create($resolverState);
    $mockClient = MockClient::global([
        FetchRootCaCertificateRequest::class => $response(),
    ]);
    $requestIdResolver = $resolverState->requestId(...);
    $client = new GatewayRootCaClient(
        requestIdResolver: $requestIdResolver,
    );
    unset($requestIdResolver, $resolverState);

    $firstException = null;

    try {
        $client->fetch('https://10.44.0.1:8443');
    } catch (Throwable $exception) {
        $firstException = $exception;
    }

    if ($firstFailureClass === null) {
        expect($firstException)->toBeNull();
    }

    if ($firstFailureClass !== null) {
        expect($firstException)->toBeInstanceOf($firstFailureClass);
    }

    gc_collect_cycles();

    expect($weakResolverState->get())
        ->toBeNull()
        ->and(print_r($client, return: true))
        ->not->toContain($credential);

    if ($firstException instanceof Throwable) {
        expect(gateway_root_ca_exception_leak_sources($firstException, [$credential]))
            ->toBeEmpty();
    }

    try {
        $client->fetch('https://10.44.0.1:8443');
        $this->fail('Expected the one-shot resolver to be unavailable.');
    } catch (UnexpectedValueException $exception) {
        expect($exception->getMessage())
            ->toBe('Gateway request ID resolver failed.');
        expect(gateway_root_ca_exception_leak_sources($exception, [$credential]))
            ->toBeEmpty();
    }

    $mockClient->assertSentCount(1, FetchRootCaCertificateRequest::class);
})->with([
    'successful response' => [
        static fn (): MockResponse => gateway_root_ca_mock_response(),
        null,
    ],
    'gateway failure' => [
        static fn (): MockResponse => MockResponse::make([
            'error' => [
                'code' => 'gateway.bootstrap_failed',
                'message' => 'Gateway bootstrap failed.',
                'details' => [],
            ],
        ], 503),
        GatewayApiException::class,
    ],
]);

it('does not consume the resolver when the bootstrap origin is invalid', function (): void {
    $resolverCalls = 0;
    $mockClient = MockClient::global([
        FetchRootCaCertificateRequest::class => gateway_root_ca_mock_response(),
    ]);
    $client = new GatewayRootCaClient(
        requestIdResolver: static function () use (&$resolverCalls): string {
            $resolverCalls++;

            return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
        },
    );

    expect(fn (): RootCaCertificateResponse => $client->fetch('http://10.44.0.1'))
        ->toThrow(InvalidArgumentException::class, 'safe HTTPS origin');

    $client->fetch('https://10.44.0.1:8443');

    expect($resolverCalls)->toBe(1);
    $mockClient->assertSentCount(1, FetchRootCaCertificateRequest::class);
});

it('fails closed without retaining an invalid injected request ID', function (): void {
    $credential = substr(hash('sha256', __METHOD__), offset: 0, length: 20);
    $invalidRequestId = "request-token={$credential}\r\nX-Orbit-Control: {$credential}";
    $mockClient = MockClient::global([
        FetchRootCaCertificateRequest::class => gateway_root_ca_mock_response(),
    ]);
    $client = new GatewayRootCaClient(
        requestIdResolver: static fn (): string => $invalidRequestId,
    );

    try {
        $client->fetch('https://10.44.0.1:8443');
        $this->fail('Expected invalid request ID rejection.');
    } catch (UnexpectedValueException $exception) {
        $mockClient->assertNothingSent();

        expect($exception->getMessage())->toBe('Gateway request ID resolver returned an invalid UUID.');
        expect(gateway_root_ca_exception_has_marker(
            exception: $exception,
            needle: 'SensitiveParameterValue',
        ))
            ->toBeTrue();
        expect(gateway_root_ca_exception_leak_sources(
            exception: $exception,
            needles: [$credential, $invalidRequestId],
        ))
            ->toBeEmpty();
        expect(print_r($client, return: true))->not->toContain($credential, $invalidRequestId);
    }

    expect(fn (): RootCaCertificateResponse => $client->fetch('https://10.44.0.1:8443'))
        ->toThrow(UnexpectedValueException::class, 'Gateway request ID resolver failed.');
    $mockClient->assertNothingSent();
});

it('fails closed without retaining request ID resolver failures', function (): void {
    $credential = substr(hash('sha256', __FUNCTION__), offset: 0, length: 20);
    $mockClient = MockClient::global([
        FetchRootCaCertificateRequest::class => gateway_root_ca_mock_response(),
    ]);
    $client = new GatewayRootCaClient(
        requestIdResolver: static function () use ($credential): string {
            throw new RuntimeException("Resolver failed with token={$credential}");
        },
    );

    try {
        $client->fetch('https://10.44.0.1:8443');
        $this->fail('Expected request ID resolver failure.');
    } catch (UnexpectedValueException $exception) {
        $mockClient->assertNothingSent();

        expect($exception->getMessage())
            ->toBe('Gateway request ID resolver failed.')
            ->and($exception->getPrevious())
            ->toBeNull();
        expect(gateway_root_ca_exception_has_marker(
            exception: $exception,
            needle: 'SensitiveParameterValue',
        ))
            ->toBeTrue();
        expect(gateway_root_ca_exception_leak_sources(
            exception: $exception,
            needles: [$credential],
        ))
            ->toBeEmpty();
        expect(print_r($client, return: true))->not->toContain($credential);
    }

    expect(fn (): RootCaCertificateResponse => $client->fetch('https://10.44.0.1:8443'))
        ->toThrow(UnexpectedValueException::class, 'Gateway request ID resolver failed.');
    $mockClient->assertNothingSent();
});

it('rejects unsafe bootstrap gateway URLs', function (string $url): void {
    expect(fn (): RootCaCertificateResponse => new GatewayRootCaClient()->fetch($url))
        ->toThrow(InvalidArgumentException::class, 'safe HTTPS origin');
})->with([
    'plain HTTP' => 'http://10.44.0.1',
    'userinfo' => 'https://orbit:secret@10.44.0.1',
    'query' => 'https://10.44.0.1?redirect=evil',
    'fragment' => 'https://10.44.0.1#ca',
    'base path' => 'https://10.44.0.1/orbit',
]);

it('does not retain a rejected bootstrap URL in SDK-owned trace arguments', function (): void {
    $credential = substr(hash('sha256', __FILE__), offset: 0, length: 20);
    $control = "line\r\nX-Orbit-Token: {$credential}";
    $query =
        http_build_query(
            [
                'defaults' => [
                    'services' => [
                        ['name' => 'gateway', 'value' => $credential],
                    ],
                ],
                'control' => $control,
            ],
            encoding_type: PHP_QUERY_RFC3986,
        )."&access_token={$credential}";
    $gatewayUrl = "https://operator:{$credential}@10.44.0.1?{$query}";
    $resolverCalled = false;

    try {
        new GatewayRootCaClient(
            requestIdResolver: static function () use (&$resolverCalled): string {
                $resolverCalled = true;

                return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
            },
        )->fetch($gatewayUrl);
        $this->fail('Expected unsafe bootstrap URL rejection.');
    } catch (InvalidArgumentException $exception) {
        $trace = gateway_root_ca_sdk_trace($exception);

        expect($exception->getMessage())
            ->toBe('Gateway root CA bootstrap requires a safe HTTPS origin.')
            ->and($resolverCalled)
            ->toBeFalse();
        expect($trace)->toContain('SensitiveParameterValue');
        expect(gateway_root_ca_exception_leak_sources(
            $exception,
            [$credential, $control, $query, $gatewayUrl, rawurlencode($control)],
        ))->toBeEmpty();
    }
});

it('marks SDK URL and pending-request ingress parameters as sensitive', function (): void {
    $rootClient = new ReflectionClass(GatewayRootCaClient::class);
    $connector = new ReflectionClass(GatewayConnector::class);

    expect(gateway_root_ca_sensitive_parameter_count($rootClient->getConstructor(), parameterName: 'requestIdResolver'))
        ->toBe(1)
        ->and(gateway_root_ca_sensitive_parameter_count($rootClient->getMethod('fetch'), parameterName: 'gatewayUrl'))
        ->toBe(1)
        ->and(gateway_root_ca_sensitive_parameter_count(
            $rootClient->getMethod('safeOrigin'),
            parameterName: 'gatewayUrl',
        ))
        ->toBe(1)
        ->and(gateway_root_ca_sensitive_parameter_count($connector->getConstructor(), parameterName: 'baseUrl'))
        ->toBe(1)
        ->and(gateway_root_ca_sensitive_parameter_count(
            $connector->getConstructor(),
            parameterName: 'requestIdResolver',
        ))
        ->toBe(1)
        ->and(gateway_root_ca_sensitive_parameter_count($connector->getMethod('boot'), parameterName: 'pendingRequest'))
        ->toBe(1);
});

function gateway_root_ca_sdk_trace(Throwable $exception): string
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

function gateway_root_ca_sensitive_parameter_count(?ReflectionMethod $method, string $parameterName): int
{
    if (! $method instanceof ReflectionMethod) {
        return 0;
    }

    $parameter = array_find(
        $method->getParameters(),
        static fn (ReflectionParameter $parameter): bool => $parameter->getName() === $parameterName,
    );

    return $parameter instanceof ReflectionParameter
        ? count($parameter->getAttributes(SensitiveParameter::class))
        : 0;
}

function gateway_root_ca_exception_has_marker(Throwable $exception, string $needle): bool
{
    foreach ([
        $exception->getMessage(),
        (string) $exception,
        gateway_root_ca_sdk_trace($exception),
    ] as $diagnostic) {
        if (str_contains($diagnostic, $needle)) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<string> $needles
 * @return list<string>
 */
function gateway_root_ca_exception_leak_sources(Throwable $exception, array $needles): array
{
    $sources = [
        'message' => $exception->getMessage(),
        'string' => (string) $exception,
        'sdk_trace' => gateway_root_ca_sdk_trace($exception),
    ];
    $leaks = [];

    foreach ($sources as $name => $source) {
        foreach ($needles as $needle) {
            if ($needle === '' || ! str_contains($source, $needle)) {
                continue;
            }

            $leaks[] = $name;

            break;
        }
    }

    return $leaks;
}

function gateway_root_ca_mock_response(): MockResponse
{
    return MockResponse::make([
        'data' => [
            'root_ca' => "-----BEGIN CERTIFICATE-----\nROOT\n-----END CERTIFICATE-----\n",
            'sha256' => '8a7c80e2',
        ],
        'meta' => [
            'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ],
    ]);
}
