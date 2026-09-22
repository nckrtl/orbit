<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

describe('task check execution', function (): void {
    it('rejects false and stale evidence through real subprocesses and isolated files', function (): void {
        $process = new Process([
            'python3', base_path('tests/Fixtures/task_check_test.py'), resource_path('tasks/check.py'),
        ], timeout: 30);
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
    });
});
