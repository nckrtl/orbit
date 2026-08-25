<?php

declare(strict_types=1);

use App\Commands\Gateway\GatewayAddCommand;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe(GatewayAddCommand::class, function (): void {
    it('adds and activates the first gateway profile', function (): void {
        expect(class_exists(GatewayAddCommand::class))->toBeTrue();

        $this
            ->artisan('gateway:add', [
                'name' => 'test',
                'url' => 'https://10.70.0.1/',
                '--ca' => '/home/orbit/.orbit/ca/root.pem',
                '--json' => true,
            ])
            ->expectsOutputToContain('"name":"test"')
            ->assertExitCode(0);

        $profile = app(GatewayConfigRepository::class)->active();

        expect($profile?->name)
            ->toBe('test')
            ->and($profile?->url)
            ->toBe('https://10.70.0.1')
            ->and($profile?->caPath)
            ->toBe('/home/orbit/.orbit/ca/root.pem');
    });

    it('rejects a non-HTTPS gateway URL', function (): void {
        expect(class_exists(GatewayAddCommand::class))->toBeTrue();

        $this
            ->artisan('gateway:add', [
                'name' => 'test',
                'url' => 'http://10.70.0.1',
            ])
            ->expectsOutputToContain('Gateway URL must use HTTPS.')
            ->assertExitCode(1);

        expect(app(GatewayConfigRepository::class)->find('test'))->toBeNull();
    });
});
