<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Responses\Realtime\RealtimeResponse;

describe(RealtimeResponse::class, function (): void {
    it('rejects a payload with a blank channel', function (): void {
        RealtimeResponse::fromGatewayData(['url' => 'wss://reverb.orbit', 'key' => 'app-key', 'channel' => ''], '0198e15d-16c4-7855-8eb2-182b53ad28ba');
    })->throws(GatewayApiException::class);

    it('rejects a configured url without a key', function (): void {
        RealtimeResponse::fromGatewayData(['url' => 'wss://reverb.orbit', 'key' => '', 'channel' => 'orbit'], '0198e15d-16c4-7855-8eb2-182b53ad28ba');
    })->throws(GatewayApiException::class);

    it('treats a null url and key as not configured, keeping the channel', function (): void {
        $response = RealtimeResponse::fromGatewayData(['url' => null, 'key' => null, 'channel' => 'orbit'], '0198e15d-16c4-7855-8eb2-182b53ad28ba');

        expect($response->configured)->toBeFalse()
            ->and($response->url)->toBeNull()
            ->and($response->key)->toBeNull()
            ->and($response->channel)->toBe('orbit');
    });

    it('builds a not-configured response with no endpoint fields', function (): void {
        $response = RealtimeResponse::notConfigured('0198e15d-16c4-7855-8eb2-182b53ad28ba');

        expect($response->configured)->toBeFalse()
            ->and($response->url)->toBeNull()
            ->and($response->key)->toBeNull()
            ->and($response->channel)->toBe('orbit')
            ->and($response->requestId)->toBe('0198e15d-16c4-7855-8eb2-182b53ad28ba');
    });
});
