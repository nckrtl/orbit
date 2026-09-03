<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** @return array{root:string,script:string,environment:array<string,string>} */
function instanceApiReadinessFixture(string $response, int $exitCode): array
{
    $root = temporaryPath('orbit-instance-api-readiness-', 6);
    mkdir($root, 0o700, true);
    file_put_contents("{$root}/orbit", <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail
        printf '%s\n' "$*" >>"$(dirname "$0")/commands"
        [[ "$*" == 'instance:list --json' ]]
        printf '%s' "$INSTANCE_API_READINESS_RESPONSE"
        exit "$INSTANCE_API_READINESS_EXIT"
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
            'INSTANCE_API_READINESS_EXIT' => (string) $exitCode,
            'INSTANCE_API_READINESS_RESPONSE' => $response,
        ],
    ];
}

function instanceApiReadinessResponse(string $shape, array $items = []): string
{
    return json_encode([
        $shape => $items,
        'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

it('accepts validated read-only legacy and typed instance-list envelopes', function (
    string $shape,
    array $items,
): void {
    $fixture = instanceApiReadinessFixture(instanceApiReadinessResponse($shape, $items), 0);

    try {
        $process = new Process(
            ['bash', $fixture['script'], 'instance-api-readiness'],
            env: $fixture['environment'],
        );

        expect($process->run())
            ->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())
            ->toContain("instance:list --json validated {$shape} envelope")
            ->and(file("{$fixture['root']}/commands", FILE_IGNORE_NEW_LINES))
            ->toBe(['instance:list --json']);
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
})->with([
    'legacy instances' => ['instances', [['name' => 'sample']]],
    'typed AppInstances' => ['app_instances', [['name' => 'sample']]],
    'empty object item' => ['instances', [(object) []]],
]);

it('fails closed when the instance-list probe is unavailable or invalid', function (
    string $response,
    int $exitCode,
    int $expectedExitCode,
): void {
    $fixture = instanceApiReadinessFixture($response, $exitCode);

    try {
        $process = new Process(
            ['bash', $fixture['script'], 'instance-api-readiness'],
            env: $fixture['environment'],
        );

        expect($process->run())
            ->toBe($expectedExitCode)
            ->and($process->getErrorOutput())
            ->toContain('instance-api-readiness: instance:list --json')
            ->and(file("{$fixture['root']}/commands", FILE_IGNORE_NEW_LINES))
            ->toBe(['instance:list --json']);
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
})->with([
    'unavailable instance API' => [
        json_encode(['error' => ['code' => 'gateway.unavailable']], JSON_THROW_ON_ERROR),
        69,
        69,
    ],
    'malformed response' => ['not-json', 0, 65],
    'both recognized shapes' => [
        json_encode([
            'instances' => [],
            'app_instances' => [],
            'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ], JSON_THROW_ON_ERROR),
        0,
        65,
    ],
    'non-list collection' => [
        json_encode([
            'instances' => ['named' => []],
            'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ], JSON_THROW_ON_ERROR),
        0,
        65,
    ],
    'scalar collection item' => [instanceApiReadinessResponse('instances', ['invalid']), 0, 65],
    'list collection item' => [instanceApiReadinessResponse('instances', [[]]), 0, 65],
    'missing request identity' => [json_encode(['instances' => []], JSON_THROW_ON_ERROR), 0, 65],
    'invalid request identity' => [
        json_encode(['instances' => [], 'request_id' => 'invalid'], JSON_THROW_ON_ERROR),
        0,
        65,
    ],
]);
