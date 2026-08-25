<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Exceptions\GatewayConfigException;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->configDirectory = sys_get_temp_dir().'/orbit-cli-'.Str::uuid();
    $this->configPath = $this->configDirectory.'/config.json';
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory($this->configDirectory);
});

describe(GatewayConfigRepository::class, function (): void {
    it('persists gateway profiles and activates the first profile', function (): void {
        expect(class_exists(GatewayConfigRepository::class))->toBeTrue();

        $repository = new GatewayConfigRepository($this->configPath);
        $repository->add(new GatewayProfile(
            name: 'test',
            url: 'https://10.70.0.1',
            caPath: '/home/orbit/.orbit/ca/root.pem',
        ));

        $reloaded = new GatewayConfigRepository($this->configPath);

        expect($reloaded->active())
            ->toEqual(new GatewayProfile(
                name: 'test',
                url: 'https://10.70.0.1',
                caPath: '/home/orbit/.orbit/ca/root.pem',
            ))
            ->and(fileperms($this->configPath) & 0o777)
            ->toBe(0o600);
    });

    it('switches the active profile without changing other profiles', function (): void {
        expect(class_exists(GatewayConfigRepository::class))->toBeTrue();

        $repository = new GatewayConfigRepository($this->configPath);
        $repository->add(new GatewayProfile('test', 'https://10.70.0.1'));
        $repository->add(new GatewayProfile('production', 'https://10.80.0.1'));
        $repository->use('production');

        expect($repository->active()?->name)
            ->toBe('production')
            ->and($repository->find('test')?->url)
            ->toBe('https://10.70.0.1');
    });

    it('rejects an unknown active profile', function (): void {
        expect(class_exists(GatewayConfigException::class))->toBeTrue();

        $repository = new GatewayConfigRepository($this->configPath);

        expect(fn () => $repository->use('missing'))
            ->toThrow(GatewayConfigException::class, 'Gateway profile [missing] does not exist.');
    });
});
