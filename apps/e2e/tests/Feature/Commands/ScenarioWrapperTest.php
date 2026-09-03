<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

$wrapper = dirname(__DIR__, 5).'/bin/e2e-scenarios';

/** @return array{environment:array<string, string>, head:string, primary_root:string} */
function scenarioWrapperFixture(): array
{
    $root = temporaryPath('orbit-scenario-wrapper-', 5);
    $bin = "{$root}/bin";
    $commonGit = "{$root}/repository/.git";
    mkdir($bin, 0o700, true);
    mkdir($commonGit, 0o700, true);
    file_put_contents("{$bin}/git", <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail
        case "$*" in
          *' rev-parse --verify HEAD^{commit}') printf '%s\n' "$SCENARIO_TEST_HEAD" ;;
          *' status --porcelain --untracked-files=all -- . :!.e2e') ;;
          *' rev-parse --path-format=absolute --git-common-dir') printf '%s\n' "$SCENARIO_TEST_COMMON_GIT" ;;
          *) exit 64 ;;
        esac
        BASH);
    file_put_contents("{$bin}/composer", <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail
        printf 'candidate=%s\nrepository=%s\nprimary-root=%s\narguments=%s\n' \
          "$ORBIT_SCENARIO_CANDIDATE_SHA" \
          "$ORBIT_SCENARIO_REPOSITORY" \
          "$ORBIT_SCENARIO_PRIMARY_ROOT" \
          "$*"
        BASH);
    chmod("{$bin}/git", 0o700);
    chmod("{$bin}/composer", 0o700);

    $head = str_repeat('b', 40);

    return [
        'environment' => [
            'PATH' => $bin.':'.getenv('PATH'),
            'SCENARIO_TEST_COMMON_GIT' => $commonGit,
            'SCENARIO_TEST_HEAD' => $head,
        ],
        'head' => $head,
        'primary_root' => dirname($commonGit),
    ];
}

it('prints cold scenario usage and exits 64 for unsupported arguments', function (array $arguments) use ($wrapper) {
    $result = new Process([$wrapper, ...$arguments]);
    $result->run();

    expect($result->getExitCode())->toBe(64);
    expect($result->getErrorOutput())
        ->toContain('usage: bin/e2e-scenarios cold [CANDIDATE_SHA]')
        ->toContain('not part of feature development');
})->with([
    'missing track' => [[]],
    'unknown track' => [['snapshot']],
    'too many arguments' => [['cold', str_repeat('a', 40), 'extra']],
]);

it('runs the cold flow with the current HEAD by default or as an explicit assertion', function (bool $explicit) use ($wrapper) {
    $fixture = scenarioWrapperFixture();
    $arguments = [$wrapper, 'cold'];
    if ($explicit) {
        $arguments[] = $fixture['head'];
    }
    $result = new Process($arguments, env: $fixture['environment']);

    expect($result->run())->toBe(0, $result->getErrorOutput());
    expect($result->getOutput())
        ->toContain("candidate={$fixture['head']}")
        ->toContain('repository='.dirname(__DIR__, 5))
        ->toContain("primary-root={$fixture['primary_root']}")
        ->toContain('arguments=--working-dir='.dirname(__DIR__, 5).'/apps/e2e test:scenario-cold');
})->with([
    'resolved current HEAD' => [false],
    'explicit current HEAD' => [true],
]);

it('rejects a candidate that is not a full lowercase commit SHA', function () use ($wrapper) {
    $result = new Process([$wrapper, 'cold', 'main']);
    $result->run();

    expect($result->getExitCode())->toBe(64);
    expect($result->getErrorOutput())->toContain('40 lowercase hexadecimal characters');
});

it('rejects an explicit candidate that differs from the current HEAD', function () use ($wrapper) {
    $fixture = scenarioWrapperFixture();
    $result = new Process([$wrapper, 'cold', str_repeat('a', 40)], env: $fixture['environment']);
    $result->run();

    expect($result->getExitCode())->toBe(64);
    expect($result->getErrorOutput())->toContain('candidate must equal this checkout HEAD');
});

it('registers only the faithful cold flow outside the default test suites', function () use ($wrapper) {
    $source = (string) file_get_contents($wrapper);

    expect(is_executable($wrapper))->toBeTrue();
    expect($source)
        ->toContain('test:scenario-cold')
        ->toContain('ORBIT_SCENARIO_CANDIDATE_SHA')
        ->not->toContain('e2e-live', 'TOPOLOGY_SNAPSHOT_NAMESPACE', 'pcov');
});
