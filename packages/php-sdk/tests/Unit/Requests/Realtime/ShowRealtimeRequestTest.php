<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Orbit\Sdk\Responses\Realtime\RealtimeResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe(ShowRealtimeRequest::class, function (): void {
    it('sends a GET to /api/v1/realtime', function (): void {
        $request = new ShowRealtimeRequest;

        expect($request->getMethod())->toBe(Method::GET)
            ->and($request->resolveEndpoint())->toBe('/api/v1/realtime');
    });

    it('maps a configured realtime endpoint', function (): void {
        $mockClient = new MockClient([
            ShowRealtimeRequest::class => MockResponse::make([
                'data' => [
                    'url' => 'wss://reverb.orbit',
                    'key' => 'app-key',
                    'channel' => 'orbit',
                ],
                'meta' => ['request_id' => '0198e15d-16c4-7855-8eb2-182b53ad28ba'],
            ]),
        ]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);

        $response = $connector->send(new ShowRealtimeRequest)->dto();

        expect($response)->toBeInstanceOf(RealtimeResponse::class)
            ->and($response->configured)->toBeTrue()
            ->and($response->url)->toBe('wss://reverb.orbit')
            ->and($response->key)->toBe('app-key')
            ->and($response->channel)->toBe('orbit')
            ->and($response->requestId)->toBe('0198e15d-16c4-7855-8eb2-182b53ad28ba')
            ->and($response->toArray())->toBe([
                'configured' => true,
                'url' => 'wss://reverb.orbit',
                'key' => 'app-key',
                'channel' => 'orbit',
                'request_id' => '0198e15d-16c4-7855-8eb2-182b53ad28ba',
            ]);
    });

    it('maps a 200 response with a null url and key to not configured', function (): void {
        $mockClient = new MockClient([
            ShowRealtimeRequest::class => MockResponse::make([
                'data' => ['url' => null, 'key' => null, 'channel' => 'orbit'],
                'meta' => ['request_id' => '0198e15d-16c4-7855-8eb2-182b53ad28ba'],
            ]),
        ]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);

        $response = $connector->send(new ShowRealtimeRequest)->dto();

        expect($response)->toBeInstanceOf(RealtimeResponse::class)
            ->and($response->configured)->toBeFalse()
            ->and($response->url)->toBeNull()
            ->and($response->key)->toBeNull()
            ->and($response->channel)->toBe('orbit');
    });

    it('treats a 404 from an older Gateway as not configured instead of a transport failure', function (): void {
        $mockClient = new MockClient([
            ShowRealtimeRequest::class => MockResponse::make([
                'error' => ['code' => 'realtime.not_configured', 'message' => 'Realtime is not configured.'],
            ], 404),
        ]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);

        $response = $connector->send(new ShowRealtimeRequest)->dto();

        expect($response)->toBeInstanceOf(RealtimeResponse::class)
            ->and($response->configured)->toBeFalse()
            ->and($response->url)->toBeNull()
            ->and($response->key)->toBeNull()
            ->and($response->channel)->toBe('orbit')
            ->and($response->toArray()['configured'])->toBeFalse();
    });
});
