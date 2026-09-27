<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/** @return list<string> */
function orb277_projects(): array
{
    return ['apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'];
}

/**
 * Print the TIA directory a project's guidance configuration resolves, without the parent run's Pest state.
 */
function orb277_tia_directory(string $root, string|false $override): string
{
    $process = new Process(['vendor/bin/pest', '--configuration=phpunit.guidance.xml', '--baseline'], $root, [
        'COLLISION_PRINTER' => false,
        'ORBIT_TIA_DIRECTORY' => $override,
        'PARATEST' => false,
        'PEST_TIA' => false,
        'PEST_TIA_BASELINED' => false,
        'PEST_TIA_FILTERED' => false,
        'PEST_TIA_LOCALLY' => false,
        'TEST_TOKEN' => false,
        'UNIQUE_TEST_TOKEN' => false,
    ]);
    $process->setTimeout(120);
    $process->mustRun();
    $output = trim($process->getOutput());
    $compact = json_decode($output, true);

    return is_array($compact) && is_string($compact['raw'][0] ?? null) ? $compact['raw'][0] : $output;
}

/** @return array{root: string, main: string, candidate: string, path: string} */
function orb277_gate_fixture(?string $candidatePath = null): array
{
    $root = temporaryPath('orbit-builder-gate-', 6);

    mkdir($root.'/bin', 0o700, true);
    mkdir($root.'/tooling', 0o700, true);
    mkdir($root.'/apps/web/src/api', 0o700, true);
    mkdir($root.'/apps/web/node_modules/.bin', 0o700, true);
    mkdir($root.'/apps/pi-server', 0o700, true);
    mkdir($root.'/packages/agent-annotation', 0o700, true);
    mkdir($root.'/docs', 0o700, true);
    file_put_contents($root.'/packages/agent-annotation/.gitkeep', '');
    file_put_contents($root.'/apps/web/src/api/schema.d.ts', "export type Example = string;\n");
    file_put_contents($root.'/docs/openapi.json', "{}\n");

    foreach (orb277_projects() as $project) {
        mkdir($root.'/'.$project, 0o700, true);
        file_put_contents($root.'/'.$project.'/.gitkeep', '');
    }

    copy(base_path('../../bin/review-check'), $root.'/bin/review-check');
    copy(base_path('tests/Fixtures/BuilderGate/composer'), $root.'/tooling/composer');
    file_put_contents($root.'/bin/tia-cache', "#!/usr/bin/env sh\n\nexit 0\n");
    foreach (['bun', 'vp'] as $tool) {
        file_put_contents($root.'/tooling/'.$tool, <<<'SH'
#!/usr/bin/env sh
if [ "$1" = install ] && [ "${ORBIT_GATE_FAIL_INSTALL:-}" = "$PWD" ]; then
    echo 'bun install failed'
    exit 9
fi
exit 0
SH);

        chmod($root.'/tooling/'.$tool, 0o700);
    }
    file_put_contents($root.'/apps/web/node_modules/.bin/openapi-typescript', <<<'SH'
#!/usr/bin/env sh
while [ "$#" -gt 0 ]; do
    if [ "$1" = "-o" ]; then
        cp src/api/schema.d.ts "$2"
        exit $?
    fi
    shift
done
exit 2
SH);
    chmod($root.'/apps/web/node_modules/.bin/openapi-typescript', 0o700);
    chmod($root.'/bin/review-check', 0o700);
    chmod($root.'/bin/tia-cache', 0o700);
    chmod($root.'/tooling/composer', 0o700);

    foreach ([
        ['git', 'init', '--quiet', '--initial-branch=main'],
        ['git', 'config', 'user.name', 'Orbit test'],
        ['git', 'config', 'user.email', 'test@orbit.invalid'],
        ['git', 'add', '.'],
        ['git', 'commit', '--quiet', '-m', 'main'],
    ] as $arguments) {
        (new Process($arguments, $root))->mustRun();
    }

    $main = trim((new Process(['git', 'rev-parse', 'HEAD'], $root))->mustRun()->getOutput());

    $candidatePath ??= 'apps/gateway/app/Example.php';
    if ($candidatePath === 'apps/gateway/app/Example.php') {
        mkdir($root.'/apps/gateway/app', 0o700, true);
        mkdir($root.'/apps/gateway/tests', 0o700, true);
        file_put_contents($root.'/apps/gateway/app/Example.php', "<?php\n\nfinal class Example {}\n");
        file_put_contents($root.'/apps/gateway/tests/ExampleTest.php', "<?php\n\nit('exists', fn () => expect(true)->toBeTrue());\n");
    } elseif ($candidatePath === 'docs/openapi.json') {
        file_put_contents($root.'/'.$candidatePath, "{\"changed\": true}\n");
    } else {
        $path = $root.'/'.$candidatePath;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o700, true);
        }
        file_put_contents($path, "candidate change\n");
    }

    foreach ([
        ['git', 'checkout', '--quiet', '-b', 'orb-277-candidate'],
        ['git', 'add', '.'],
        ['git', 'commit', '--quiet', '-m', 'candidate'],
    ] as $arguments) {
        (new Process($arguments, $root))->mustRun();
    }

    return [
        'root' => $root,
        'main' => $main,
        'candidate' => trim((new Process(['git', 'rev-parse', 'HEAD'], $root))->mustRun()->getOutput()),
        'path' => $root.'/tooling:'.getenv('PATH'),
    ];
}

/** @return array{process: Process, receipt: array<string, mixed>} */
function orb277_run_gate(array $fixture, string $unselected, string $failInstall = '', int $expectedExit = 0): array
{
    $process = new Process([$fixture['root'].'/bin/review-check'], $fixture['root'], [
        'ORBIT_GATE_UNSELECTED' => $unselected,
        'ORBIT_GATE_FAIL_INSTALL' => $failInstall,
        'PATH' => $fixture['path'],
    ]);
    $process->setTimeout(60);
    $process->run();

    $head = trim((new Process(['git', 'rev-parse', 'HEAD'], $fixture['root']))->mustRun()->getOutput());
    $paths = glob("{$fixture['root']}/.git/orbit-checks/{$head}/review-*/result.json");

    expect($process->getExitCode())->toBe($expectedExit, $process->getOutput().$process->getErrorOutput());
    expect($paths)->toBeArray()->toHaveCount(1);

    return [
        'process' => $process,
        'receipt' => json_decode((string) file_get_contents($paths[0]), true, flags: JSON_THROW_ON_ERROR),
    ];
}

/** @param array<string, mixed> $receipt */
function orb277_check(array $receipt, string $project, string $script): array
{
    $check = collect($receipt['checks'])->first(
        static fn (array $check): bool => $check['project'] === $project && $check['command'] === ['composer', $script],
    );

    expect($check)->toBeArray();

    return $check;
}

describe('Builder gate', function (): void {
    it('selects the web profile for web changes', function (): void {
        $fixture = orb277_gate_fixture('apps/web/src/App.tsx');
        $receipt = orb277_run_gate($fixture, '')['receipt'];
        $web = collect($receipt['checks'])->where('project', 'apps/web')->values();

        expect($receipt['passed'])->toBeTrue()
            ->and($receipt['changed_paths'])->toBe(['apps/web/src/App.tsx'])
            ->and(collect($receipt['checks'])->pluck('project')->unique()->values()->all())
            ->toBe(['apps/web', 'packages/agent-annotation'])
            ->and($web->pluck('command')->all())->toHaveCount(4)
            ->and($web[0]['command'])->toBe(['bun', 'install', '--frozen-lockfile'])
            ->and($web[1]['command'])->toBe(['vp', 'check'])
            ->and($web[2]['command'])->toBe(['bun', 'run', 'test:unit'])
            ->and($web->pluck('exit_code')->all())->toBe([0, 0, 0, 0]);
        expect($web[3]['command'][0])->toBe('bash')
            ->and($web[3]['command'][2])->toContain('./node_modules/.bin/openapi-typescript');
        expect(collect($receipt['checks'])->firstWhere('project', 'packages/agent-annotation'))
            ->toMatchArray(['command' => ['bun', 'install', '--frozen-lockfile'], 'exit_code' => 0]);
    });

    it('selects only the generated web types check for OpenAPI changes', function (): void {
        $fixture = orb277_gate_fixture('docs/openapi.json');
        $receipt = orb277_run_gate($fixture, '')['receipt'];
        $web = collect($receipt['checks'])->where('project', 'apps/web')->values();

        expect($receipt['passed'])->toBeTrue()
            ->and($receipt['changed_paths'])->toBe(['docs/openapi.json'])
            ->and(collect($receipt['checks'])->pluck('project')->unique()->values()->all())->toBe(['apps/web'])
            ->and($web)->toHaveCount(2)
            ->and($web[0]['command'])->toBe(['bun', 'install', '--frozen-lockfile'])
            ->and($web[1]['command'][2])->toContain('./node_modules/.bin/openapi-typescript')
            ->and($web->pluck('exit_code')->all())->toBe([0, 0]);
    });

    it('selects the Pi server CI profile for Pi server changes', function (): void {
        $fixture = orb277_gate_fixture('apps/pi-server/src/index.ts');
        $receipt = orb277_run_gate($fixture, '')['receipt'];
        $piServer = collect($receipt['checks'])->where('project', 'apps/pi-server')->values();

        expect($receipt['passed'])->toBeTrue()
            ->and($receipt['changed_paths'])->toBe(['apps/pi-server/src/index.ts'])
            ->and(collect($receipt['checks'])->pluck('project')->unique()->values()->all())->toBe(['apps/pi-server'])
            ->and($piServer->pluck('command')->all())->toBe([
                ['bun', 'install', '--frozen-lockfile'],
                ['bun', 'run', 'check'],
                ['bun', 'run', 'test'],
                ['bun', 'run', 'build'],
            ])
            ->and($piServer->pluck('exit_code')->all())->toBe([0, 0, 0, 0]);
    });

    it('records web dependency installation failures in the receipt', function (): void {
        $fixture = orb277_gate_fixture('apps/web/src/App.tsx');
        $run = orb277_run_gate($fixture, '', $fixture['root'].'/apps/web', 1);
        $webInstall = collect($run['receipt']['checks'])->first(
            static fn (array $check): bool => $check['project'] === 'apps/web'
                && $check['command'] === ['bun', 'install', '--frozen-lockfile'],
        );

        expect($run['receipt']['passed'])->toBeFalse()
            ->and($webInstall)->toMatchArray(['exit_code' => 9])
            ->and(file_get_contents($webInstall['log']))->toContain('bun install failed');
    });

    it('runs the candidate gate from root composer check without a process timeout', function (): void {
        $composer = json_decode((string) file_get_contents(base_path('../../composer.json')), true, flags: JSON_THROW_ON_ERROR);

        expect($composer['scripts']['check'])->toBe(['Composer\\Config::disableProcessTimeout', 'bin/review-check']);
    });

    it('records guidance checks into a separate TIA cache in every project', function (string $project): void {
        $repository = realpath(base_path('../..'));
        $root = $repository.'/'.$project;
        $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $guidance = $root.'/vendor/.orbit-guidance-tia';

        expect($composer['scripts']['guidance:check'])->toBe(
            'ORBIT_TIA_DIRECTORY=vendor/.orbit-guidance-tia vendor/bin/pest --configuration=phpunit.guidance.xml --tia --fresh --compact',
        );
        expect(orb277_tia_directory($root, 'vendor/.orbit-guidance-tia'))->toBe($guidance);
        expect(orb277_tia_directory($root, false))->not->toStartWith($guidance);

        $ignored = new Process(['git', 'check-ignore', '-q', $project.'/vendor/.orbit-guidance-tia/graph.json'], $repository);
        $ignored->run();

        expect($ignored->getExitCode())->toBe(0, 'The guidance cache must stay out of the candidate tree.');
    })->with(orb277_projects());

    it('records a selection warning when affected tests select nothing for a changed project', function (): void {
        $fixture = orb277_gate_fixture();
        $run = orb277_run_gate($fixture, 'apps/gateway,apps/cli');
        $receipt = $run['receipt'];
        $changed = ['apps/gateway/app/Example.php', 'apps/gateway/tests/ExampleTest.php'];

        expect($receipt['passed'])->toBeTrue();
        expect($receipt['base'])->toBe($fixture['main']);
        expect($receipt['changed_paths'])->toBe($changed);
        expect($receipt['warnings'])->toHaveCount(1);
        expect($receipt['warnings'][0])
            ->toMatchArray(['project' => 'apps/gateway', 'command' => ['composer', 'test:affected'], 'paths' => $changed])
            ->and($receipt['warnings'][0]['message'])->toContain('selected no tests', '2 path(s) under apps/gateway/')
            ->and(file_exists($receipt['warnings'][0]['log']))->toBeTrue();
        expect(orb277_check($receipt, 'apps/gateway', 'test:affected'))
            ->toHaveKey('warning', $receipt['warnings'][0]['message']);
        expect(collect($receipt['checks'])->pluck('project')->unique()->values()->all())->toBe(['apps/gateway']);
        expect(orb277_check($receipt, 'apps/gateway', 'check'))->not->toHaveKey('warning');
        expect($run['process']->getOutput())
            ->toContain('[apps/gateway] WARNING: composer test:affected selected no tests', 'Selection warnings: 1')
            ->not->toContain('[apps/cli] WARNING');
    });

    it('records no selection warning when the changed project executes tests or the candidate is main', function (): void {
        $fixture = orb277_gate_fixture();
        $executed = orb277_run_gate($fixture, 'apps/cli')['receipt'];

        expect($executed['passed'])->toBeTrue();
        expect(collect($executed['checks'])->pluck('project')->unique()->values()->all())->toBe(['apps/gateway']);
        expect($executed['warnings'])->toBe([]);
        expect(collect($executed['checks'])->pluck('warning')->filter()->all())->toBe([]);

        (new Process(['git', 'checkout', '--quiet', 'main'], $fixture['root']))->mustRun();
        $main = orb277_run_gate($fixture, 'apps/gateway');

        expect($main['receipt']['candidate'])->toBe($fixture['main']);
        expect($main['receipt']['changed_paths'])->toBe([]);
        expect($main['receipt']['checks'])->toBe([]);
        expect($main['receipt']['warnings'])->toBe([]);
        expect($main['process']->getOutput())->not->toContain('WARNING');
    });
});
