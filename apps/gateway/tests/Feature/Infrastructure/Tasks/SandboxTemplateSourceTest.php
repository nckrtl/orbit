<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('prepares only a marked disposable source copy and produces an adoptable template', function (): void {
    $process = new Process(['python3', base_path('tests/Fixtures/Compute/guest_template_source_test.py'), resource_path('compute/guest-template-source.py')]);
    $process->mustRun();

    expect($process->getExitCode())->toBe(0);
});
