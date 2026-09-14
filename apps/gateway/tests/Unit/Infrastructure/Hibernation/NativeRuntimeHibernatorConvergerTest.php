<?php

declare(strict_types=1);

use App\Infrastructure\Hibernation\NativeRuntimeHibernatorConverger;
use App\Infrastructure\Hibernation\RuntimeHibernatorUnitRenderer;
use Tests\Support\AppDevFakeProcessRunner;

it('installs the oneshot service and enables the timer', function (): void {
    $processes = new AppDevFakeProcessRunner;
    $units = new RuntimeHibernatorUnitRenderer;
    $converger = new NativeRuntimeHibernatorConverger(
        processes: $processes,
        units: $units,
        phpBinary: '/usr/bin/php8.5',
        artisan: '/home/orbit/orbit-gateway/artisan',
        orbitHome: '/home/orbit/.orbit',
        workingDirectory: '/home/orbit/orbit-gateway',
        sweepSeconds: 600,
    );

    $converger->converge();

    expect(array_map(static fn ($invocation): array => $invocation->arguments, $processes->invocations))
        ->toBe([
            ['sudo', 'install', '-m', '0644', '/dev/stdin', $units->servicePath()],
            ['sudo', 'install', '-m', '0644', '/dev/stdin', $units->timerPath()],
            ['sudo', 'systemctl', 'daemon-reload'],
            ['sudo', 'systemctl', 'enable', '--now', $units->timerName()],
        ])
        ->and($processes->invocations[0]->input)
        ->toContain('orbit:runtime-hibernator')
        ->toContain('User=orbit')
        ->not->toContain('User=root')
        ->and($processes->invocations[1]->input)
        ->toContain('OnUnitActiveSec=600s');
});

it('republishes the oneshot as the configured Gateway account', function (): void {
    $processes = new AppDevFakeProcessRunner;
    $units = new RuntimeHibernatorUnitRenderer;
    $converger = new NativeRuntimeHibernatorConverger(
        processes: $processes,
        units: $units,
        phpBinary: '/usr/bin/php8.5',
        artisan: '/home/gateway/orbit-gateway/artisan',
        orbitHome: '/home/gateway/.orbit',
        workingDirectory: '/home/gateway/orbit-gateway',
        user: 'gateway',
        sweepSeconds: 600,
    );

    $converger->converge();

    expect($processes->invocations[0]->input)
        ->toContain('User=gateway')
        ->not->toContain('User=orbit')
        ->not->toContain('User=root');
});
