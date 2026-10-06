<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('shares real Pest results across worktrees and isolates cache maintenance', function (): void {
    $repository = dirname(__DIR__, 5);
    // Three vendor trees exceed /tmp's inode budget during parallel gates. Keep this fixture on the worktree filesystem.
    // Do not use an ignored directory: Finder's ancestor ignore rules affect nested worktree fingerprints.
    $temporary = $repository.'/orbit_tia_'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    $files->makeDirectory($temporary, 0700, true);
    // Cache maintenance keeps TMPDIR, so nested Pest writes here instead of shared /tmp.
    // Cache maintenance runs this suite with its own TIA directory; the fixture's bootstrap and seeding cases need defaults.
    $environment = ['TMPDIR' => $temporary, 'PYTHONDONTWRITEBYTECODE' => '1', 'ORBIT_TIA_DIRECTORY' => false, 'ORBIT_MAIN_CACHE_STORE' => false];
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
    try {
        $process->run();
        expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
    } finally {
        $files->deleteDirectory($temporary);
    }
});
