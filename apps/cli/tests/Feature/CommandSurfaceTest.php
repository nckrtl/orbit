<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

it('exposes only Orbit commands beyond the console essentials', function (): void {
    $commands = array_keys(app(Kernel::class)->all());

    expect($commands)
        ->toContain(
            'gateway:add',
            'gateway:status',
            'gateway:use',
            'node:list',
            'node:provision',
            'node:show',
        )
        ->not->toContain('app:build', 'app:install', 'app:rename', 'make:command', 'make:test', 'test');
});
