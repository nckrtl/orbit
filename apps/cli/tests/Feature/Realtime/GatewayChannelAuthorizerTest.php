<?php

declare(strict_types=1);

use App\Support\Realtime\GatewayChannelAuthorizer;
use App\Support\Realtime\RealtimeConnectionException;
use Illuminate\Support\Facades\Http;

describe(GatewayChannelAuthorizer::class, function (): void {
    it('posts socket_id and channel_name to the versioned gateway auth route without a bearer token', function (): void {
        Http::fake([
            'https://gateway.test/api/v1/broadcasting/auth' => Http::response(['auth' => 'app-key:signature']),
        ]);

        $caPath = sys_get_temp_dir().'/orbit-cli-realtime-ca-'.uniqid('', true).'.pem';
        file_put_contents($caPath, "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n");

        try {
            $auth = new GatewayChannelAuthorizer('https://gateway.test', $caPath)
                ->authorize('1.1', 'private-orbit');
        } finally {
            unlink($caPath);
        }

        expect($auth)->toBe('app-key:signature');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://gateway.test/api/v1/broadcasting/auth'
            && $request->method() === 'POST'
            && $request['socket_id'] === '1.1'
            && $request['channel_name'] === 'private-orbit'
            && $request->hasHeader('Accept', 'application/json')
            && ! $request->hasHeader('Authorization'));
    });

    it('fails when the gateway answers a non-success status', function (): void {
        Http::fake([
            'https://gateway.test/api/v1/broadcasting/auth' => Http::response(['error' => ['code' => 'http.404']], 404),
        ]);

        expect(fn (): string => new GatewayChannelAuthorizer('https://gateway.test', null)
            ->authorize('1.1', 'private-orbit'))
            ->toThrow(RealtimeConnectionException::class, 'Realtime channel authorization failed with HTTP status 404.');
    });
});
