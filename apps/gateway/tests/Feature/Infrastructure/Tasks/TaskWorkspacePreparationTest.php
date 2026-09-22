<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('runs preparation with isolated state and rejects failures through real subprocesses', function (): void {
    $process = new Process(['python3', base_path('tests/Fixtures/task_prepare_test.py'), resource_path('tasks/prepare.py')], timeout: 30);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
});
