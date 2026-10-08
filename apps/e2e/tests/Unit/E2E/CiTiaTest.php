<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('selects affected tests on main only when Pest can see every change', function (): void {
    $repository = dirname(__DIR__, 5);
    $process = new Process(
        ['python3', dirname(__DIR__, 2).'/Fixtures/ci-tia-tests.py', $repository],
        $repository,
        ['PYTHONDONTWRITEBYTECODE' => '1', 'GITHUB_OUTPUT' => false, 'ORBIT_TIA_DIRECTORY' => false],
    );
    $process->setTimeout(180);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
});
