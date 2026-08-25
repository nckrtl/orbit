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
                ->toBe(['service' => 'php-fpm']);
        }
    });
});
