<?php

declare(strict_types=1);

use App\E2E\Value\GuestProcess;

it('starts a transient unit as orbit with the exec environment and returns at once', function (): void {
    expect(new GuestProcess('viewer')->spawnArgv(['bun', 'viewer.ts', '--node=2']))->toBe([
        'sudo',
        'systemd-run',
        '--quiet',
        '--collect',
        '--unit=orbit-e2e-viewer.service',
        '--uid=orbit',
        '--gid=orbit',
        '--working-directory=/home/orbit',
        '--setenv=HOME=/home/orbit',
        '--setenv=ORBIT_HOME=/home/orbit/.orbit',
        '--setenv=DB_DATABASE=/home/orbit/.orbit/gateway.sqlite',
        '--property=TimeoutStopSec=10',
        '--',
        'bun',
        'viewer.ts',
        '--node=2',
    ]);
});

it('reads the unit journal with precise timestamps and optional bounds', function (): void {
    $process = new GuestProcess('viewer');

    expect($process->logsArgv())->toBe([
        'sudo', 'journalctl', '--no-pager', '--output=short-iso-precise', '--unit=orbit-e2e-viewer.service',
    ])->and($process->logsArgv('-5min', 20))->toBe([
        'sudo', 'journalctl', '--no-pager', '--output=short-iso-precise', '--unit=orbit-e2e-viewer.service',
        '--since=-5min', '--lines=20',
    ])->and($process->killArgv())->toBe(['sudo', 'systemctl', 'stop', 'orbit-e2e-viewer.service']);
});

it('refuses names, commands, and bounds that could reach another unit or option', function (string $name): void {
    expect(fn (): GuestProcess => new GuestProcess($name))->toThrow(InvalidArgumentException::class);
})->with(['', '-viewer', 'Viewer', 'viewer.service', 'a/b', 'viewer name', str_repeat('a', 41)]);

it('refuses an option as the program and unsafe log bounds', function (): void {
    $process = new GuestProcess('viewer');

    expect(fn (): array => $process->spawnArgv(['--help']))->toThrow(InvalidArgumentException::class)
        ->and(fn (): array => $process->spawnArgv([]))->toThrow(InvalidArgumentException::class)
        ->and(fn (): array => $process->logsArgv('$(reboot)'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): array => $process->logsArgv(null, 0))->toThrow(InvalidArgumentException::class);
});
