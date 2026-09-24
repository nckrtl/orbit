<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('runs topology commands from a task workspace clone through a bridge worktree', function (): void {
    $repository = dirname(__DIR__, 5);
    $process = new Process(
        ['python3', dirname(__DIR__, 2).'/Fixtures/e2e-clone-bridge-tests.py', $repository],
        $repository,
        ['PYTHONDONTWRITEBYTECODE' => '1', 'ORBIT_E2E_BRIDGE' => false, 'XDG_STATE_HOME' => false],
    );
    $process->setTimeout(120);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
});
