<?php

declare(strict_types=1);

use App\Domain\Fleet\CliReleaseName;
use App\Infrastructure\Fleet\FleetConvergeUnitRenderer;
use App\Infrastructure\Fleet\NativeFleetConvergeUnits;
use App\Infrastructure\Fleet\StaticCliRelease;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use Tests\Support\RecordingProcessRunner;

describe('fleet converge units', function (): void {
    it('renders a oneshot service that runs the rollout as the Gateway account and a 5-minute timer', function (): void {
        $units = new FleetConvergeUnitRenderer;
        $service = $units->renderService('/usr/bin/php8.5', '/home/orbit/orbit/apps/gateway/artisan', '/home/orbit/.orbit', '/home/orbit/orbit/apps/gateway', 'orbit');

        expect($service)->toContain("Type=oneshot\n")
            ->and($service)->toContain("User=orbit\n")
            ->and($service)->toContain("WorkingDirectory=/home/orbit/orbit/apps/gateway\n")
            ->and($service)->toContain('ExecStart="/usr/bin/php8.5" "/home/orbit/orbit/apps/gateway/artisan" "orbit:fleet-converge"')
            ->and($units->renderTimer())->toContain("OnUnitInactiveSec=300s\n")
            ->and($units->renderTimer())->toContain("Unit=orbit-fleet-converge.service\n");
    });

    it('installs both units and enables the timer', function (): void {
        $processes = new RecordingProcessRunner;

        new NativeFleetConvergeUnits($processes, artisan: '/a/artisan', orbitHome: '/h', workingDirectory: '/a')->converge();

        expect(array_map(static fn (array $arguments): string => implode(' ', array_slice($arguments, 0, 4)), $processes->ran))->toBe([
            'sudo install -m 0644',
            'sudo install -m 0644',
            'sudo systemctl daemon-reload',
            'sudo systemctl enable --now',
        ])->and(end($processes->ran))->toBe(['sudo', 'systemctl', 'enable', '--now', 'orbit-fleet-converge.timer']);
    });

    it('starts the service without waiting for it', function (): void {
        $processes = new RecordingProcessRunner;

        expect(new NativeFleetConvergeUnits($processes)->start())->toBeTrue()
            ->and($processes->ran)->toBe([['sudo', 'systemctl', 'start', '--no-block', 'orbit-fleet-converge.service']]);
    });

    it('reports a refused start without throwing', function (): void {
        $processes = new class implements ProcessRunner
        {
            public function run(ProcessInvocation $invocation): CommandResult
            {
                return new CommandResult(1, '', 'denied', 1, false);
            }
        };

        expect(new NativeFleetConvergeUnits($processes)->start())->toBeFalse();
    });
});

it('names a CLI release from a local manifest only for a disposable topology', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'cli-release');
    file_put_contents($path, json_encode([
        'number' => 9001,
        'checksums_url' => 'https://gateway.orbit/fleet-proof/SHA256SUMS',
        'assets' => [['platform' => 'linux-x86_64', 'url' => 'https://gateway.orbit/fleet-proof/orbit', 'sha256' => str_repeat('a', 64)]],
    ], JSON_THROW_ON_ERROR));
    $release = new StaticCliRelease($path);
    $commit = str_repeat('b', 40);

    $found = $release->find($commit, new CliReleaseName((int) $release->count($commit)));
    unlink($path);

    expect($release->commit($commit))->toBe($commit)
        ->and($found->version)->toBe('0.9001.0')
        ->and($found->assets[0]->name)->toBe('orbit-0.9001.0-linux-x86_64')
        ->and(new StaticCliRelease('/missing')->count($commit))->toBeNull();
});

it('asks systemd for a fresh run shortly after the running one exits', function (): void {
    $processes = new RecordingProcessRunner;

    expect(new NativeFleetConvergeUnits($processes)->startLater())->toBeTrue()
        ->and($processes->ran[0])->toContain('systemd-run', '--on-active=15', 'systemctl', 'orbit-fleet-converge.service')
        ->and($processes->ran[0][0])->toBe('sudo');
});
