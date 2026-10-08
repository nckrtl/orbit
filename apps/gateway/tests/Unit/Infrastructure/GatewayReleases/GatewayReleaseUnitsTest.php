<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\GatewayReleases\GatewayReleaseUnitRenderer;
use App\Infrastructure\GatewayReleases\NativeGatewayReleaseUnitConverger;
use App\Infrastructure\GatewayReleases\SystemdGatewayReleaseUnitStarter;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;

final class GatewayReleaseUnitProcessRunner implements ProcessRunner
{
    /** @var list<list<string>> */
    public array $ran = [];

    /** @var array<string, string> unit file contents by destination */
    public array $installed = [];

    /** @var list<string> the states `systemctl is-active` reports, in order; the last one repeats */
    public array $states = ['active'];

    public function __construct(private readonly ?string $fail = null) {}

    public function run(ProcessInvocation $invocation): CommandResult
    {
        if (($invocation->arguments[1] ?? null) === 'is-active') {
            $state = count($this->states) > 1 ? array_shift($this->states) : $this->states[0];

            return new CommandResult($state === 'active' ? 0 : 3, $state."\n", '', 1, false);
        }

        $this->ran[] = $invocation->arguments;

        if (($invocation->arguments[1] ?? null) === 'install') {
            $this->installed[$invocation->arguments[5]] = (string) file_get_contents($invocation->arguments[4]);
        }

        return in_array($this->fail, $invocation->arguments, true)
            ? new CommandResult(1, '', 'refused', 1, false)
            : new CommandResult(0, '', '', 1, false);
    }
}

describe(GatewayReleaseUnitRenderer::class, function (): void {
    it('renders the timer, the oneshot service, and the run template as the Gateway account', function (): void {
        $units = new GatewayReleaseUnitRenderer;
        $artisan = '/home/orbit/orbit/apps/gateway/artisan';

        expect($units->renderTimer())->toBe(implode("\n", [
            '[Unit]',
            'Description=Orbit Gateway automatic release timer',
            '',
            '[Timer]',
            'OnCalendar=*-*-* *:*:00',
            'AccuracySec=1s',
            'RandomizedDelaySec=10s',
            'Persistent=false',
            'Unit=orbit-gateway-release.service',
            '',
            '[Install]',
            'WantedBy=timers.target',
            '',
        ]))->and($units->renderService('/usr/bin/php8.5', $artisan, '/home/orbit/.orbit', '/home/orbit/orbit/apps/gateway', 'orbit'))->toBe(implode("\n", [
            '[Unit]',
            'Description=Orbit Gateway automatic release',
            'After=network-online.target',
            'Wants=network-online.target',
            '',
            '[Service]',
            'Type=oneshot',
            'User=orbit',
            'WorkingDirectory=/home/orbit/orbit/apps/gateway',
            'Environment=ORBIT_HOME=/home/orbit/.orbit',
            'Environment=HOME=/home/orbit',
            'Environment=PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'Environment=LANG=C.UTF-8',
            'ExecStart="/usr/bin/php8.5" "/home/orbit/orbit/apps/gateway/artisan" "gateway:release:auto"',
            'ExecStopPost="/usr/bin/php8.5" "/home/orbit/orbit/apps/gateway/artisan" "gateway:release:settle"',
            'TimeoutStartSec=3600',
            '',
        ]))->and($units->renderRunTemplate('/usr/bin/php8.5', $artisan, '/home/orbit/.orbit', '/home/orbit/orbit/apps/gateway', 'orbit'))
            ->toContain('Description=Orbit Gateway release %i')
            ->toContain('ExecStart="/usr/bin/php8.5" "/home/orbit/orbit/apps/gateway/artisan" "gateway:release:run" %i')
            ->toContain('ExecStopPost="/usr/bin/php8.5" "/home/orbit/orbit/apps/gateway/artisan" "gateway:release:settle" %i')
            ->toContain('Type=oneshot')
            ->toContain('User=orbit')
            ->toContain('TimeoutStartSec=3600')
            ->and($units->runUnitName(42))->toBe('orbit-gateway-release-run@42.service');
    });

    it('escapes specifiers in paths so only the record id is expanded', function (): void {
        $service = new GatewayReleaseUnitRenderer()->renderRunTemplate('/usr/bin/php', '/srv/50%/artisan', '/srv/home $x', '/srv/50%', 'orbit');

        expect($service)->toContain('WorkingDirectory=/srv/50%%')
            ->toContain('Environment=ORBIT_HOME=/srv/home\x20\x24x')
            ->toContain('"/srv/50%%/artisan" "gateway:release:run" %i');
    });
});

describe(NativeGatewayReleaseUnitConverger::class, function (): void {
    it('installs the three units, reloads systemd, and enables the timer without starting a release', function (): void {
        $processes = new GatewayReleaseUnitProcessRunner;
        new NativeGatewayReleaseUnitConverger(
            processes: $processes,
            phpBinary: '/usr/bin/php8.5',
            orbitHome: '/home/orbit/.orbit',
            workingDirectory: '/home/orbit/orbit/apps/gateway',
        )->converge();

        expect(array_keys($processes->installed))->toBe([
            '/etc/systemd/system/orbit-gateway-release.service',
            '/etc/systemd/system/orbit-gateway-release-run@.service',
            '/etc/systemd/system/orbit-gateway-release.timer',
        ])
            ->and(array_slice($processes->ran, 3))->toBe([
                ['sudo', 'systemctl', 'daemon-reload'],
                ['sudo', 'systemctl', 'enable', '--now', 'orbit-gateway-release.timer'],
            ])
            ->and($processes->installed['/etc/systemd/system/orbit-gateway-release.service'])->toContain('"/home/orbit/orbit/apps/gateway/artisan" "gateway:release:auto"');

        foreach ($processes->ran as $arguments) {
            expect($arguments)->not->toContain('orbit-gateway-release.service')
                ->and($arguments)->not->toContain('restart');
        }
    });

    it('stops before reloading when a unit cannot be installed', function (): void {
        $processes = new GatewayReleaseUnitProcessRunner(fail: '/etc/systemd/system/orbit-gateway-release-run@.service');

        $failure = null;
        try {
            new NativeGatewayReleaseUnitConverger($processes, orbitHome: '/home/orbit/.orbit', workingDirectory: '/home/orbit/orbit/apps/gateway')->converge();
        } catch (NodeProvisioningException $exception) {
            $failure = $exception;
        }

        expect($failure?->errorCode)->toBe('gateway.release_units_install_failed')
            ->and($failure?->step)->toBe('gateway-release-run-service')
            ->and($processes->ran)->toHaveCount(2);
    });
});

describe(SystemdGatewayReleaseUnitStarter::class, function (): void {
    it('starts the run unit of one record and returns once it runs', function (): void {
        $processes = new GatewayReleaseUnitProcessRunner;
        $processes->states = ['inactive', 'activating', 'active'];
        new SystemdGatewayReleaseUnitStarter($processes, sleep: static function (): void {})->start(7, static fn (): bool => false);

        expect($processes->ran)->toBe([['sudo', '-n', 'systemctl', 'start', '--no-block', 'orbit-gateway-release-run@7.service']]);
    });

    it('accepts a unit that already ended after it claimed the record', function (): void {
        $processes = new GatewayReleaseUnitProcessRunner;
        $processes->states = ['inactive'];

        new SystemdGatewayReleaseUnitStarter($processes, sleep: static function (): void {})->start(7, static fn (): bool => true);

        expect($processes->ran)->toHaveCount(1);
    });

    it('does not take a queued start as proof that the unit runs', function (string $state): void {
        $processes = new GatewayReleaseUnitProcessRunner;
        $processes->states = [$state];

        expect(release_failure(fn () => new SystemdGatewayReleaseUnitStarter($processes, attempts: 3, sleep: static function (): void {})->start(7, static fn (): bool => false)))
            ->errorCode->toBe('gateway.release_unit_failed')
            ->status->toBe(503);
    })->with(['failed', 'inactive']);

    it('reports a unit systemd refused to start', function (): void {
        $processes = new GatewayReleaseUnitProcessRunner(fail: 'orbit-gateway-release-run@7.service');

        expect(release_failure(fn () => new SystemdGatewayReleaseUnitStarter($processes)->start(7, static fn (): bool => false)))
            ->toBeInstanceOf(GatewayReleaseException::class)
            ->errorCode->toBe('gateway.release_unit_failed')
            ->status->toBe(503);
    });
});
