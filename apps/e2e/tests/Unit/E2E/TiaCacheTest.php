<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('shares real Pest results across worktrees and isolates cache maintenance', function (): void {
    $repository = dirname(__DIR__, 5);
    // Cache maintenance runs this suite with its own TIA directory; the fixture's bootstrap and seeding cases need defaults.
    $environment = ['PYTHONDONTWRITEBYTECODE' => '1', 'ORBIT_TIA_DIRECTORY' => false, 'ORBIT_MAIN_CACHE_STORE' => false];
    // The fixture starts its own Pest runner, outside this suite's worker state.
    foreach (array_keys($_SERVER + $_ENV) as $name) {
        if (is_string($name) && (str_starts_with($name, 'PEST_') || in_array($name, ['PARATEST', 'TEST_TOKEN', 'UNIQUE_TEST_TOKEN'], true))) {
            $environment[$name] = false;
        }
    }
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
