<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use App\Support\Realtime\WebSocketTransport;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Saloon\Http\Faking\MockClient;

beforeEach(function (): void {
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-realtime-tail-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
});

afterEach(function (): void {
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

/**
 * Every case here runs without --no-interaction and without a real TTY, which is exactly the
 * "noninteractive input" row of the CLI design standard's input-mode table: no controlling
 * terminal on stdin. That is also why every successful case below passes --json — human output
 * additionally requires a real interactive terminal, which a Pest process does not have, and
 * verifying it needs PTY evidence per the verifying-cli-output skill instead of an assertion here.
 */
describe('realtime:tail', function (): void {
    it('refuses in a non-interactive context without --json', function (): void {
        app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://gateway.test'));

        $exitCode = Artisan::call('realtime:tail');

        expect($exitCode)->toBe(1)
            ->and(str_replace("\n", '', Artisan::output()))->toContain('requires an interactive terminal or --json');
    });

    it('refuses when neither the profile nor the gateway has a realtime endpoint', function (): void {
        app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://gateway.test'));
        MockClient::global(gateway_fixture_mock('realtime/realtime-show/unconfigured'));

        $exitCode = Artisan::call('realtime:tail', ['--json' => true]);

        expect($exitCode)->toBe(1)
            ->and(Artisan::output())->toContain('"code":"realtime.not_configured"');
    });

    it('asks the gateway for the realtime endpoint when the profile has none', function (): void {
        app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://gateway.test'));
        MockClient::global(gateway_fixture_mock('realtime/realtime-show/configured'));
        Http::fake(['*/api/v1/broadcasting/auth' => Http::response(['auth' => 'app-key:signature'])]);
        app()->instance(WebSocketTransport::class, realtime_fixture_command_transport('mixed_events'));

        $exitCode = Artisan::call('realtime:tail', ['--json' => true]);

        expect($exitCode)->toBe(130)
            ->and(Artisan::output())->toContain('"type":"node.created"');
    });

    it('rejects an invalid --types filter', function (): void {
        app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://gateway.test'));

        $exitCode = Artisan::call('realtime:tail', ['--json' => true, '--types' => 'Not Valid!']);

        expect($exitCode)->toBe(1)
            ->and(Artisan::output())->toContain('"code":"input.invalid"');
    });

    it('streams every decoded event from a fixture as one JSON envelope per line', function (): void {
        app(GatewayConfigRepository::class)->add(new GatewayProfile(
            name: 'test',
            url: 'https://gateway.test',
            realtimeUrl: 'wss://reverb.test',
            realtimeKey: 'app-key',
        ));
        Http::fake(['*/api/v1/broadcasting/auth' => Http::response(['auth' => 'app-key:signature'])]);
        app()->instance(WebSocketTransport::class, realtime_fixture_command_transport('mixed_events'));

        $exitCode = Artisan::call('realtime:tail', ['--json' => true]);
        $lines = array_values(array_filter(explode("\n", trim(Artisan::output()))));

        expect($exitCode)->toBe(130);
        expect($lines)->toHaveCount(4);

        $decoded = array_map(fn (string $line): array => json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR), $lines);

        expect(array_column($decoded, 'type'))->toBe([
            'node.created',
            'process.status',
            'schedule.updated',
            'deployment.log',
        ]);
        expect($decoded[0])->toBe([
            'type' => 'node.created',
            'id' => 101,
            'at' => '2026-09-18T10:00:00+00:00',
            'data' => ['id' => 7, 'name' => 'beast', 'status' => 'provisioning'],
        ]);
        expect($decoded[3]['data'])->toBe(['line' => 'Composer install complete']);
    });

    it('only prints events matching a --types filter', function (): void {
        app(GatewayConfigRepository::class)->add(new GatewayProfile(
            name: 'test',
            url: 'https://gateway.test',
            realtimeUrl: 'wss://reverb.test',
            realtimeKey: 'app-key',
        ));
        Http::fake(['*/api/v1/broadcasting/auth' => Http::response(['auth' => 'app-key:signature'])]);
        app()->instance(WebSocketTransport::class, realtime_fixture_command_transport('mixed_events'));

        $exitCode = Artisan::call('realtime:tail', ['--json' => true, '--types' => 'node.*,process.status']);
        $lines = array_values(array_filter(explode("\n", trim(Artisan::output()))));
        $decoded = array_map(fn (string $line): array => json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR), $lines);

        expect($exitCode)->toBe(130)
            ->and(array_column($decoded, 'type'))->toBe(['node.created', 'process.status']);
    });

    it('sends the socket_id and the fixed private-orbit channel to the gateway auth endpoint', function (): void {
        app(GatewayConfigRepository::class)->add(new GatewayProfile(
            name: 'test',
            url: 'https://gateway.test',
            realtimeUrl: 'wss://reverb.test',
            realtimeKey: 'app-key',
        ));
        Http::fake(['*/api/v1/broadcasting/auth' => Http::response(['auth' => 'app-key:signature'])]);
        app()->instance(WebSocketTransport::class, realtime_fixture_command_transport('handshake'));

        Artisan::call('realtime:tail', ['--json' => true]);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://gateway.test/api/v1/broadcasting/auth'
            && $request['socket_id'] === '123.456'
            && $request['channel_name'] === 'private-orbit');
    });
});
