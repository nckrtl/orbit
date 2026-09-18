<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Exceptions\GatewayConfigException;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->configDirectory = sys_get_temp_dir().'/orbit-cli-realtime-'.Str::uuid();
    $this->configPath = $this->configDirectory.'/config.json';
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->configDirectory);
});

describe('GatewayProfile realtime fields', function (): void {
    it('accepts a null realtime URL and key', function (): void {
        expect(GatewayProfile::hasSafeRealtimeUrl(null))->toBeTrue()
            ->and(GatewayProfile::hasValidRealtimeKey(null))->toBeTrue();
    });

    it('requires the wss scheme for a realtime URL', function (): void {
        expect(GatewayProfile::hasSafeRealtimeUrl('wss://reverb.orbit'))->toBeTrue()
            ->and(GatewayProfile::hasSafeRealtimeUrl('https://reverb.orbit'))->toBeFalse()
            ->and(GatewayProfile::hasSafeRealtimeUrl('ws://reverb.orbit'))->toBeFalse()
            ->and(GatewayProfile::hasSafeRealtimeUrl('wss://user:pass@reverb.orbit'))->toBeFalse()
            ->and(GatewayProfile::hasSafeRealtimeUrl('wss://reverb.orbit/app'))->toBeFalse()
            ->and(GatewayProfile::hasSafeRealtimeUrl(''))->toBeFalse();
    });

    it('accepts an opaque realtime key made of common key characters', function (): void {
        expect(GatewayProfile::hasValidRealtimeKey('abc123-_.XYZ'))->toBeTrue()
            ->and(GatewayProfile::hasValidRealtimeKey(''))->toBeFalse()
            ->and(GatewayProfile::hasValidRealtimeKey(str_repeat('a', 129)))->toBeFalse()
            ->and(GatewayProfile::hasValidRealtimeKey("key\nwith-newline"))->toBeFalse();
    });

    it('round trips realtime fields through fromArray/toArray', function (): void {
        $profile = GatewayProfile::fromArray('test', [
            'url' => 'https://gateway.test',
            'ca_path' => null,
            'realtime_url' => 'wss://reverb.test/',
            'realtime_key' => 'app-key',
        ]);

        expect($profile)->not->toBeNull()
            ->and($profile->realtimeUrl)->toBe('wss://reverb.test')
            ->and($profile->realtimeKey)->toBe('app-key')
            ->and($profile->toArray())->toBe([
                'url' => 'https://gateway.test',
                'ca_path' => null,
                'realtime_url' => 'wss://reverb.test',
                'realtime_key' => 'app-key',
            ]);
    });

    it('rejects a stored profile with an unsafe realtime URL', function (): void {
        expect(GatewayProfile::fromArray('test', [
            'url' => 'https://gateway.test',
            'realtime_url' => 'https://not-a-websocket.test',
            'realtime_key' => 'app-key',
        ]))->toBeNull();
    });

    it('persists realtime fields through the repository', function (): void {
        $repository = new GatewayConfigRepository($this->configPath);
        $repository->add(new GatewayProfile(
            name: 'test',
            url: 'https://gateway.test',
            realtimeUrl: 'wss://reverb.test',
            realtimeKey: 'app-key',
        ));

        $reloaded = new GatewayConfigRepository($this->configPath);

        expect($reloaded->active())
            ->toEqual(new GatewayProfile(
                name: 'test',
                url: 'https://gateway.test',
                realtimeUrl: 'wss://reverb.test',
                realtimeKey: 'app-key',
            ));
    });

    it('refuses to add a profile with an unsafe realtime URL', function (): void {
        $repository = new GatewayConfigRepository($this->configPath);

        expect(fn () => $repository->add(new GatewayProfile(
            name: 'test',
            url: 'https://gateway.test',
            realtimeUrl: 'https://not-a-websocket.test',
            realtimeKey: 'app-key',
        )))->toThrow(GatewayConfigException::class);
    });

    it('refuses to add a profile with an invalid realtime key', function (): void {
        $repository = new GatewayConfigRepository($this->configPath);

        expect(fn () => $repository->add(new GatewayProfile(
            name: 'test',
            url: 'https://gateway.test',
            realtimeUrl: 'wss://reverb.test',
            realtimeKey: "bad\nkey",
        )))->toThrow(GatewayConfigException::class);
    });

    it('keeps realtime fields on the stored profile when its CA pin is updated', function (): void {
        $repository = new GatewayConfigRepository($this->configPath);
        $expected = new GatewayProfile(
            name: 'test',
            url: 'https://gateway.test',
            realtimeUrl: 'wss://reverb.test',
            realtimeKey: 'app-key',
        );
        $repository->add($expected);

        $repository->updatePin($expected, '/home/orbit/.orbit/ca/fetched.pem');

        expect($repository->find('test'))->toEqual(new GatewayProfile(
            name: 'test',
            url: 'https://gateway.test',
            caPath: '/home/orbit/.orbit/ca/fetched.pem',
            realtimeUrl: 'wss://reverb.test',
            realtimeKey: 'app-key',
        ));
    });
});
