<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

$wrapper = dirname(__DIR__, 5).'/bin/e2e-scenarios';

it('prints cold scenario usage and exits 64 without exact arguments', function () use ($wrapper) {
    $result = Process::run([$wrapper]);

    expect($result->exitCode())->toBe(64);
    expect($result->errorOutput())
        ->toContain('usage: bin/e2e-scenarios cold CANDIDATE_SHA')
        ->toContain('not part of feature development');
});

it('rejects a candidate that is not a full lowercase commit SHA', function () use ($wrapper) {
    $result = Process::run([$wrapper, 'cold', 'main']);

    expect($result->exitCode())->toBe(64);
    expect($result->errorOutput())->toContain('40 lowercase hexadecimal characters');
});

it('registers only the faithful cold flow outside the default test suites', function () use ($wrapper) {
    $source = (string) file_get_contents($wrapper);

    expect(is_executable($wrapper))->toBeTrue();
    expect($source)
        ->toContain('test:scenario-cold')
        ->toContain('ORBIT_SCENARIO_CANDIDATE_SHA')
        ->not->toContain('e2e-live', 'TOPOLOGY_SNAPSHOT_NAMESPACE', 'pcov');
});
