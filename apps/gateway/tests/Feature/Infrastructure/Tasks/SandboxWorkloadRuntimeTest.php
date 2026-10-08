<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('enrolls private workloads with fresh keys pinned native roles and partial retry preservation', function (): void {
    $process = new Process(['python3', base_path('tests/Fixtures/Compute/guest_workload_runtime_test.py'), resource_path('compute/guest-workload-runtime.py')]);
    $process->mustRun();

    expect($process->getExitCode())->toBe(0);
});
