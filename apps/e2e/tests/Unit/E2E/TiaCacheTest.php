<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('publishes successful main graphs and seeds private worktree caches', function (): void {
    $repository = dirname(__DIR__, 5);
    $process = new Process(
        [
            'python3',
            dirname(__DIR__, 2).'/Fixtures/tia-cache-tests.py',
            $repository.'/bin/tia-cache',
        ],
        $repository,
        ['PYTHONDONTWRITEBYTECODE' => '1'],
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
});
