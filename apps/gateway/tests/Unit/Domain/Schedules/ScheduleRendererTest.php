<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Schedules\ScheduleRenderer;
use App\Domain\Schedules\ScheduleTarget;
use App\Models\Node;
use App\Models\Schedule;

it('keeps the caller command only in the protected UUID script', function (): void {
    $schedule = new Schedule([
        'id' => '123e4567-e89b-42d3-a456-426614174000',
        'name' => 'backup',
        'calendar' => 'daily',
        'command' => 'php artisan backup:run --token="secret"',
        'timeout_seconds' => 90,
    ]);
    $target = new ScheduleTarget(
        node: new Node,
        user: 'orbit-app',
        group: 'orbit-app',
        home: '/home/orbit-app',
        workingDirectory: '/home/orbit-app/current',
        shell: '/bin/bash',
        loginShell: false,
        appInstance: null,
    );
    $renderer = schedule_renderer('https://10.44.0.1');
    $script = $renderer->renderScript($schedule, $target);
    $service = $renderer->renderService($schedule, $target);
    $timer = $renderer->renderTimer($schedule);

    expect($script)
        ->toContain($schedule->command)
        ->toContain("'/bin/bash' -c")
        ->toContain('--cacert <(')
        ->not->toContain('TEST ROOT CERTIFICATE')
        ->toContain('/api/v1/schedules/'.$schedule->id.'/complete')
        ->and($service)
        ->not->toContain($schedule->command)
        ->toContain('ExecStart=/etc/orbit/schedules/'.$schedule->id.'.sh run')
        ->toContain('ExecStopPost=/etc/orbit/schedules/'.$schedule->id.'.sh complete')
        ->toContain('WorkingDirectory=/home/orbit-app/current')
        ->toContain('TimeoutStartSec=90')
        ->and($timer)
        ->not->toContain($schedule->command)
        ->toContain('Persistent=true')
        ->toContain('OnCalendar=daily')
        ->and($renderer->serviceName($schedule))
        ->toBe('orbit-schedule-'.$schedule->id.'.service');
});

it('uses a non-interactive login shell for Node and development contexts', function (): void {
    $schedule = new Schedule([
        'id' => '123e4567-e89b-42d3-a456-426614174001',
        'name' => 'task',
        'calendar' => 'hourly',
        'command' => 'true',
        'timeout_seconds' => 3600,
    ]);
    $target = new ScheduleTarget(
        node: new Node,
        user: 'orbit',
        group: 'orbit',
        home: '/home/orbit',
        workingDirectory: '/home/orbit',
        shell: '/bin/zsh',
        loginShell: true,
        appInstance: null,
    );

    expect(schedule_renderer('https://gateway.test')->renderScript($schedule, $target))
        ->toContain("'/bin/zsh' -lc 'true'");
});

function schedule_renderer(string $callbackBase): ScheduleRenderer
{
    $certificates = new class implements LeafCertificateSigner
    {
        public function sign(string $hostname, string $certificateRequest): string
        {
            throw new LogicException('Signing is not part of Schedule rendering.');
        }

        public function rootCertificate(): string
        {
            return 'TEST ROOT CERTIFICATE';
        }
    };

    return new ScheduleRenderer($certificates, $callbackBase);
}
