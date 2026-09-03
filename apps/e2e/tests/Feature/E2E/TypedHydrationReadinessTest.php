<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** @param list<array<string, mixed>> $instances */
function typedHydrationResponse(array $instances): string
{
    return json_encode([
        'app_instances' => $instances,
        'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

/**
 * @param  array{git_reset_exit_code?:int,root_invocation?:bool,transient_failures?:int,transient_response?:string,transient_exit_code?:int}  $options
 * @return array{root:string,checkout:string,script:string,state:string,environment:array<string,string>}
 */
function typedHydrationReadinessFixture(
    string $response,
    int $exitCode,
    array $options = [],
): array {
    $gitResetExitCode = $options['git_reset_exit_code'] ?? 0;
    $rootInvocation = $options['root_invocation'] ?? false;
    $transientFailures = $options['transient_failures'] ?? 0;
    $transientResponse = $options['transient_response'] ?? '';
    $transientExitCode = $options['transient_exit_code'] ?? 1;
    $root = temporaryPath('orbit-typed-hydration-readiness-', 6);
    $checkout = "{$root}/checkout";
    mkdir("{$root}/bin", 0o700, true);
    mkdir("{$checkout}/.git", 0o700, true);
    mkdir("{$checkout}/vendor", 0o700, true);
    mkdir("{$checkout}/storage", 0o700, true);
    mkdir("{$checkout}/bootstrap/cache", 0o700, true);
    file_put_contents("{$checkout}/composer.lock", "locked dependencies\n");
    file_put_contents("{$checkout}/vendor/autoload.php", "autoloaded\n");
    file_put_contents(
        "{$checkout}/vendor/.orbit-e2e-composer-lock",
        hash_file('sha256', "{$checkout}/composer.lock"),
    );
    file_put_contents("{$checkout}/.env", "APP_KEY=base64:fixture\n");
    file_put_contents("{$checkout}/artisan", <<<'PHP'
        <?php
        file_put_contents(
            (string) getenv('TYPED_HYDRATION_ARTISAN_CALLS'),
            implode(' ', array_slice($argv, 1)).PHP_EOL,
            FILE_APPEND,
        );
        PHP);
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
        state=$(dirname "$0")
        printf '%s\n' "$*" >>"$state/orbit-calls"
        printf '%s|%s|%s|%s\n' "${TYPED_HYDRATION_RUNTIME_USER-}" "$HOME" "${ORBIT_HOME-}" "${DB_DATABASE-}" >>"$state/orbit-profile"
        attempt=$(wc -l <"$state/orbit-calls")
        if [[ "$attempt" -le "$TYPED_HYDRATION_TRANSIENT_FAILURES" ]]; then
          printf '%s' "$TYPED_HYDRATION_TRANSIENT_RESPONSE"
          exit "$TYPED_HYDRATION_TRANSIENT_EXIT"
        fi
        printf '%s' "$TYPED_HYDRATION_RESPONSE"
        exit "$TYPED_HYDRATION_EXIT"
        BASH);
    file_put_contents("{$root}/bin/git", <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail
        printf '%s\n' "$*" >>"$TYPED_HYDRATION_GIT_CALLED"
        case "$*" in
          *' remote get-url origin')
            printf 'https://github.com/laravel/laravel.git\n'
            ;;
          *' cat-file -e '*)
            ;;
          *' reset --hard --quiet '*)
            printf '%s\n' "$*" >>"$TYPED_HYDRATION_GIT_RESET_CALLED"
            exit "$TYPED_HYDRATION_GIT_RESET_EXIT"
            ;;
          *' rev-parse HEAD')
            printf '%s\n' "$TYPED_HYDRATION_COMMIT"
            ;;
          *)
            exit 1
            ;;
        esac
        BASH);
    file_put_contents("{$root}/bin/sleep", <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail
        for mutation_log in \
          "$TYPED_HYDRATION_GIT_RESET_CALLED" \
          "$TYPED_HYDRATION_ARTISAN_CALLS" \
          "$TYPED_HYDRATION_MUTATION_CALLS"
        do
          [[ ! -e "$mutation_log" ]] || {
            touch "$TYPED_HYDRATION_MUTATED_DURING_PREFLIGHT"
            exit 99
          }
        done
        printf '%s\n' "$*" >>"$TYPED_HYDRATION_SLEEP_CALLS"
        BASH);
    foreach (['chmod', 'cp', 'install', 'touch'] as $command) {
        $wrapper = str_replace('__COMMAND__', $command, <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            printf '%s %s\n' '__COMMAND__' "$*" >>"$TYPED_HYDRATION_MUTATION_CALLS"
            exec /usr/bin/__COMMAND__ "$@"
            BASH);
        file_put_contents("{$root}/bin/{$command}", $wrapper);
        chmod("{$root}/bin/{$command}", 0o700);
    }
    file_put_contents("{$root}/bin/composer", <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail
        printf '%s\n' "composer $*" >>"$TYPED_HYDRATION_MUTATION_CALLS"
        exit 99
        BASH);
    if ($rootInvocation) {
        file_put_contents("{$root}/bin/id", <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            [[ "$*" == '-u' ]]
            if [[ "${TYPED_HYDRATION_RUNTIME_USER-}" == orbit ]]; then
              printf '1000\n'
            else
              printf '0\n'
            fi
            BASH);
        file_put_contents("{$root}/bin/sudo", <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            printf '%s\n' "$*" >>"$TYPED_HYDRATION_SUDO_CALLS"
            [[ "$1" == -u && "$2" == orbit && "$3" == -- && "$4" == env ]]
            shift 3
            export TYPED_HYDRATION_RUNTIME_USER=orbit
            exec "$@"
            BASH);
        chmod("{$root}/bin/id", 0o700);
        chmod("{$root}/bin/sudo", 0o700);
    }
    chmod("{$root}/orbit", 0o700);
    chmod("{$root}/bin/git", 0o700);
    chmod("{$root}/bin/sleep", 0o700);
    chmod("{$root}/bin/composer", 0o700);
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
            'TYPED_HYDRATION_ARTISAN_CALLS' => "{$root}/artisan-calls",
            'TYPED_HYDRATION_COMMIT' => str_repeat('b', 40),
            'TYPED_HYDRATION_EXIT' => (string) $exitCode,
            'TYPED_HYDRATION_GIT_CALLED' => "{$root}/git-called",
            'TYPED_HYDRATION_GIT_RESET_CALLED' => "{$root}/git-reset-called",
            'TYPED_HYDRATION_GIT_RESET_EXIT' => (string) $gitResetExitCode,
            'TYPED_HYDRATION_MUTATED_DURING_PREFLIGHT' => "{$root}/mutated-during-preflight",
            'TYPED_HYDRATION_MUTATION_CALLS' => "{$root}/mutation-calls",
            'TYPED_HYDRATION_RESPONSE' => $response,
            'TYPED_HYDRATION_SLEEP_CALLS' => "{$root}/sleep-calls",
            'TYPED_HYDRATION_SUDO_CALLS' => "{$root}/sudo-calls",
            'TYPED_HYDRATION_TRANSIENT_EXIT' => (string) $transientExitCode,
            'TYPED_HYDRATION_TRANSIENT_FAILURES' => (string) $transientFailures,
            'TYPED_HYDRATION_TRANSIENT_RESPONSE' => $transientResponse,
        ],
    ];
}

it('uses the Orbit runtime profile when root invokes typed hydration', function (): void {
    $response = typedHydrationResponse([[
        'id' => 4,
        'app_id' => 1,
        'node_id' => 2,
        'name' => 'e2e-dev',
        'status' => 'active',
        'checkout_path' => '__CHECKOUT__',
        'selected_branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'effective_root' => 'public',
    ]]);
    $fixture = typedHydrationReadinessFixture($response, 0, ['root_invocation' => true]);

    try {
        $process = new Process([
            'bash',
            $fixture['script'],
            'hydrate',
            str_repeat('b', 40),
            'app-dev',
            $fixture['checkout'],
        ], env: $fixture['environment']);

        expect($process->run())->toBe(0, $process->getErrorOutput());
        expect(file("{$fixture['root']}/sudo-calls", FILE_IGNORE_NEW_LINES))->toBe([
            '-u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite bash '
                .$fixture['script']
                .' hydrate '
                .str_repeat('b', 40)
                .' app-dev '
                .$fixture['checkout'],
        ]);
        expect(file("{$fixture['root']}/orbit-profile", FILE_IGNORE_NEW_LINES))->toBe([
            'orbit|/home/orbit|/home/orbit/.orbit|/home/orbit/.orbit/gateway.sqlite',
        ]);
        expect(file("{$fixture['root']}/orbit-calls", FILE_IGNORE_NEW_LINES))->toBe([
            'instance:list --json',
        ]);
        expect(file("{$fixture['root']}/git-reset-called", FILE_IGNORE_NEW_LINES))->toHaveCount(1);
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
});

it('hydrates once after readiness and six transient preflight failures within the 30 second bound', function (): void {
    $response = typedHydrationResponse([[
        'id' => 4,
        'app_id' => 1,
        'node_id' => 2,
        'name' => 'e2e-dev',
        'status' => 'active',
        'checkout_path' => '__CHECKOUT__',
        'selected_branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'effective_root' => 'public',
    ]]);
    $failure = json_encode(['error' => [
        'code' => 'gateway.unavailable',
        'message' => 'The gateway is temporarily unavailable.',
        'request_id' => null,
    ]], JSON_THROW_ON_ERROR);
    $fixture = typedHydrationReadinessFixture(
        $response,
        0,
        [
            'transient_failures' => 6,
            'transient_response' => $failure,
            'transient_exit_code' => 69,
        ],
    );

    try {
        $readinessEnvironment = [
            ...$fixture['environment'],
            'TYPED_HYDRATION_TRANSIENT_FAILURES' => '0',
        ];
        $readiness = new Process(
            ['bash', $fixture['script'], 'instance-api-readiness'],
            env: $readinessEnvironment,
        );
        expect($readiness->run())->toBe(0, $readiness->getErrorOutput());
        rename("{$fixture['root']}/orbit-calls", "{$fixture['root']}/readiness-calls");

        $process = new Process([
            'bash',
            $fixture['script'],
            'hydrate',
            str_repeat('b', 40),
            'app-dev',
            $fixture['checkout'],
        ], env: $fixture['environment']);

        expect($process->run())->toBe(0, $process->getErrorOutput());
        expect(file("{$fixture['root']}/readiness-calls", FILE_IGNORE_NEW_LINES))
            ->toBe(['instance:list --json']);
        expect(file("{$fixture['root']}/orbit-calls", FILE_IGNORE_NEW_LINES))
            ->toBe(array_fill(0, 7, 'instance:list --json'));
        expect(file("{$fixture['root']}/sleep-calls", FILE_IGNORE_NEW_LINES))
            ->toBe(array_fill(0, 6, '1'));
        expect(file("{$fixture['root']}/git-called", FILE_IGNORE_NEW_LINES))->toBe([
            '-C '.$fixture['checkout'].' remote get-url origin',
            '-C '.$fixture['checkout'].' cat-file -e '.str_repeat('b', 40).'^{commit}',
            '-C '.$fixture['checkout'].' reset --hard --quiet '.str_repeat('b', 40),
            '-C '.$fixture['checkout'].' rev-parse HEAD',
        ]);
        expect(file("{$fixture['root']}/artisan-calls", FILE_IGNORE_NEW_LINES))
            ->toBe(['migrate --force --no-interaction']);
        expect(file("{$fixture['root']}/mutation-calls", FILE_IGNORE_NEW_LINES))->toBe([
            'install -d -m 0775 '.$fixture['checkout'].'/storage '.$fixture['checkout'].'/bootstrap/cache',
            'chmod -R ug+rwX '.$fixture['checkout'].'/storage '.$fixture['checkout'].'/bootstrap/cache',
        ]);
        expect(file_exists("{$fixture['root']}/mutated-during-preflight"))->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
});

it('preserves the final CLI failure after the bounded hydration preflight', function (int $exitCode): void {
    $fixture = typedHydrationReadinessFixture('Gateway unavailable.', $exitCode);

    try {
        $process = new Process([
            'bash',
            $fixture['script'],
            'hydrate',
            str_repeat('b', 40),
            'app-dev',
            $fixture['checkout'],
        ], env: $fixture['environment']);

        expect($process->run())->toBe($exitCode);
        expect(file("{$fixture['root']}/orbit-calls", FILE_IGNORE_NEW_LINES))->toHaveCount(30);
        expect(file("{$fixture['root']}/sleep-calls", FILE_IGNORE_NEW_LINES))->toBe(array_fill(0, 29, '1'));
        expect($process->getErrorOutput())
            ->toContain(
                "hydrate: instance:list --json failed after 30 attempts; final attempt 30 exited with code {$exitCode}",
                'Gateway unavailable.',
            );
        expect(file_exists("{$fixture['root']}/git-called"))->toBeFalse();
        expect(file_exists("{$fixture['root']}/mutated-during-preflight"))->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
})->with([
    'service unavailable' => 69,
    'temporary failure' => 75,
]);

it('retries malformed instance envelopes before a bounded validation failure', function (string $response): void {
    $fixture = typedHydrationReadinessFixture($response, 0);

    try {
        $process = new Process([
            'bash',
            $fixture['script'],
            'hydrate',
            str_repeat('b', 40),
            'app-dev',
            $fixture['checkout'],
        ], env: $fixture['environment']);

        expect($process->run())->toBe(65);
        expect(file("{$fixture['root']}/orbit-calls", FILE_IGNORE_NEW_LINES))->toHaveCount(30);
        expect(file("{$fixture['root']}/sleep-calls", FILE_IGNORE_NEW_LINES))->toBe(array_fill(0, 29, '1'));
        expect($process->getErrorOutput())
            ->toContain(
                'hydrate: instance:list --json failed after 30 attempts; final attempt 30 validation failure',
                'malformed or unsupported response envelope',
            );
        expect(file_exists("{$fixture['root']}/git-called"))->toBeFalse();
        expect(file_exists("{$fixture['root']}/mutated-during-preflight"))->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
})->with([
    'malformed JSON' => 'not-json',
    'unsupported shape' => '{"data":[]}',
    'ambiguous shape' => '{"instances":[],"app_instances":[]}',
    'non-list collection' => '{"app_instances":{"named":{}}}',
]);

it('returns a non-retryable failure when a later hydration command exits 75', function (): void {
    $response = typedHydrationResponse([[
        'id' => 4,
        'app_id' => 1,
        'node_id' => 2,
        'name' => 'e2e-dev',
        'status' => 'active',
        'checkout_path' => '__CHECKOUT__',
        'selected_branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'effective_root' => 'public',
    ]]);
    $fixture = typedHydrationReadinessFixture($response, 0, ['git_reset_exit_code' => 75]);

    try {
        $process = new Process([
            'bash',
            $fixture['script'],
            'hydrate',
            str_repeat('b', 40),
            'app-dev',
            $fixture['checkout'],
        ], env: $fixture['environment']);

        expect($process->run())->toBe(1);
        expect(file("{$fixture['root']}/orbit-calls", FILE_IGNORE_NEW_LINES))
            ->toBe(['instance:list --json']);
        expect(file("{$fixture['root']}/git-reset-called", FILE_IGNORE_NEW_LINES))->toHaveCount(1);
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
});

it('fails immediately before checkout mutation for semantic typed state', function (string $response): void {
    $exitCode = 0;
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
        expect(file("{$fixture['root']}/orbit-calls", FILE_IGNORE_NEW_LINES))
            ->toBe(['instance:list --json']);
        expect(file_exists("{$fixture['root']}/sleep-calls"))->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($fixture['root']);
    }
})->with([
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
    ],
    'legacy envelope for typed hydration' => [
        json_encode([
            'instances' => [],
            'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ], JSON_THROW_ON_ERROR),
    ],
]);
