<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

describe('Incus sandbox boundary', function (): void {
    it('preserves foreign resources and refuses unsafe identities and exhausted capacity', function (): void {
        $process = new Process(['python3', base_path('tests/Fixtures/Compute/incus_sandbox_test.py'), resource_path('compute/incus-sandbox.py')]);
        $process->mustRun();

        expect($process->getExitCode())->toBe(0);
    });

    it('accepts only the approved bundle commit and removes its trusted import', function (): void {
        $process = new Process(['python3', base_path('tests/Fixtures/Compute/trusted_bundle_test.py'), resource_path('compute/trusted-git-bundle.py')]);
        $process->mustRun();

        expect($process->getExitCode())->toBe(0);
    });
});
