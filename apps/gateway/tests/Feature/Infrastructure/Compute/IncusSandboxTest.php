<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

describe('Incus sandbox boundary', function (): void {
    it('preserves foreign resources and refuses unsafe identities and exhausted capacity', function (): void {
        $process = new Process(['python3', base_path('tests/Fixtures/Compute/incus_sandbox_test.py'), base_path('../agent/resources/incus-sandbox.py')]);
        $process->mustRun();

        expect($process->getExitCode())->toBe(0);
    });

    it('refuses unowned host network intent, rule drift, and unsafe control input', function (): void {
        $process = new Process(['python3', base_path('tests/Fixtures/Compute/incus_host_network_test.py'), base_path('../agent/resources/incus-host-network.py')]);
        $process->mustRun();

        expect($process->getExitCode())->toBe(0);
    });

    it('filters real packets and restores only recorded host policies', function (): void {
        $process = new Process(['sudo', '-n', 'unshare', '--net', 'python3', '-B', base_path('tests/Fixtures/Compute/incus_host_network_test.py'), base_path('../agent/resources/incus-host-network.py'), '--packets']);
        $process->mustRun();

        expect($process->getExitCode())->toBe(0);
    })->group('privileged');
});
