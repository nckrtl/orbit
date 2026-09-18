<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Orbit\Sdk\Responses\Realtime\RealtimeResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe(ShowRealtimeRequest::class, function (): void {
    it('maps the realtime endpoint to a typed response when broadcasting is configured', function (): void {
        $mockClient = new MockClient([
            ShowRealtimeRequest::class => MockResponse::make([
                'data' => [
                    'url' => 'wss://reverb.orbit',
                    'key' => 'app-key',
                    'channel' => 'orbit',
                ],
                'meta' => [
                    'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
                ],
            ]),
        ]);
        $connector = new GatewayConnector('https://10.70.0.1');
        $connector->withMockClient($mockClient);

        $response = $connector->send(new ShowRealtimeRequest)->dto();
        $request = $mockClient->getLastRequest();

        expect($request?->resolveEndpoint())
            ->toBe('/api/v1/realtime')
            ->and($request?->getMethod())
            ->toBe(Method::GET)
            ->and($response)
            ->toBeInstanceOf(RealtimeResponse::class)
            ->and($response->url)
            ->toBe('wss://reverb.orbit')
            ->and($response->key)
            ->toBe('app-key')
            ->and($response->channel)
            ->toBe('orbit')
            ->and($response->requestId)
            ->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a844');
    });

    it('maps null connection details when broadcasting is not configured', function (): void {
        $mockClient = new MockClient([
            ShowRealtimeRequest::class => MockResponse::make([
                'data' => [
                    'url' => null,
                    'key' => null,
                    'channel' => 'orbit',
                ],
                'meta' => [
                    'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
                ],
            ]),
        ]);
        $connector = new GatewayConnector('https://10.70.0.1');
        $connector->withMockClient($mockClient);

        $response = $connector->send(new ShowRealtimeRequest)->dto();

        expect($response)->toBeInstanceOf(RealtimeResponse::class);
        expect($response->toArray())->toBe([
            'url' => null,
            'key' => null,
            'channel' => 'orbit',
            'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ]);
    });

    it('rejects non-string fields instead of coercing them', function (): void {
        $mockClient = new MockClient([
            ShowRealtimeRequest::class => MockResponse::make([
                'data' => [
                    'url' => 12345,
                    'key' => true,
                    'channel' => null,
                ],
                'meta' => [
                    'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
                ],
            ]),
        ]);
        $connector = new GatewayConnector('https://10.70.0.1');
        $connector->withMockClient($mockClient);

        $response = $connector->send(new ShowRealtimeRequest)->dto();

        expect($response)->toBeInstanceOf(RealtimeResponse::class);
        expect($response->toArray())->toBe([
            'url' => null,
            'key' => null,
            'channel' => '',
            'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ]);
    });
});
