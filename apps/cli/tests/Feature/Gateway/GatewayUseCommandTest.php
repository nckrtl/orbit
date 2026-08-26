<?php

declare(strict_types=1);

use App\Commands\Gateway\GatewayUseCommand;
use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe(GatewayUseCommand::class, function (): void {
    it('changes the active gateway profile', function (): void {
        expect(class_exists(GatewayUseCommand::class))->toBeTrue();

        $repository = app(GatewayConfigRepository::class);
        $repository->add(new GatewayProfile('test', 'https://10.70.0.1'));
        $repository->add(new GatewayProfile('production', 'https://10.80.0.1'));

        $this
            ->artisan('gateway:use', [
                'name' => 'production',
                '--json' => true,
            ])
            ->expectsOutputToContain('"active_gateway":"production"')
            ->assertExitCode(0);

        expect($repository->active()?->name)->toBe('production');
    });

    it('rejects invalid names without exposing input or changing the active profile', function (string $name): void {
        $repository = app(GatewayConfigRepository::class);
        $repository->add(new GatewayProfile('test', 'https://10.70.0.1'));
        $before = file_get_contents($this->orbitHome.'/config.json');
        $expected = json_encode([
            'error' => [
                'code' => 'gateway.profile_invalid',
                'message' => 'Gateway profile name is invalid.',
                'request_id' => null,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $exitCode = Artisan::call('gateway:use', ['name' => $name, '--json' => true]);
        $output = trim(Artisan::output());

        expect($exitCode)->toBe(1);
        expect($output)
            ->toBe($expected)
            ->not->toContain('profile-secret');
        expect(file_get_contents($this->orbitHome.'/config.json'))
            ->toBe($before)
            ->and($repository->active()?->name)
            ->toBe('test');
    })->with([
        'control character' => "invalid\nprofile-secret",
        'credential-shaped name' => 'API_TOKEN=profile-secret',
        'path-like name' => '../profile-secret',
    ]);

    it('rejects a missing valid name without exposing input or changing the active profile', function (): void {
        $repository = app(GatewayConfigRepository::class);
        $repository->add(new GatewayProfile('test', 'https://10.70.0.1'));
        $before = file_get_contents($this->orbitHome.'/config.json');
        $expected = json_encode([
            'error' => [
                'code' => 'gateway.profile_not_found',
                'message' => 'Gateway profile does not exist.',
                'request_id' => null,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $exitCode = Artisan::call('gateway:use', ['name' => 'profile-secret', '--json' => true]);
        $output = trim(Artisan::output());

        expect($exitCode)->toBe(1);
        expect($output)
            ->toBe($expected)
            ->not->toContain('profile-secret');
        expect(file_get_contents($this->orbitHome.'/config.json'))
            ->toBe($before)
            ->and($repository->active()?->name)
            ->toBe('test');
    });
});
