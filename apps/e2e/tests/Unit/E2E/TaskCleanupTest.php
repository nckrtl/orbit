<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('removes only the invoking task bridge from disposable git repositories', function (): void {
    $repository = dirname(__DIR__, 5);
    $process = new Process(
        ['python3', dirname(__DIR__, 2).'/Fixtures/e2e-task-cleanup-tests.py', $repository],
        $repository,
        ['PYTHONDONTWRITEBYTECODE' => '1', 'XDG_STATE_HOME' => false],
    );
    $process->setTimeout(180);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
});
