<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Gateway\ShowGatewayStatusRequest;
use Orbit\Sdk\Responses\Gateway\GatewayStatusResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe(ShowGatewayStatusRequest::class, function (): void {
    it('maps the versioned status endpoint to a typed response', function (): void {
        expect(class_exists(ShowGatewayStatusRequest::class))->toBeTrue();

        $mockClient = new MockClient([
            ShowGatewayStatusRequest::class => MockResponse::make([
                'data' => [
                    'name' => 'orbit-gateway',
                    'status' => 'ok',
                    'version' => '0.1.0',
                    'php_version' => '8.5.8',
                    'laravel_version' => '13.26.1',
                ],
                'meta' => [
                    'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
                ],
            ]),
        ]);
        $connector = new GatewayConnector('https://10.70.0.1');
        $connector->withMockClient($mockClient);

        $response = $connector->send(new ShowGatewayStatusRequest)->dto();
        $request = $mockClient->getLastRequest();

        expect($request?->resolveEndpoint())
            ->toBe('/api/v1/gateway/status')
            ->and($request?->getMethod())
            ->toBe(Method::GET)
            ->and($response)
            ->toBeInstanceOf(GatewayStatusResponse::class)
            ->and($response->name)
            ->toBe('orbit-gateway')
            ->and($response->status)
            ->toBe('ok')
            ->and($response->version)
            ->toBe('0.1.0')
            ->and($response->phpVersion)
            ->toBe('8.5.8')
            ->and($response->laravelVersion)
            ->toBe('13.26.1')
            ->and($response->requestId)
            ->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a844');
    });

    it('rejects non-string status fields instead of coercing them', function (): void {
        $credential = substr(hash('sha256', __METHOD__), offset: 0, length: 20);
        $mockClient = new MockClient([
            ShowGatewayStatusRequest::class => MockResponse::make([
                'data' => [
                    'name' => ['token' => $credential],
                    'status' => 503,
                    'version' => true,
                    'php_version' => null,
                    'laravel_version' => [],
                ],
                'meta' => [
                    'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
                ],
            ]),
        ]);
        $connector = new GatewayConnector('https://10.70.0.1');
        $connector->withMockClient($mockClient);

        $response = $connector->send(new ShowGatewayStatusRequest)->dto();

        expect($response)->toBeInstanceOf(GatewayStatusResponse::class);
        expect($response->toArray())->toBe([
            'name' => '',
            'status' => '',
            'version' => '',
            'php_version' => '',
            'laravel_version' => '',
            'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ]);
    });
});
