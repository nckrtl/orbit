<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** @return array{root:string,script:string,environment:array<string,string>} */
function gatewayReadinessBarrierFixture(string $response, int $exitCode): array
{
    $root = temporaryPath('orbit-gateway-readiness-', 6);
    mkdir($root, 0o700, true);
    file_put_contents("{$root}/orbit", <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail
        printf '%s\n' "$*" >>"$(dirname "$0")/commands"
        [[ "$*" == 'gateway:status --json' ]]
        printf '%s' "$GATEWAY_READINESS_RESPONSE"
        exit "$GATEWAY_READINESS_EXIT"
        BASH);
    chmod("{$root}/orbit", 0o700);

    $source = file_get_contents(dirname(__DIR__, 3).'/resources/guest/converge-sample-app.sh');
    $script = str_replace(
        'orbit=/home/orbit/orbit/apps/cli/orbit',
        "orbit={$root}/orbit",
        $source,
    );
    file_put_contents("{$root}/converge.sh", $script);
    chmod("{$root}/converge.sh", 0o700);

    return [
        'root' => $root,
        'script' => "{$root}/converge.sh",
        'environment' => [
            'GATEWAY_READINESS_EXIT' => (string) $exitCode,
            'GATEWAY_READINESS_RESPONSE' => $response,
        ],
    ];
}

function gatewayReadinessResponse(array $overrides = []): string
{
    return json_encode([
        'gateway' => 'e2e',
        'url' => 'https://10.44.0.1',
        'name' => 'orbit-gateway',
        'status' => 'ok',
        'version' => '0.1.0',
        'php_version' => '8.5.8',
        'laravel_version' => '13.26.1',
        'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ...$overrides,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

it('accepts a validated read-only Gateway status response', function (): void {
    $fixture = gatewayReadinessBarrierFixture(gatewayReadinessResponse(), 0);

    try {
        $process = new Process(
            ['bash', $fixture['script'], 'gateway-readiness'],
            env: $fixture['environment'],
        );

        expect($process->run())
            ->toBe(0, $process->getErrorOutput())
            ->and(file("{$fixture['root']}/commands", FILE_IGNORE_NEW_LINES))
            ->toBe(['gateway:status --json']);
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
});

it('fails closed when Gateway status is unavailable or invalid', function (string $response, int $exitCode): void {
    $fixture = gatewayReadinessBarrierFixture($response, $exitCode);

    try {
        $process = new Process(
            ['bash', $fixture['script'], 'gateway-readiness'],
            env: $fixture['environment'],
        );

        expect($process->run())
            ->not
            ->toBe(0)
            ->and(file("{$fixture['root']}/commands", FILE_IGNORE_NEW_LINES))
            ->toBe(['gateway:status --json']);
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
})->with([
    'unavailable Gateway' => [
        json_encode(['error' => ['code' => 'gateway.unavailable']], JSON_THROW_ON_ERROR),
        1,
    ],
    'malformed response' => ['not-json', 0],
    'wrong readiness status' => [gatewayReadinessResponse(['status' => 'starting']), 0],
    'missing request identity' => [gatewayReadinessResponse(['request_id' => null]), 0],
]);
