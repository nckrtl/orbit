<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');

pest()
    ->tia()
    ->locally()
    ->filtered();

/**
 * Adapt a Caddyfile with the installed caddy binary. Reads the configuration
 * from a temporary file because Caddy 2.6 (Ubuntu 26.04) cannot read stdin.
 */
function caddy_adapt(string $configuration): App\Infrastructure\Processes\CommandResult
{
    $path = tempnam(sys_get_temp_dir(), 'orbit-caddy-adapt-');
    if ($path === false) {
        throw new RuntimeException('Could not create a temporary Caddyfile.');
    }
    try {
        file_put_contents($path, $configuration);

        return new App\Infrastructure\Processes\NativeProcessRunner()->run(new App\Infrastructure\Processes\ProcessInvocation(
            arguments: ['caddy', 'adapt', '--config', $path, '--adapter', 'caddyfile'],
        ));
    } finally {
        unlink($path);
    }
}
