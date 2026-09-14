<?php

declare(strict_types=1);

use App\Infrastructure\Hibernation\RuntimeHibernatorUnitRenderer;

it('renders a oneshot hibernator service and ten-minute timer', function (): void {
    $units = new RuntimeHibernatorUnitRenderer;

    expect($units->serviceName())
        ->toBe('orbit-runtime-hibernator.service')
        ->and($units->timerName())
        ->toBe('orbit-runtime-hibernator.timer')
        ->and($units->renderService('/usr/bin/php8.5', '/home/orbit/orbit-gateway/artisan', '/home/orbit/.orbit', '/home/orbit/orbit-gateway', 'orbit'))
        ->toContain('Type=oneshot')
        ->toContain('User=orbit')
        ->not->toContain('User=root')
        ->toContain('orbit:runtime-hibernator')
        ->not->toContain('WantedBy=multi-user.target')
        ->and($units->renderTimer())
        ->toContain('OnUnitActiveSec=600s')
        ->toContain('WantedBy=timers.target')
        ->toContain('Unit=orbit-runtime-hibernator.service');
});

it('renders the supplied Gateway account as the oneshot user', function (): void {
    $unit = new RuntimeHibernatorUnitRenderer()->renderService(
        '/usr/bin/php8.5',
        '/home/gateway/orbit-gateway/artisan',
        '/home/gateway/.orbit',
        '/home/gateway/orbit-gateway',
        'gateway',
    );

    expect($unit)
        ->toContain('User=gateway')
        ->not->toContain('User=orbit')
        ->not->toContain('User=root');
});
