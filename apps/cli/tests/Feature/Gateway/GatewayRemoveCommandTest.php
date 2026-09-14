<?php

declare(strict_types=1);

use App\Commands\Gateway\GatewayRemoveCommand;
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

describe(GatewayRemoveCommand::class, function (): void {
    it('removes an inactive profile and its pinned certificate file', function (): void {
        expect(class_exists(GatewayRemoveCommand::class))->toBeTrue();

        $repository = app(GatewayConfigRepository::class);
        $repository->add(new GatewayProfile('test', 'https://10.70.0.1'));
        $caPath = gateway_remove_test_certificate($this->orbitHome, 'production');
        $repository->add(new GatewayProfile('production', 'https://10.80.0.1', $caPath));

        $this
            ->artisan('gateway:remove', ['name' => 'production'])
            ->expectsOutputToContain('Gateway [production] removed.')
            ->assertExitCode(0);

        expect($repository->find('production'))
            ->toBeNull()
            ->and($repository->find('test')?->url)
            ->toBe('https://10.70.0.1')
            ->and($repository->active()?->name)
            ->toBe('test')
            ->and(is_file($caPath))
            ->toBeFalse();
    });

    it('reports an unknown name as not found without changing configuration', function (): void {
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

        $exitCode = Artisan::call('gateway:remove', ['name' => 'profile-secret', '--json' => true]);
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

    it('refuses the active profile and changes nothing', function (): void {
        $repository = app(GatewayConfigRepository::class);
        $repository->add(new GatewayProfile('test', 'https://10.70.0.1'));
        $caPath = gateway_remove_test_certificate($this->orbitHome, 'test');
        $repository->add(new GatewayProfile('test', 'https://10.70.0.1', $caPath));
        $repository->add(new GatewayProfile('production', 'https://10.80.0.1'));
        $before = file_get_contents($this->orbitHome.'/config.json');
        $expected = json_encode([
            'error' => [
                'code' => 'gateway.profile_active',
                'message' => 'Cannot remove the active gateway profile.',
                'request_id' => null,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $exitCode = Artisan::call('gateway:remove', ['name' => 'test', '--json' => true]);
        $output = trim(Artisan::output());

        expect($exitCode)->toBe(1);
        expect($output)->toBe($expected);
        expect(file_get_contents($this->orbitHome.'/config.json'))
            ->toBe($before)
            ->and($repository->active()?->name)
            ->toBe('test')
            ->and($repository->find('test'))
            ->not->toBeNull()
            ->and(is_file($caPath))
            ->toBeTrue();
    });

    it('removes the active profile with force and clears the active selection', function (): void {
        $repository = app(GatewayConfigRepository::class);
        $repository->add(new GatewayProfile('test', 'https://10.70.0.1'));
        $caPath = gateway_remove_test_certificate($this->orbitHome, 'test');
        $repository->add(new GatewayProfile('test', 'https://10.70.0.1', $caPath));
        $repository->add(new GatewayProfile('production', 'https://10.80.0.1'));

        $this
            ->artisan('gateway:remove', [
                'name' => 'test',
                '--force' => true,
                '--json' => true,
            ])
            ->expectsOutputToContain('"profile":"test"')
            ->assertExitCode(0);

        expect($repository->find('test'))
            ->toBeNull()
            ->and($repository->find('production')?->url)
            ->toBe('https://10.80.0.1')
            ->and($repository->active())
            ->toBeNull()
            ->and(is_file($caPath))
            ->toBeFalse();
    });

    it('returns the removed profile name as json', function (): void {
        $repository = app(GatewayConfigRepository::class);
        $repository->add(new GatewayProfile('test', 'https://10.70.0.1'));
        $repository->add(new GatewayProfile('production', 'https://10.80.0.1'));
        $expected = json_encode(['profile' => 'production'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $exitCode = Artisan::call('gateway:remove', ['name' => 'production', '--json' => true]);
        $output = trim(Artisan::output());

        expect($exitCode)->toBe(0);
        expect($output)->toBe($expected);
        expect($repository->find('production'))->toBeNull();
    });

    it('rejects invalid names without exposing input or changing configuration', function (string $name): void {
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

        $exitCode = Artisan::call('gateway:remove', ['name' => $name, '--json' => true]);
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

    it('succeeds when the recorded pinned certificate file is already missing', function (): void {
        $repository = app(GatewayConfigRepository::class);
        $repository->add(new GatewayProfile('test', 'https://10.70.0.1'));
        $repository->add(new GatewayProfile(
            'production',
            'https://10.80.0.1',
            $this->orbitHome.'/gateways/production/ca/missing.pem',
        ));

        $this
            ->artisan('gateway:remove', ['name' => 'production'])
            ->assertExitCode(0);

        expect($repository->find('production'))->toBeNull();
    });

    it('does not delete a symlinked pinned certificate path', function (): void {
        $external = sys_get_temp_dir().'/orbit-cli-ca-'.Str::uuid();
        mkdir(directory: $external, permissions: 0o700, recursive: true);
        $target = $external.'/root.pem';
        file_put_contents($target, "certificate\n");

        try {
            $repository = app(GatewayConfigRepository::class);
            $repository->add(new GatewayProfile('test', 'https://10.70.0.1'));
            $link = gateway_remove_test_certificate($this->orbitHome, 'production');
            unlink($link);
            symlink($target, $link);
            $repository->add(new GatewayProfile('production', 'https://10.80.0.1', $link));

            $this
                ->artisan('gateway:remove', ['name' => 'production'])
                ->assertExitCode(0);

            expect($repository->find('production'))
                ->toBeNull()
                ->and(is_file($target))
                ->toBeTrue();
        } finally {
            new Filesystem()->deleteDirectory($external);
        }
    });
});

function gateway_remove_test_certificate(string $orbitHome, string $profile): string
{
    $caPath = $orbitHome.'/gateways/'.$profile.'/ca/root.pem';
    mkdir(directory: dirname($caPath), permissions: 0o700, recursive: true);
    file_put_contents($caPath, "certificate\n");
    chmod(filename: $caPath, permissions: 0o600);

    return $caPath;
}
