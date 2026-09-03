<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** @return array{root:string,checkout:string,script:string,state:string,environment:array<string,string>} */
function typedHydrationReadinessFixture(string $response, int $exitCode): array
{
    $root = temporaryPath('orbit-typed-hydration-readiness-', 6);
    $checkout = "{$root}/checkout";
    mkdir("{$root}/bin", 0o700, true);
    mkdir("{$checkout}/.git", 0o700, true);
    $response = str_replace('__CHECKOUT__', $checkout, $response);
    $state = "{$root}/sample-app-state.json";
    file_put_contents($state, json_encode([
        'shape' => 'app_instances',
        'app_id' => 1,
        'node_id' => 2,
        'name' => 'e2e-dev',
        'checkout_path' => $checkout,
        'effective_root' => 'public',
    ], JSON_THROW_ON_ERROR));
    file_put_contents("{$root}/orbit", <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail
        [[ "$*" == 'instance:list --json' ]]
        printf '%s' "$TYPED_HYDRATION_RESPONSE"
        exit "$TYPED_HYDRATION_EXIT"
        BASH);
    file_put_contents("{$root}/bin/git", <<<'BASH'
        #!/usr/bin/env bash
        touch "$TYPED_HYDRATION_GIT_CALLED"
        exit 1
        BASH);
    chmod("{$root}/orbit", 0o700);
    chmod("{$root}/bin/git", 0o700);
    $source = file_get_contents(dirname(__DIR__, 3).'/resources/guest/converge-sample-app.sh');
    $script = str_replace(
        [
            'orbit=/home/orbit/orbit/apps/cli/orbit',
            'sample_state=/home/orbit/.orbit/e2e-sample-app-state.json',
        ],
        ["orbit={$root}/orbit", "sample_state={$state}"],
        $source,
    );
    file_put_contents("{$root}/converge.sh", $script);
    chmod("{$root}/converge.sh", 0o700);

    return [
        'root' => $root,
        'checkout' => $checkout,
        'script' => "{$root}/converge.sh",
        'state' => $state,
        'environment' => [
            'PATH' => "{$root}/bin:".getenv('PATH'),
            'TYPED_HYDRATION_EXIT' => (string) $exitCode,
            'TYPED_HYDRATION_GIT_CALLED' => "{$root}/git-called",
            'TYPED_HYDRATION_RESPONSE' => $response,
        ],
    ];
}

it('classifies only bounded gateway readiness envelopes as retryable', function (string $code): void {
    $response = json_encode(['error' => [
        'code' => $code,
        'message' => 'The gateway is temporarily unavailable.',
        'request_id' => null,
    ]], JSON_THROW_ON_ERROR);
    $fixture = typedHydrationReadinessFixture($response, 1);

    try {
        $process = new Process([
            'bash',
            $fixture['script'],
            'hydrate',
            str_repeat('b', 40),
            'app-dev',
            $fixture['checkout'],
        ], env: $fixture['environment']);

        expect($process->run())->toBe(75);
        expect(file_exists("{$fixture['root']}/git-called"))->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
})->with(['gateway.unreachable', 'gateway.unavailable']);

it('fails closed before checkout mutation for semantic typed state', function (string $response, int $exitCode): void {
    $fixture = typedHydrationReadinessFixture($response, $exitCode);

    try {
        $process = new Process([
            'bash',
            $fixture['script'],
            'hydrate',
            str_repeat('b', 40),
            'app-dev',
            $fixture['checkout'],
        ], env: $fixture['environment']);

        expect($process->run())->not->toBe(0)->not->toBe(75);
        expect(file_exists("{$fixture['root']}/git-called"))->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
})->with([
    'malformed typed response' => ['not-json', 0],
    'wrong checkout path' => [
        json_encode([
            'app_instances' => [[
                'id' => 4,
                'app_id' => 1,
                'node_id' => 2,
                'name' => 'e2e-dev',
                'status' => 'active',
                'checkout_path' => '/wrong/checkout',
                'selected_branch' => 'main',
                'starting_commit' => str_repeat('a', 40),
                'effective_root' => 'public',
            ]],
        ], JSON_THROW_ON_ERROR),
        0,
    ],
    'wrong app identity' => [
        json_encode([
            'app_instances' => [[
                'id' => 4,
                'app_id' => 9,
                'node_id' => 2,
                'name' => 'e2e-dev',
                'status' => 'active',
                'checkout_path' => '__CHECKOUT__',
                'selected_branch' => 'main',
                'starting_commit' => str_repeat('a', 40),
                'effective_root' => 'public',
            ]],
        ], JSON_THROW_ON_ERROR),
        0,
    ],
    'wrong node identity' => [
        json_encode([
            'app_instances' => [[
                'id' => 4,
                'app_id' => 1,
                'node_id' => 9,
                'name' => 'e2e-dev',
                'status' => 'active',
                'checkout_path' => '__CHECKOUT__',
                'selected_branch' => 'main',
                'starting_commit' => str_repeat('a', 40),
                'effective_root' => 'public',
            ]],
        ], JSON_THROW_ON_ERROR),
        0,
    ],
    'inactive assignment' => [
        json_encode([
            'app_instances' => [[
                'id' => 4,
                'app_id' => 1,
                'node_id' => 2,
                'name' => 'e2e-dev',
                'status' => 'failed',
                'checkout_path' => '__CHECKOUT__',
                'selected_branch' => 'main',
                'starting_commit' => str_repeat('a', 40),
                'effective_root' => 'public',
            ]],
        ], JSON_THROW_ON_ERROR),
        0,
    ],
    'non-readiness gateway error' => [
        json_encode(['error' => [
            'code' => 'gateway.invalid_response',
            'message' => 'Gateway response is invalid.',
            'request_id' => null,
        ]], JSON_THROW_ON_ERROR),
        1,
    ],
    'malformed readiness envelope' => [
        json_encode([
            'error' => [
                'code' => 'gateway.unavailable',
                'message' => 'The gateway is temporarily unavailable.',
                'details' => ['retry_after' => 2],
                'request_id' => null,
            ],
        ], JSON_THROW_ON_ERROR),
        1,
    ],
]);
