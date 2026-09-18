<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Support\Realtime\RealtimeConnectionConfig;

describe(RealtimeConnectionConfig::class, function (): void {
    afterEach(function (): void {
        putenv('ORBIT_REALTIME_URL');
        putenv('ORBIT_REALTIME_KEY');
    });

    it('is not configured for a null profile', function (): void {
        expect(RealtimeConnectionConfig::resolve(null, '1.0.0'))->toBeNull();
    });

    it('is not configured when the profile has neither field set', function (): void {
        $profile = new GatewayProfile('test', 'https://gateway.test');

        expect(RealtimeConnectionConfig::resolve($profile, '1.0.0'))->toBeNull();
    });

    it('is not configured when only one of the two fields is present', function (): void {
        $profile = new GatewayProfile('test', 'https://gateway.test', realtimeUrl: 'wss://reverb.test');

        expect(RealtimeConnectionConfig::resolve($profile, '1.0.0'))->toBeNull();
    });

    it('resolves the socket URL and carries the gateway URL and CA path forward', function (): void {
        $profile = new GatewayProfile(
            name: 'test',
            url: 'https://gateway.test',
            caPath: '/home/orbit/.orbit/ca/root.pem',
            realtimeUrl: 'wss://reverb.test/',
            realtimeKey: 'app-key',
        );

        $config = RealtimeConnectionConfig::resolve($profile, '2.3.4');

        expect($config)->not->toBeNull()
            ->and($config->socketUrl)->toBe('wss://reverb.test/app/app-key?protocol=7&client=orbit-cli&version=2.3.4')
            ->and($config->gatewayUrl)->toBe('https://gateway.test')
            ->and($config->caPath)->toBe('/home/orbit/.orbit/ca/root.pem');
    });

    it('percent-encodes an app key and client version that need it', function (): void {
        $profile = new GatewayProfile(
            name: 'test',
            url: 'https://gateway.test',
            realtimeUrl: 'wss://reverb.test',
            realtimeKey: 'app.key-1',
        );

        $config = RealtimeConnectionConfig::resolve($profile, 'Orbit unreleased');

        expect($config->socketUrl)->toBe('wss://reverb.test/app/app.key-1?protocol=7&client=orbit-cli&version=Orbit%20unreleased');
    });

    it('prefers the environment override over the stored profile fields', function (): void {
        putenv('ORBIT_REALTIME_URL=wss://override.test');
        putenv('ORBIT_REALTIME_KEY=override-key');
        $profile = new GatewayProfile(
            name: 'test',
            url: 'https://gateway.test',
            realtimeUrl: 'wss://reverb.test',
            realtimeKey: 'app-key',
        );

        $config = RealtimeConnectionConfig::resolve($profile, '1.0.0');

        expect($config->socketUrl)->toBe('wss://override.test/app/override-key?protocol=7&client=orbit-cli&version=1.0.0');
    });

    it('lets the environment override alone configure a profile with no stored realtime fields', function (): void {
        putenv('ORBIT_REALTIME_URL=wss://override.test');
        putenv('ORBIT_REALTIME_KEY=override-key');
        $profile = new GatewayProfile('test', 'https://gateway.test');

        expect(RealtimeConnectionConfig::resolve($profile, '1.0.0'))->not->toBeNull();
    });

    it('treats an unsafe environment override as not configured rather than crashing', function (): void {
        putenv('ORBIT_REALTIME_URL=https://not-a-websocket.test');
        putenv('ORBIT_REALTIME_KEY=app-key');
        $profile = new GatewayProfile('test', 'https://gateway.test');

        expect(RealtimeConnectionConfig::resolve($profile, '1.0.0'))->toBeNull();
    });
});
