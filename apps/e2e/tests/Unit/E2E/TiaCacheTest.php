<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('selects the published graph directory while isolating background checks and failures', function (): void {
    $repository = dirname(__DIR__, 5);
    $environment = ['PYTHONDONTWRITEBYTECODE' => '1'];
    $evidenceDirectory = getenv('TIA_CACHE_EVIDENCE_DIR');

    if (is_string($evidenceDirectory) && $evidenceDirectory !== '') {
        $environment['TIA_CACHE_EVIDENCE_DIR'] = $evidenceDirectory;
    }

    $process = new Process(
        [
            'python3',
            dirname(__DIR__, 2).'/Fixtures/tia-cache-tests.py',
            $repository.'/bin/tia-cache',
        ],
        $repository,
        $environment,
    );
    $process->setTimeout(360);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
});
