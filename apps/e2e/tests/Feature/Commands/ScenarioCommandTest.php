<?php

declare(strict_types=1);

it('rejects a scenario selection that includes a non-string id', function (string $command, mixed $id): void {
    $environment = [
        'ORBIT_SCENARIO_CANDIDATE_SHA' => str_repeat('a', 40),
        'ORBIT_SCENARIO_REPOSITORY' => '/tmp/orbit-scenario-repository',
        'ORBIT_SCENARIO_PRIMARY_ROOT' => '/tmp/orbit-scenario-primary',
    ];
    $previous = [];
    foreach ($environment as $name => $value) {
        $previous[$name] = getenv($name);
        putenv("{$name}={$value}");
    }

    try {
        $this->artisan($command, ['--scenario' => ['cold-four-node', $id]])
            ->expectsOutputToContain('The scenario selection is invalid.')
            ->assertFailed();
    } finally {
        foreach ($previous as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv("{$name}={$value}");
            }
        }
    }
})->with([
    'cold null' => ['scenario:cold', null],
    'snapshot null' => ['scenario:snapshot', null],
    'bounded run null' => ['scenario:run', null],
    'cold integer' => ['scenario:cold', 1],
]);
