<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Gateway\ShowGatewayStatusRequest;
use Orbit\Sdk\Support\GatewayRequestId;
use Saloon\Enums\Method;
use Saloon\Enums\PipeOrder;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Http\Response;

describe(GatewayConnector::class, function (): void {
    it('configures gateway trust and transport defaults', function (): void {
        expect(class_exists(GatewayConnector::class))->toBeTrue();

        $connector = new GatewayConnector(
            baseUrl: 'https://10.70.0.1',
            caPemPath: '/home/orbit/.orbit/ca/root.pem',
        );

        expect($connector->resolveBaseUrl())
            ->toBe('https://10.70.0.1')
            ->and($connector->config()->all())
            ->toMatchArray([
                'allow_redirects' => false,
                'connect_timeout' => 10,
                'timeout' => 900,
                'verify' => '/home/orbit/.orbit/ca/root.pem',
            ]);
    });

    it('does not allow callers to disable TLS verification for normal requests', function (): void {
        expect(fn (): GatewayConnector => new GatewayConnector(
            baseUrl: 'https://10.70.0.1',
            caPemPath: false,
        ))
            ->toThrow(TypeError::class);
    });

    it('adds client and request correlation headers', function (): void {
        expect(class_exists(GatewayConnector::class))->toBeTrue();

        $mockClient = new MockClient([MockResponse::make(['data' => []])]);
        $connector = new GatewayConnector(
            baseUrl: 'https://10.70.0.1',
            requestIdResolver: static fn (): string => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        );
        $connector->withMockClient($mockClient);

        $connector->send(new class extends Request {
            protected Method $method = Method::GET;

            public function resolveEndpoint(): string
            {
                return '/api/v1/probe';
            }
        });

        $headers = $mockClient->getLastPendingRequest()?->headers()->all() ?? [];

        expect($headers)
            ->toHaveKey('Accept', 'application/json')
            ->toHaveKey('X-Orbit-Client', 'cli')
            ->toHaveKey('X-Orbit-Request-Id', '0198e15c-bf97-7c23-8f1f-61b8fe67a844');
    });

    it('generates request correlation when no resolver is supplied', function (): void {
        $mockClient = new MockClient([MockResponse::make(['data' => []])]);
        $connector = new GatewayConnector('https://10.70.0.1');
        $connector->withMockClient($mockClient);

        $connector->send(gateway_connector_probe_request());

        $requestId = $mockClient->getLastPendingRequest()?->headers()->get('X-Orbit-Request-Id');

        expect($requestId)
            ->toBeString()
            ->and(GatewayRequestId::fromTransport($requestId))
            ->toBe($requestId);
    });

    it('fails closed without retaining an invalid resolved request ID', function (): void {
        $credential = substr(hash('sha256', __METHOD__), offset: 0, length: 20);
        $invalidRequestId = "request-token={$credential}\r\nX-Orbit-Control: {$credential}";
        $mockClient = new MockClient([MockResponse::make(['data' => []])]);
        $connector = new GatewayConnector(
            baseUrl: 'https://10.70.0.1',
            requestIdResolver: static fn (): string => $invalidRequestId,
        );
        $connector->withMockClient($mockClient);

        try {
            $connector->send(gateway_connector_probe_request());
            $this->fail('Expected invalid request ID rejection.');
        } catch (UnexpectedValueException $exception) {
            $mockClient->assertNothingSent();
            $diagnostics = implode("\n", [
                $exception->getMessage(),
                (string) $exception,
                print_r($connector, return: true),
                gateway_connector_sdk_trace($exception),
            ]);

            expect($exception->getMessage())->toBe('Gateway request ID resolver returned an invalid UUID.');
            expect($diagnostics)->not->toContain($credential, $invalidRequestId);
        }

        expect(fn (): Response => $connector->send(gateway_connector_probe_request()))
            ->toThrow(UnexpectedValueException::class, 'Gateway request ID resolver failed.');
        $mockClient->assertNothingSent();
    });

    it('preserves the always-throw plugin order', function (): void {
        $lateMiddlewareRan = false;
        $mockClient = new MockClient([
            ShowGatewayStatusRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'gateway.unavailable',
                    'message' => 'The gateway is unavailable.',
                    'details' => [],
                ],
            ], 503),
        ]);
        $connector = new GatewayConnector('https://10.70.0.1');
        $connector->middleware()->onResponse(
            callable: static function (Response $response) use (&$lateMiddlewareRan): Response {
                $lateMiddlewareRan = true;

                return $response;
            },
            order: PipeOrder::LAST,
        );
        $connector->withMockClient($mockClient);

        try {
            $connector->send(new ShowGatewayStatusRequest);
            $this->fail('Expected GatewayApiException.');
        } catch (GatewayApiException) {
            expect($lateMiddlewareRan)->toBeFalse();
        }
    });
});

function gateway_connector_probe_request(): Request
{
    return new class extends Request {
        protected Method $method = Method::GET;

        public function resolveEndpoint(): string
        {
            return '/api/v1/probe';
        }
    };
}

function gateway_connector_sdk_trace(Throwable $exception): string
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
