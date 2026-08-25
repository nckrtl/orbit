<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;

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
});
