<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Gateway\ShowGatewayStatusRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('gateway error envelope', function (): void {
    it('throws a typed exception with stable error details', function (): void {
        expect(class_exists(GatewayApiException::class))->toBeTrue();

        $mockClient = new MockClient([
            ShowGatewayStatusRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'gateway.unavailable',
                    'message' => 'The gateway is unavailable.',
                    'details' => ['service' => 'php-fpm'],
                ],
            ], 503),
        ]);
        $connector = new GatewayConnector('https://10.70.0.1');
        $connector->withMockClient($mockClient);

        try {
            $connector->send(new ShowGatewayStatusRequest)->dto();
            $this->fail('Expected GatewayApiException.');
        } catch (GatewayApiException $exception) {
            expect($exception->getMessage())
                ->toBe('The gateway is unavailable.')
                ->and($exception->errorCode())
                ->toBe('gateway.unavailable')
                ->and($exception->details())
                ->toBe(['service' => 'php-fpm'])
                ->and($exception->requestId())
                ->toBeNull();
        }
    });

    it('treats a blank response request ID header as absent', function (): void {
        $mockClient = new MockClient([
            ShowGatewayStatusRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => 'gateway.unavailable',
                        'message' => 'The gateway is unavailable.',
                        'details' => [],
                    ],
                ],
                503,
                ['X-Orbit-Request-Id' => ''],
            ),
        ]);
        $connector = new GatewayConnector('https://10.70.0.1');
        $connector->withMockClient($mockClient);

        try {
            $connector->send(new ShowGatewayStatusRequest)->dto();
            $this->fail('Expected GatewayApiException.');
        } catch (GatewayApiException $exception) {
            expect($exception->requestId())->toBeNull();
        }
    });

    it('captures the response request ID header on failed requests', function (): void {
        $mockClient = new MockClient([
            ShowGatewayStatusRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => 'gateway.unavailable',
                        'message' => 'The gateway is unavailable.',
                        'details' => ['service' => 'php-fpm'],
                    ],
                ],
                503,
                [
                    'x-orbit-request-id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
                ],
            ),
        ]);
        $connector = new GatewayConnector('https://10.70.0.1');
        $connector->withMockClient($mockClient);

        try {
            $connector->send(new ShowGatewayStatusRequest)->dto();
            $this->fail('Expected GatewayApiException.');
        } catch (GatewayApiException $exception) {
            expect($exception->requestId())->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a844');
        }
    });
});
