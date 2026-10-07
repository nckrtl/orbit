<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('passes, fails, and times out each Gateway smoke check against a fake Gateway', function (): void {
    $repository = dirname(__DIR__, 5);
    $process = new Process(
        ['python3', dirname(__DIR__, 2).'/Fixtures/gateway-smoke-tests.py', $repository],
        $repository,
        ['PYTHONDONTWRITEBYTECODE' => '1', 'SSL_CERT_FILE' => false],
    );
    $process->setTimeout(240);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
});
