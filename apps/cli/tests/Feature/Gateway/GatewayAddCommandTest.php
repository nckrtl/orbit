<?php

declare(strict_types=1);

use App\Commands\Gateway\GatewayAddCommand;
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

    it('rejects unsafe gateway profile input as one safe json error', function (
        array $arguments,
        string $message,
    ): void {
        $expected = json_encode([
            'error' => [
                'code' => 'gateway.profile_invalid',
                'message' => $message,
                'request_id' => null,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $exitCode = Artisan::call('gateway:add', [...$arguments, '--json' => true]);
        $output = trim(Artisan::output());

        expect($exitCode)->toBe(1);
        expect($output)
            ->toBe($expected)
            ->not->toContain('profile-secret');
        expect(is_file($this->orbitHome.'/config.json'))->toBeFalse();
    })->with([
        'invalid profile name' => [
            ['name' => "test\nprofile-secret", 'url' => 'https://10.70.0.1'],
            'Gateway profile name is invalid.',
        ],
        'credential-shaped profile name' => [
            ['name' => 'API_TOKEN=profile-secret', 'url' => 'https://10.70.0.1'],
            'Gateway profile name is invalid.',
        ],
        'path-like profile name' => [
            ['name' => '../profile-secret', 'url' => 'https://10.70.0.1'],
            'Gateway profile name is invalid.',
        ],
        'profile name with a space' => [
            ['name' => 'test profile-secret', 'url' => 'https://10.70.0.1'],
            'Gateway profile name is invalid.',
        ],
        'uppercase profile name' => [
            ['name' => 'Production', 'url' => 'https://10.70.0.1'],
            'Gateway profile name is invalid.',
        ],
        'profile name above maximum length' => [
            [
                'name' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'url' => 'https://10.70.0.1',
            ],
            'Gateway profile name is invalid.',
        ],
        'credential-bearing URL' => [
            ['name' => 'test', 'url' => 'https://user:profile-secret@10.70.0.1'],
            'Gateway URL must be a safe HTTPS origin.',
        ],
        'URL base path' => [
            ['name' => 'test', 'url' => 'https://10.70.0.1/profile-secret'],
            'Gateway URL must be a safe HTTPS origin.',
        ],
        'URL query' => [
            ['name' => 'test', 'url' => 'https://10.70.0.1?token=profile-secret'],
            'Gateway URL must be a safe HTTPS origin.',
        ],
        'URL fragment' => [
            ['name' => 'test', 'url' => 'https://10.70.0.1#profile-secret'],
            'Gateway URL must be a safe HTTPS origin.',
        ],
        'port zero' => [
            ['name' => 'test', 'url' => 'https://10.70.0.1:0'],
            'Gateway URL must be a safe HTTPS origin.',
        ],
        'port above maximum' => [
            ['name' => 'test', 'url' => 'https://10.70.0.1:65536'],
            'Gateway URL must be a safe HTTPS origin.',
        ],
        'repeated slash path' => [
            ['name' => 'test', 'url' => 'https://10.70.0.1//profile-secret'],
            'Gateway URL must be a safe HTTPS origin.',
        ],
        'relative CA path' => [
            ['name' => 'test', 'url' => 'https://10.70.0.1', '--ca' => 'profile-secret/root.pem'],
            'Gateway CA path must be an absolute path.',
        ],
        'control character in CA path' => [
            ['name' => 'test', 'url' => 'https://10.70.0.1', '--ca' => "/tmp/root.pem\nprofile-secret"],
            'Gateway CA path must be an absolute path.',
        ],
    ]);
});
