<?php

declare(strict_types=1);

use App\Commands\Gateway\GatewayUseCommand;
use App\Data\GatewayProfile;
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
});
