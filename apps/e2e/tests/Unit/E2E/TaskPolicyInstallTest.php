<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('preserves a previous task helper when the documented install fails', function (): void {
    $repository = dirname(__DIR__, 5);
    $process = new Process(
        ['bash', dirname(__DIR__, 2).'/Fixtures/task-policy-install.sh'],
        $repository,
    );
    $process->setTimeout(60);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
});
