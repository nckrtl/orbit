<?php

declare(strict_types=1);

use App\Infrastructure\Hibernation\RuntimeHibernatorUnitRenderer;

it('renders a oneshot hibernator service and ten-minute timer', function (): void {
    $units = new RuntimeHibernatorUnitRenderer;

    expect($units->serviceName())
        ->toBe('orbit-runtime-hibernator.service')
        ->and($units->timerName())
        ->toBe('orbit-runtime-hibernator.timer')
        ->and($units->renderService('/usr/bin/php8.5', '/home/orbit/orbit-gateway/artisan', '/home/orbit/.orbit', '/home/orbit/orbit-gateway'))
        ->toContain('Type=oneshot')
        ->toContain('orbit:runtime-hibernator')
        ->not->toContain('WantedBy=multi-user.target')
        ->and($units->renderTimer())
        ->toContain('OnUnitActiveSec=600s')
        ->toContain('WantedBy=timers.target')
        ->toContain('Unit=orbit-runtime-hibernator.service');
});
