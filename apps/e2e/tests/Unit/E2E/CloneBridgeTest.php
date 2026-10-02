<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('finds a shared registration owned by the checkout owner', function (): void {
    $repository = dirname(__DIR__, 5);
    $process = new Process(
        ['python3', dirname(__DIR__, 2).'/Fixtures/e2e-clone-bridge-tests.py', $repository, 'CloneBridgeTest.test_finds_a_shared_registration_owned_by_the_checkout_owner'],
        $repository,
        ['PYTHONDONTWRITEBYTECODE' => '1', 'ORBIT_E2E_BRIDGE' => false, 'XDG_STATE_HOME' => false],
    );
    $process->setTimeout(120);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
});

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
