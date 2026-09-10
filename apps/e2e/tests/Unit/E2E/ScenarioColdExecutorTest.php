<?php

declare(strict_types=1);

use App\E2E\ScenarioColdExecutor;
use App\E2E\Value\ColdTopologyCleanupResult;
use App\Exceptions\E2E\ColdTopologyCleanupException;

it('turns process signals into interruption diagnostics', function (int $signal): void {
    $executor = (new ReflectionClass(ScenarioColdExecutor::class))->newInstanceWithoutConstructor();
    $install = new ReflectionMethod($executor, 'installSignalHandlers');
    $previous = [];
    foreach ([SIGINT, SIGTERM, SIGALRM] as $installedSignal) {
        $previous[$installedSignal] = pcntl_signal_get_handler($installedSignal);
    }

    try {
        $install->invoke($executor);

        expect(fn (): bool => posix_kill(getmypid(), $signal))
            ->toThrow(RuntimeException::class, "Scenario construction was interrupted by signal {$signal}.");
    } finally {
        pcntl_alarm(0);
        foreach ($previous as $installedSignal => $handler) {
            pcntl_signal($installedSignal, $handler);
        }
    }
})->with([
    'interrupt' => [SIGINT],
    'terminate' => [SIGTERM],
]);

it('preserves the original construction failure and its failed cleanup details', function (): void {
    $executor = (new ReflectionClass(ScenarioColdExecutor::class))->newInstanceWithoutConstructor();
    $classify = new ReflectionMethod($executor, 'constructionFailure');
    $primary = new RuntimeException('original construction diagnostic');
    $cleanup = new ColdTopologyCleanupResult(
        ['removed-vm'],
        [],
        ['foreign network refused'],
        ['remaining-network'],
        'bin/e2e-scenarios cleanup aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa cold-four-node bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    );

    [$failure, $retainedCleanup] = $classify->invoke(
        $executor,
        new ColdTopologyCleanupException($cleanup, $primary),
    );

    expect($failure)->toBe($primary);
    expect($failure->getMessage())->toBe('original construction diagnostic');
    expect($retainedCleanup)->toBe($cleanup);
    expect($retainedCleanup?->toArray())->toBe([
        'removed' => ['removed-vm'],
        'absent' => [],
        'refused' => ['foreign network refused'],
        'remaining' => ['remaining-network'],
        'recovery_command' => 'bin/e2e-scenarios cleanup aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa cold-four-node bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    ]);
});
