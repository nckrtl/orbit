<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Environment\ImportAppInstanceEnvironmentRequest;
use Orbit\Sdk\Requests\Environment\SynchronizeAppInstanceEnvironmentRequest;
use Orbit\Sdk\Requests\Environment\UpdateAppInstanceEnvironmentRequest;
use Saloon\Enums\Method;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

describe('AppInstance environment requests', function (): void {
    it('sends the three exact methods routes and JSON object payloads', function (): void {
        $cases = [
            [
                new ImportAppInstanceEnvironmentRequest(17),
                Method::POST,
                '/api/v1/instances/17/environment/import',
                [],
                '{}',
            ],
            [
                new ImportAppInstanceEnvironmentRequest('app.example.test', false),
                Method::POST,
                '/api/v1/instances/app.example.test/environment/import',
                ['replace' => false],
                '{"replace":false}',
            ],
            [
                new UpdateAppInstanceEnvironmentRequest(17, 'FEATURE_FLAG', 'false'),
                Method::PUT,
                '/api/v1/instances/17/environment/FEATURE_FLAG',
                ['value' => 'false'],
                '{"value":"false"}',
            ],
            [
                new SynchronizeAppInstanceEnvironmentRequest('app.example.test'),
                Method::POST,
                '/api/v1/instances/app.example.test/environment/sync',
                [],
                '{}',
            ],
        ];

        foreach ($cases as [$request, $method, $endpoint, $body, $serializedBody]) {
            $mock = new MockClient([
                $request::class => MockResponse::make(environment_success_envelope(environment_operation($request))),
            ]);
            $connector = environment_connector($mock);
            $connector->send($request)->dto();
            $pending = $mock->getLastPendingRequest();

            expect($request->getMethod())
                ->toBe($method)
                ->and($request->resolveEndpoint())
                ->toBe($endpoint)
                ->and($request->body()->all())
                ->toBe($body)
                ->and((string) $pending?->createPsrRequest()->getBody())
                ->toBe($serializedBody)
                ->and($pending?->headers()->get('Content-Type'))
                ->toBe('application/json');
        }
    });

    it('encodes selector and key segments exactly once without resolving either input', function (): void {
        expect(new ImportAppInstanceEnvironmentRequest('blue/green.example.test')->resolveEndpoint())
            ->toBe('/api/v1/instances/blue%2Fgreen.example.test/environment/import')
            ->and(new SynchronizeAppInstanceEnvironmentRequest('blue%2Fgreen.example.test')->resolveEndpoint())
            ->toBe('/api/v1/instances/blue%252Fgreen.example.test/environment/sync')
            ->and(
                new UpdateAppInstanceEnvironmentRequest(
                    'blue%2Fgreen.example.test',
                    'FEATURE%2FFLAG/ONE',
                    'value',
                )->resolveEndpoint(),
            )
            ->toBe('/api/v1/instances/blue%252Fgreen.example.test/environment/FEATURE%252FFLAG%2FONE');
    });

    it('preserves omitted replace separately from explicit false', function (): void {
        expect(new ImportAppInstanceEnvironmentRequest(17)->body()->all())
            ->toBeEmpty()
            ->and(new ImportAppInstanceEnvironmentRequest(17, false)->body()->all())
            ->toBe(['replace' => false])
            ->and(new ImportAppInstanceEnvironmentRequest(17, true)->body()->all())
            ->toBe(['replace' => true]);
    });

    it('preserves every string value for Gateway validation', function (string $value): void {
        $request = new UpdateAppInstanceEnvironmentRequest('app.example.test', 'VALUE', $value);

        expect($request->body()->all())->toBe(['value' => $value]);
    })->with([
        'empty' => [''],
        'multiline' => ["first line\nsecond line"],
        'false string' => ['false'],
        'zero string' => ['0'],
        'placeholder' => ['prefix-{{app_instance.hostname}}-{{app_instance.environment}}'],
    ]);

    it('keeps an arbitrary submitted value only in intended body serialization', function (): void {
        $value = 'plain-environment-sentinel-7f4c';
        $request = new UpdateAppInstanceEnvironmentRequest('app.example.test', 'VISIBLE_NAME', $value);

        ob_start();
        var_dump($request);
        $dump = ob_get_clean();

        if (! is_string($dump)) {
            $this->fail('Could not capture request debug output.');
        }

        try {
            serialize($request);
            $this->fail('Expected request serialization to fail closed.');
        } catch (LogicException $exception) {
            $diagnostics = implode("\n", [
                print_r($request, return: true),
                $dump,
                (string) json_encode($request, JSON_THROW_ON_ERROR),
                $exception->getMessage(),
                (string) $exception,
                print_r($exception->getTrace(), return: true),
            ]);

            expect($diagnostics)->not->toContain($value);
        }

        $mock = new MockClient([
            UpdateAppInstanceEnvironmentRequest::class => MockResponse::make(
                environment_success_envelope('update'),
            ),
        ]);
        environment_connector($mock)->send($request)->dto();

        $transportResponse = $mock->getLastResponse();

        if ($transportResponse === null) {
            $this->fail('Expected a recorded environment response.');
        }

        ob_start();
        var_dump($transportResponse);
        $responseDump = ob_get_clean();

        if (! is_string($responseDump)) {
            $this->fail('Could not capture response debug output.');
        }

        try {
            serialize($transportResponse);
            $this->fail('Expected response serialization to fail closed.');
        } catch (LogicException $exception) {
            expect(implode("\n", [
                print_r($transportResponse, return: true),
                $responseDump,
                (string) json_encode($transportResponse, JSON_THROW_ON_ERROR),
                $exception->getMessage(),
                (string) $exception,
            ]))
                ->not
                ->toContain($value);
        }

        expect($request->body()->all())
            ->toBe(['value' => $value])
            ->and((string) $mock->getLastPendingRequest()?->createPsrRequest()->getBody())
            ->toBe('{"value":"plain-environment-sentinel-7f4c"}');

        $valueParameter = new ReflectionParameter([UpdateAppInstanceEnvironmentRequest::class, '__construct'], 'value');
        expect($valueParameter->getAttributes(SensitiveParameter::class))->toHaveCount(1);
    });

    it('sanitizes a real fatal transport failure without changing the intended body', function (): void {
        $value = 'plain-live-transport-sentinel-150f';
        $serializedBody = null;
        $request = environment_update_with_body_capture($value, $serializedBody);
        $connector = new GatewayConnector('https://127.0.0.1:1', timeout: 1);

        try {
            $connector->send($request);
            $this->fail('Expected a refused environment transport connection.');
        } catch (Throwable $exception) {
            assert_safe_environment_transport_failure($exception, $value);
        }

        expect($serializedBody)->toBe('{"value":"plain-live-transport-sentinel-150f"}');
    });

    it('sanitizes a real asynchronous fatal transport failure without changing the intended body', function (): void {
        $value = 'plain-async-transport-sentinel-9d31';
        $serializedBody = null;
        $request = environment_update_with_body_capture($value, $serializedBody);
        $connector = new GatewayConnector('https://127.0.0.1:1', timeout: 1);

        try {
            $connector->sendAsync($request)->wait();
            $this->fail('Expected a refused asynchronous environment transport connection.');
        } catch (Throwable $exception) {
            assert_safe_environment_transport_failure($exception, $value);
        }

        expect($serializedBody)->toBe('{"value":"plain-async-transport-sentinel-9d31"}');
    });

    it('sanitizes a real pooled fatal transport failure without changing the intended body', function (): void {
        $value = 'plain-pool-transport-sentinel-f2a7';
        $serializedBody = null;
        $failure = null;
        $request = environment_update_with_body_capture($value, $serializedBody);
        $connector = new GatewayConnector('https://127.0.0.1:1', timeout: 1);
        $connector
            ->pool(
                requests: ['environment' => $request],
                concurrency: 1,
                exceptionHandler: static function (#[SensitiveParameter] mixed $reason) use (&$failure): void {
                    $failure = $reason;
                },
            )
            ->send()
            ->wait();

        expect($failure)->toBeInstanceOf(Throwable::class);

        if (! $failure instanceof Throwable) {
            $this->fail('Expected a pooled environment transport failure.');
        }

        assert_safe_environment_transport_failure($failure, $value);
        expect($serializedBody)->toBe('{"value":"plain-pool-transport-sentinel-f2a7"}');
    });
});

function environment_connector(MockClient $mock): GatewayConnector
{
    $connector = new GatewayConnector('https://gateway.test');
    $connector->withMockClient($mock);

    return $connector;
}

function environment_request_sdk_trace(Throwable $exception): string
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

function environment_update_with_body_capture(
    #[SensitiveParameter]
    string $value,
    ?string &$serializedBody,
): UpdateAppInstanceEnvironmentRequest {
    $request = new UpdateAppInstanceEnvironmentRequest('app.example.test', 'VISIBLE_NAME', $value);
    $request->middleware()->onRequest(
        static function (#[SensitiveParameter] PendingRequest $pendingRequest) use (&$serializedBody): void {
            $serializedBody = (string) $pendingRequest->createPsrRequest()->getBody();
        },
        'captureEnvironmentRequestBody',
    );

    return $request;
}

function assert_safe_environment_transport_failure(
    Throwable $exception,
    #[SensitiveParameter]
    string $value,
): void {
    expect($exception)->toBeInstanceOf(GatewayApiException::class);

    if (! $exception instanceof GatewayApiException) {
        throw new RuntimeException('Expected a sanitized environment transport failure.');
    }

    $diagnostics = implode("\n", [
        $exception->getMessage(),
        (string) $exception,
        print_r($exception, return: true),
        environment_request_sdk_trace($exception),
    ]);

    expect($exception)
        ->not->toBeInstanceOf(FatalRequestException::class)->and($exception->getMessage())->toBe(
            'Gateway environment operation failed before receiving a response.',
        )->and($exception->errorCode())->toBeNull()->and($exception->requestId())->toBeNull()->and(
            $exception->details(),
        )->toBeEmpty()->and($exception->getPrevious())->toBeNull()->and(method_exists(
            $exception,
            'getPendingRequest',
        ))->toBeFalse()->and($diagnostics)
        ->not->toContain($value);
}

function environment_operation(GatewayRequest $request): string
{
    return match ($request::class) {
        ImportAppInstanceEnvironmentRequest::class => 'import',
        UpdateAppInstanceEnvironmentRequest::class => 'update',
        SynchronizeAppInstanceEnvironmentRequest::class => 'sync',
        default => throw new InvalidArgumentException('Unknown environment request.'),
    };
}

/** @return array{data: array{app_instance_id: int, operation: string, changed: bool, key_count: int}, meta: array{request_id: string}} */
function environment_success_envelope(string $operation): array
{
    return [
        'data' => [
            'app_instance_id' => 17,
            'operation' => $operation,
            'changed' => true,
            'key_count' => 3,
        ],
        'meta' => ['request_id' => '11111111-1111-4111-8111-111111111111'],
    ];
}
