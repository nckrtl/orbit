<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('prepares only a marked disposable source copy and produces an adoptable template', function (): void {
    $process = new Process(['python3', base_path('tests/Fixtures/Compute/guest_template_source_test.py'), resource_path('compute/guest-template-source.py')]);
    $process->mustRun();

    expect($process->getExitCode())->toBe(0);
});

it('publishes only an owned candidate pair and cleans partial outputs without deleting foreign resources', function (): void {
    $process = new Process(['python3', base_path('tests/Fixtures/Compute/publish_template_test.py'), resource_path('compute/publish-template.py')]);
    $process->mustRun();

    expect($process->getExitCode())->toBe(0);
});

it('verifies pinned cold-build inputs and refuses unsafe archives without extracting files', function (): void {
    $process = new Process(['python3', base_path('tests/Fixtures/Compute/template_inputs_test.py'), resource_path('compute/template-inputs.py')]);
    $process->mustRun();

    expect($process->getExitCode())->toBe(0);
});

it('constructs only new cold candidates and requires matching preparation receipts before native convergence', function (): void {
    $process = new Process(['python3', base_path('tests/Fixtures/Compute/prepare_template_test.py'), resource_path('compute/prepare-template.py')]);
    $process->mustRun();

    expect($process->getExitCode())->toBe(0);
});

it('requires the isolated native pair and matching Gateway version before template publication', function (): void {
    $process = new Process(['python3', base_path('tests/Fixtures/Compute/guest_template_health_test.py'), resource_path('compute/guest-template-health.py')]);
    $process->mustRun();

    expect($process->getExitCode())->toBe(0);
});

it('publishes a Project development image only from an owned blank workload base and leaves foreign resources intact', function (): void {
    $process = new Process(['python3', base_path('tests/Fixtures/Compute/project_image_test.py'), resource_path('compute/project-image.py')]);
    $process->mustRun();

    expect($process->getExitCode())->toBe(0);
});
