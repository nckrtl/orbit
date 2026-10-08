<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/** @return list<string> */
function orb277_projects(): array
{
    return ['apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'];
}

/** @return list<string> */
function orb277_selected_projects(): array
{
    return ['repository', ...orb277_projects()];
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
function orb277_gate_fixture(
    ?string $candidatePath = null,
    ?string $mainTestPath = null,
    string $changedProject = 'apps/gateway',
): array {
    $root = temporaryPath('orbit-builder-gate-', 6);

    mkdir($root.'/bin', 0o700, true);
    mkdir($root.'/tooling', 0o700, true);
    mkdir($root.'/apps/web/src/api', 0o700, true);
    mkdir($root.'/apps/web/node_modules/.bin', 0o700, true);
    mkdir($root.'/apps/pi-server', 0o700, true);
    mkdir($root.'/docs', 0o700, true);
    file_put_contents($root.'/.gitignore', ".orbit-tia/\n");
    file_put_contents($root.'/apps/web/src/api/schema.d.ts', "export type Example = string;\n");
    file_put_contents($root.'/docs/openapi.json', "{}\n");

    foreach (orb277_projects() as $project) {
        mkdir($root.'/'.$project, 0o700, true);
        file_put_contents($root.'/'.$project.'/.gitkeep', '');
    }
    foreach (orb277_projects() as $project) {
        mkdir($root.'/'.$project.'/vendor/bin', 0o700, true);
        file_put_contents($root.'/'.$project.'/vendor/bin/pest', "#!/usr/bin/env sh\nexit 0\n");
        chmod($root.'/'.$project.'/vendor/bin/pest', 0o700);
    }
    file_put_contents($root.'/apps/gateway/vendor/bin/pest', <<<'SH'
#!/usr/bin/env sh
if [ "$1" = "--list-tests" ]; then
    if [ "${ORBIT_GATE_HIDE_TESTS:-}" = 1 ]; then
        echo 'Available test:'
    else
        echo 'Available test:'
        echo ' - ExampleTest::exists'
    fi
elif [ "${ORBIT_GATE_HIDE_TESTS:-}" = 1 ]; then
    echo 'No tests ran.'
    exit 1
fi
exit 0
SH);
    chmod($root.'/apps/gateway/vendor/bin/pest', 0o700);
    file_put_contents($root.'/bin/pest-plain', "#!/usr/bin/env sh\nexec \"$@\"\n");
    chmod($root.'/bin/pest-plain', 0o700);

    copy(base_path('../../bin/review-check'), $root.'/bin/review-check');
    copy(base_path('../../bin/check-classification-fakes'), $root.'/bin/check-classification-fakes');
    file_put_contents($root.'/bin/docs-impact', "#!/usr/bin/env sh\nexit 0\n");
    file_put_contents($root.'/bin/project-vocabulary', "#!/usr/bin/env sh\nexit 0\n");
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
    chmod($root.'/bin/check-classification-fakes', 0o700);
    chmod($root.'/bin/docs-impact', 0o700);
    chmod($root.'/bin/project-vocabulary', 0o700);
    chmod($root.'/bin/tia-cache', 0o700);
    chmod($root.'/tooling/composer', 0o700);

    if ($mainTestPath !== null) {
        $path = $root.'/'.$mainTestPath;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o700, true);
        }
        file_put_contents($path, "<?php\n\nit('legacy test', fn () => expect(true)->toBeTrue());\n");
    }

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

    $candidatePath ??= $changedProject === 'apps/gateway'
        ? 'apps/gateway/app/Example.php'
        : $changedProject.'/changed.txt';
    if ($candidatePath === 'apps/gateway/app/Example.php') {
        mkdir($root.'/apps/gateway/app', 0o700, true);
        if (! is_dir($root.'/apps/gateway/tests')) {
            mkdir($root.'/apps/gateway/tests', 0o700, true);
        }
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
function orb277_run_gate(
    array $fixture,
    string $unselected,
    string $failInstall = '',
    int $expectedExit = 0,
    bool $discoverTests = true,
    string $tiaDirectory = '',
    ?string $testBase = null,
    bool $ci = false,
): array {
    $process = new Process([$fixture['root'].'/bin/review-check'], $fixture['root'], [
        'ORBIT_GATE_UNSELECTED' => $discoverTests ? $unselected : trim($unselected.',apps/gateway', ','),
        'ORBIT_GATE_FAIL_INSTALL' => $failInstall,
        'ORBIT_GATE_HIDE_TESTS' => $discoverTests ? '' : '1',
        'ORBIT_TIA_DIRECTORY' => $tiaDirectory === '' ? false : $tiaDirectory,
        'PATH' => $fixture['path'],
        'ORBIT_TASK_CHECK_BASE' => $testBase ?? false,
        'CI' => $ci ? 'true' : false,
        'GITHUB_ACTIONS' => false,
    ]);
    $process->setTimeout(60);
    $process->run();

    $head = trim((new Process(['git', 'rev-parse', 'HEAD'], $fixture['root']))->mustRun()->getOutput());
    $paths = glob("{$fixture['root']}/.git/orbit-checks/{$head}/review-*/result.json");

    expect($process->getExitCode())->toBe($expectedExit, $process->getOutput().$process->getErrorOutput());
    expect($paths)->toBeArray()->toHaveCount(1);
    expect(fileperms(dirname($paths[0])) & 0070)->toBe(0050);

    return [
        'process' => $process,
        'receipt' => json_decode((string) file_get_contents($paths[0]), true, flags: JSON_THROW_ON_ERROR),
    ];
}

/** @return list<string> */
function orb277_full_suite_command(): array
{
    return [
        '../../bin/pest-plain', 'vendor/bin/pest', '--parallel', '--processes=4', '--no-tia', '--fail-on-empty-test-suite',
        '--compact', '--colors=never',
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

/**
 * Point the gateway stand-in at the run copy so the test can read the graph Pest would see.
 */
function orb277_install_recording_pest(string $root, string $record, string $directoryRecord): void
{
    $pest = <<<'SH'
#!/usr/bin/env sh
if [ "$1" = "--list-tests" ]; then
    echo 'Available test:'
    echo ' - ExampleTest::exists'
    exit 0
fi
if [ -n "${ORBIT_TIA_DIRECTORY:-}" ] && [ -f "${ORBIT_TIA_DIRECTORY}/graph.json" ]; then
    printf '%s\n' "$ORBIT_TIA_DIRECTORY" > "__DIRECTORY__"
    cp "${ORBIT_TIA_DIRECTORY}/graph.json" "__RECORD__"
fi
exit 0
SH;
    file_put_contents($root.'/apps/gateway/vendor/bin/pest', str_replace(
        ['__DIRECTORY__', '__RECORD__'],
        [$directoryRecord, $record],
        $pest,
    ));
    chmod($root.'/apps/gateway/vendor/bin/pest', 0o700);

    file_put_contents($root.'/tooling/composer', <<<'PHP'
#!/usr/bin/env php
<?php

declare(strict_types=1);

$command = $argv[1] ?? '';

if ($command === 'test:affected') {
    $pest = (getcwd() ?: '.').'/vendor/bin/pest';
    if (is_file($pest)) {
        $process = proc_open([$pest], [1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
        if (is_resource($process)) {
            proc_close($process);
        }
    }

    $tiaDirectory = getenv('ORBIT_TIA_DIRECTORY');
    $tiaDirectory = is_string($tiaDirectory) && $tiaDirectory !== '' ? $tiaDirectory : '.orbit-tia';
    if (! str_starts_with($tiaDirectory, DIRECTORY_SEPARATOR)) {
        $tiaDirectory = (getcwd() ?: '.').DIRECTORY_SEPARATOR.$tiaDirectory;
    }
    if (! is_dir($tiaDirectory)) {
        mkdir($tiaDirectory, 0700, true);
    }
    file_put_contents($tiaDirectory.'/affected.json', json_encode(['tests/ExampleTest.php'], JSON_THROW_ON_ERROR));
    fwrite(STDOUT, "\n  Tests:    3 passed (9 assertions)\n\n");
}

exit(0);
PHP);
    chmod($root.'/tooling/composer', 0o700);
}

describe('Builder gate', function (): void {
    it('selects the web profile for web changes', function (): void {
        $fixture = orb277_gate_fixture('apps/web/src/App.tsx');
        $receipt = orb277_run_gate($fixture, '')['receipt'];
        $web = collect($receipt['checks'])->where('project', 'apps/web')->values();

        expect($receipt['passed'])->toBeTrue()
            ->and($receipt['changed_paths'])->toBe(['apps/web/src/App.tsx'])
            ->and(collect($receipt['checks'])->pluck('project')->unique()->values()->all())
            ->toBe([...orb277_selected_projects(), 'apps/web'])
            ->and($web->pluck('command')->all())->toHaveCount(4)
            ->and($web[0]['command'])->toBe(['bun', 'install', '--frozen-lockfile'])
            ->and($web[1]['command'])->toBe(['bun', 'run', 'check'])
            ->and($web[2]['command'])->toBe(['bun', 'run', 'build'])
            ->and($web->pluck('exit_code')->all())->toBe([0, 0, 0, 0]);
        expect($web[3]['command'][0])->toBe('bash')
            ->and($web[3]['command'][2])->toContain('./node_modules/.bin/openapi-typescript');
    });

    it('selects only the generated web types check for OpenAPI changes', function (): void {
        $fixture = orb277_gate_fixture('docs/openapi.json');
        $receipt = orb277_run_gate($fixture, '')['receipt'];
        $web = collect($receipt['checks'])->where('project', 'apps/web')->values();

        expect($receipt['passed'])->toBeTrue()
            ->and($receipt['changed_paths'])->toBe(['docs/openapi.json'])
            ->and(collect($receipt['checks'])->pluck('project')->unique()->values()->all())->toBe([...orb277_selected_projects(), 'apps/web'])
            ->and($web)->toHaveCount(2)
            ->and($web[0]['command'])->toBe(['bun', 'install', '--frozen-lockfile'])
            ->and($web[1]['command'][2])->toContain('./node_modules/.bin/openapi-typescript')
            ->and($web->pluck('exit_code')->all())->toBe([0, 0]);
    });

    it('runs every PHP project for documentation-only and tools changes', function (string $path): void {
        $fixture = orb277_gate_fixture($path);
        $receipt = orb277_run_gate($fixture, '')['receipt'];

        expect($receipt['passed'])->toBeTrue()
            ->and($receipt['changed_paths'])->toBe([$path])
            ->and(collect($receipt['checks'])->pluck('project')->unique()->values()->all())->toBe(orb277_selected_projects())
            ->and(collect($receipt['checks'])->count())->toBe(17);
    })->with([
        'documentation-only change' => ['docs/reference/example.md'],
        'tools change' => ['tools/phpstan/NoInlineVarOverrideRule.php'],
    ]);

    it('selects the Pi server CI profile for Pi server changes', function (): void {
        $fixture = orb277_gate_fixture('apps/pi-server/src/index.ts');
        $receipt = orb277_run_gate($fixture, '')['receipt'];
        $piServer = collect($receipt['checks'])->where('project', 'apps/pi-server')->values();

        expect($receipt['passed'])->toBeTrue()
            ->and($receipt['changed_paths'])->toBe(['apps/pi-server/src/index.ts'])
            ->and(collect($receipt['checks'])->pluck('project')->unique()->values()->all())->toBe([...orb277_selected_projects(), 'apps/pi-server'])
            ->and($piServer->pluck('command')->all())->toBe([
                ['bun', 'install', '--frozen-lockfile'],
                ['bun', 'run', 'check'],
                ['bun', 'run', 'test'],
                ['bun', 'run', 'build'],
            ])
            ->and($piServer->pluck('exit_code')->all())->toBe([0, 0, 0, 0]);
    });

    it('fails with the required tool name when a selected check tool is missing', function (): void {
        $fixture = orb277_gate_fixture('apps/web/src/App.tsx');
        unlink($fixture['root'].'/tooling/bun');
        $fixture['path'] = $fixture['root'].'/tooling:/usr/bin:/bin';
        $run = orb277_run_gate($fixture, '', expectedExit: 1);
        $install = collect($run['receipt']['checks'])->first(
            static fn (array $check): bool => $check['project'] === 'apps/web'
                && $check['command'] === ['bun', 'install', '--frozen-lockfile'],
        );

        expect($run['receipt']['passed'])->toBeFalse()
            ->and($install)->toMatchArray(['exit_code' => 127])
            ->and(file_get_contents($install['log']))->toContain('bun: required tool not found');
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
            in_array($project, ['apps/gateway', 'apps/cli', 'apps/e2e'], true)
                ? '../../bin/guidance-check'
                : 'ORBIT_TIA_DIRECTORY=vendor/.orbit-guidance-tia vendor/bin/pest --configuration=phpunit.guidance.xml --tia --fresh --compact',
        );
        expect(orb277_tia_directory($root, 'vendor/.orbit-guidance-tia'))->toBe($guidance);
        expect(orb277_tia_directory($root, false))->not->toStartWith($guidance);

        $ignored = new Process(['git', 'check-ignore', '-q', $project.'/vendor/.orbit-guidance-tia/graph.json'], $repository);
        $ignored->run();

        expect($ignored->getExitCode())->toBe(0, 'The guidance cache must stay out of the candidate tree.');
    })->with(orb277_projects());

    it('fails when Pest does not discover a changed test file', function (): void {
        $fixture = orb277_gate_fixture();
        $run = orb277_run_gate($fixture, '', expectedExit: 1, discoverTests: false);
        $check = collect($run['receipt']['checks'])->first(
            static fn (array $check): bool => $check['command'] === ['vendor/bin/pest', '--list-tests', 'tests/ExampleTest.php'],
        );

        expect($check)->not->toBeNull()
            ->and($check['exit_code'])->toBe(1)
            ->and(file_get_contents($check['log']))->toContain('Pest did not discover any tests');
        $pathRun = collect($run['receipt']['checks'])->first(
            static fn (array $check): bool => $check['command'] === [
                '../../bin/pest-plain', 'vendor/bin/pest', '--no-tia', '--fail-on-empty-test-suite',
                '--compact', '--colors=never', 'tests/ExampleTest.php',
            ],
        );
        expect($pathRun)->not->toBeNull()->and($pathRun['exit_code'])->toBe(1);
    });

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
        expect(collect($receipt['checks'])->pluck('project')->unique()->values()->all())->toBe(orb277_selected_projects());
        expect(collect($receipt['checks'])->pluck('command')->all())
            ->toContain(['vendor/bin/pest', '--list-tests', 'tests/ExampleTest.php'])
            ->toContain(['../../bin/pest-plain', 'vendor/bin/pest', '--no-tia', '--fail-on-empty-test-suite', '--compact', '--colors=never', 'tests/ExampleTest.php'])
            ->toContain(orb277_full_suite_command());
        $suite = collect($receipt['checks'])->first(
            static fn (array $check): bool => $check['project'] === 'apps/gateway'
                && $check['command'] === orb277_full_suite_command(),
        );
        expect($suite)->toMatchArray(['exit_code' => 0])
            ->and(file_exists($suite['log']))->toBeTrue();
        expect(collect($receipt['checks'])->contains(
            static fn (array $check): bool => $check['project'] === 'apps/cli'
                && $check['command'] === orb277_full_suite_command(),
        ))->toBeFalse();
        expect(orb277_check($receipt, 'apps/gateway', 'check'))->not->toHaveKey('warning');
        expect(collect($receipt['checks'])->first(static fn (array $check): bool => $check['project'] === 'apps/gateway'
            && $check['command'] === ['vendor/bin/pest', 'tests/Unit/Architecture']))
            ->toMatchArray(['exit_code' => 0]);
        expect($run['process']->getOutput())
            ->toContain('[apps/gateway] WARNING: composer test:affected selected no tests', 'Selection warnings: 1')
            ->not->toContain('[apps/cli] WARNING');
    });

    it('runs the full suite for an empty selection on a source-only change', function (): void {
        $fixture = orb277_gate_fixture('apps/gateway/app/SourceOnly.php');
        $run = orb277_run_gate($fixture, 'apps/gateway,apps/cli');
        $receipt = $run['receipt'];
        $fullSuite = orb277_full_suite_command();
        $suite = collect($receipt['checks'])->first(
            static fn (array $check): bool => $check['project'] === 'apps/gateway' && $check['command'] === $fullSuite,
        );

        expect($receipt['passed'])->toBeTrue()
            ->and($receipt['changed_paths'])->toBe(['apps/gateway/app/SourceOnly.php'])
            ->and($suite)->toBeArray()
            ->and($suite['exit_code'])->toBe(0)
            ->and(file_exists($suite['log']))->toBeTrue();
        expect(collect($receipt['checks'])->pluck('command')->all())
            ->not->toContain(['vendor/bin/pest', '--list-tests', 'tests/ExampleTest.php']);
        expect(collect($receipt['checks'])->contains(
            static fn (array $check): bool => $check['project'] !== 'apps/gateway' && $check['command'] === $fullSuite,
        ))->toBeFalse();
    });

    it('fails the gate when the empty selection full suite fails', function (): void {
        $fixture = orb277_gate_fixture('apps/gateway/app/SourceOnly.php');
        file_put_contents($fixture['root'].'/apps/gateway/vendor/bin/pest', <<<'SH'
#!/usr/bin/env sh
case " $* " in
    *" --no-tia "*) echo 'full suite failed'; exit 4 ;;
esac
exit 0
SH);
        chmod($fixture['root'].'/apps/gateway/vendor/bin/pest', 0o700);
        $run = orb277_run_gate($fixture, 'apps/gateway', expectedExit: 1);
        $suite = collect($run['receipt']['checks'])->first(
            static fn (array $check): bool => $check['project'] === 'apps/gateway'
                && $check['command'] === orb277_full_suite_command(),
        );

        expect($run['receipt']['passed'])->toBeFalse()
            ->and($suite)->toBeArray()
            ->and($suite['exit_code'])->toBe(4)
            ->and(file_get_contents($suite['log']))->toContain('full suite failed');
    });

    it('runs only changed tests that affected-test analysis did not select', function (): void {
        $fixture = orb277_gate_fixture();
        $additionalTest = $fixture['root'].'/apps/gateway/tests/AdditionalTest.php';
        file_put_contents($additionalTest, "<?php\n\nit('additional', fn () => expect(true)->toBeTrue());\n");

        $receipt = orb277_run_gate($fixture, '')['receipt'];
        $commands = collect($receipt['checks'])->pluck('command')->all();

        expect(orb277_check($receipt, 'apps/gateway', 'test:affected'))
            ->toHaveKey('selected_test_files', ['tests/ExampleTest.php']);
        expect($commands)
            ->toContain(['vendor/bin/pest', '--list-tests', 'tests/AdditionalTest.php'])
            ->toContain(['../../bin/pest-plain', 'vendor/bin/pest', '--no-tia', '--fail-on-empty-test-suite', '--compact', '--colors=never', 'tests/AdditionalTest.php'])
            ->not->toContain(['vendor/bin/pest', '--list-tests', 'tests/ExampleTest.php']);
    });

    it('checks changed Pest setup and helper sources for guarded classification fakes', function (): void {
        $fixture = orb277_gate_fixture();
        $root = $fixture['root'];
        file_put_contents($root.'/apps/gateway/tests/Pest.php', <<<'PHP'
<?php
use Laravel\Ai\Classification;
Classification::fake(fn (): array => [])->preventStrayClassifications();
PHP);
        mkdir($root.'/apps/gateway/tests/Support', 0o700, true);
        file_put_contents($root.'/apps/gateway/tests/Support/ClassificationFixtures.php', <<<'PHP'
<?php
\Laravel\Ai\Classification::fake(function (): array {
    return [];
})->preventStrayClassifications();
PHP);

        $receipt = orb277_run_gate($fixture, '')['receipt'];
        $check = collect($receipt['checks'])->first(
            static fn (array $check): bool => ($check['command'][1] ?? null) === '../../bin/check-classification-fakes',
        );

        expect($receipt['passed'])->toBeTrue()
            ->and($check['command'] ?? [])->toBe([
                'php', '../../bin/check-classification-fakes', 'tests/ExampleTest.php', 'tests/Pest.php',
                'tests/Support/ClassificationFixtures.php',
            ]);
    });

    it('uses current-run TIA evidence under an override instead of stale default evidence', function (): void {
        $fixture = orb277_gate_fixture();
        $root = $fixture['root'];
        mkdir($root.'/apps/gateway/.orbit-tia', 0o700, true);
        file_put_contents($root.'/apps/gateway/.orbit-tia/affected.json', '["tests/ExampleTest.php"]');
        $tiaDirectory = $root.'/.git/custom-tia';
        mkdir($tiaDirectory, 0o700, true);

        $receipt = orb277_run_gate(
            $fixture,
            'apps/gateway',
            tiaDirectory: $tiaDirectory,
        )['receipt'];
        $commands = collect($receipt['checks'])->pluck('command')->all();

        expect(orb277_check($receipt, 'apps/gateway', 'test:affected'))
            ->toHaveKey('selected_test_files', []);
        expect($commands)
            ->toContain(['vendor/bin/pest', '--list-tests', 'tests/ExampleTest.php'])
            ->toContain(['../../bin/pest-plain', 'vendor/bin/pest', '--no-tia', '--fail-on-empty-test-suite', '--compact', '--colors=never', 'tests/ExampleTest.php']);
    });

    it('does not try to run a changed test file that was deleted', function (): void {
        $fixture = orb277_gate_fixture(mainTestPath: 'apps/gateway/tests/LegacyTest.php');
        unlink($fixture['root'].'/apps/gateway/tests/LegacyTest.php');

        $receipt = orb277_run_gate($fixture, '')['receipt'];
        $commands = collect($receipt['checks'])->pluck('command')->all();

        expect($receipt['passed'])->toBeTrue()
            ->and($commands)->not->toContain(['vendor/bin/pest', '--list-tests', 'tests/LegacyTest.php']);
    });

    it('does not try to run a test under its old name after a rename', function (): void {
        $fixture = orb277_gate_fixture(mainTestPath: 'apps/gateway/tests/LegacyTest.php');
        rename(
            $fixture['root'].'/apps/gateway/tests/LegacyTest.php',
            $fixture['root'].'/apps/gateway/tests/Legacy.php',
        );

        $receipt = orb277_run_gate($fixture, '')['receipt'];
        $commands = collect($receipt['checks'])->pluck('command')->all();

        expect($receipt['passed'])->toBeTrue()
            ->and($commands)->not->toContain(['vendor/bin/pest', '--list-tests', 'tests/LegacyTest.php']);
    });

    it('runs every directory-scanning architecture test for every changed PHP project', function (string $project, array $architecturePaths): void {
        $fixture = orb277_gate_fixture(changedProject: $project);
        $receipt = orb277_run_gate($fixture, '')['receipt'];
        $architectureChecks = collect($receipt['checks'])
            ->filter(static fn (array $check): bool => $check['project'] === $project)
            ->filter(static fn (array $check): bool => $check['command'][0] === 'vendor/bin/pest');

        expect($architectureChecks->pluck('command')->all())
            ->toBe(array_map(static fn (string $path): array => ['vendor/bin/pest', $path], $architecturePaths))
            ->and($architectureChecks->pluck('exit_code')->unique()->all())
            ->toBe([0]);
    })->with([
        ['apps/cli', ['tests/Feature/CommandSurfaceTest.php']],
        ['apps/gateway', [
            'tests/Unit/Architecture',
            'tests/Feature/Infrastructure/Instances/ConfiguredOriginReadTest.php',
            'tests/Feature/Infrastructure/Caddy/CaddyPublicationLockTest.php',
        ]],
        ['apps/e2e', [
            'tests/Unit/E2E/ProofFixtureContractTest.php',
            'tests/Unit/E2E/ProofFixtureShellContractTest.php',
        ]],
        ['packages/php-sdk', [
            'tests/Unit/SuccessRequestIdBoundaryTest.php',
            'tests/Unit/RepositoryGuidanceTest.php',
            'tests/Unit/Requests/Workspaces/WorkspaceRequestsTest.php',
            'tests/Unit/Requests/Deployments/DeploymentRequestsTest.php',
        ]],
    ]);

    it('records no selection warning when the changed project executes tests or the candidate is main', function (): void {
        $fixture = orb277_gate_fixture();
        $executed = orb277_run_gate($fixture, 'apps/cli')['receipt'];

        expect($executed['passed'])->toBeTrue();
        expect(collect($executed['checks'])->pluck('project')->unique()->values()->all())->toBe(orb277_selected_projects());
        expect($executed['warnings'])->toBe([]);
        expect(collect($executed['checks'])->pluck('warning')->filter()->all())->toBe([]);
        expect(orb277_check($executed, 'apps/gateway', 'test:affected'))
            ->toHaveKey('selected_test_files', ['tests/ExampleTest.php']);
        expect(collect($executed['checks'])->pluck('command')->all())
            ->not->toContain(['vendor/bin/pest', '--list-tests', 'tests/ExampleTest.php'])
            ->not->toContain(orb277_full_suite_command());

        (new Process(['git', 'checkout', '--quiet', 'main'], $fixture['root']))->mustRun();
        $main = orb277_run_gate($fixture, 'apps/gateway');

        expect($main['receipt']['candidate'])->toBe($fixture['main']);
        expect($main['receipt']['changed_paths'])->toBe([]);
        expect(collect($main['receipt']['checks'])->pluck('project')->unique()->values()->all())->toBe(orb277_selected_projects());
        expect(collect($main['receipt']['checks'])->count())->toBe(17);
        expect(collect($main['receipt']['checks'])->pluck('command')->all())
            ->not->toContain(orb277_full_suite_command());
        expect($main['receipt']['passed'])->toBeTrue();
        expect($main['receipt']['warnings'])->toBe([]);
        expect($main['process']->getOutput())->not->toContain('WARNING');
    });

    it('keeps only the main baseline in the run copy when a branch baseline exists', function (): void {
        $fixture = orb277_gate_fixture();
        $graph = [
            'schema' => 1,
            'fingerprint' => ['structural' => ['schema' => 1]],
            'files' => ['app/Example.php', 'tests/ExampleTest.php'],
            'edges' => ['tests/ExampleTest.php' => [0]],
            'baselines' => [
                'main' => [
                    'sha' => $fixture['main'],
                    'tree' => [],
                    'results' => [
                        'example' => [
                            'status' => 0,
                            'message' => '',
                            'time' => 1,
                            'assertions' => 1,
                            'file' => 'tests/ExampleTest.php',
                        ],
                    ],
                ],
                'task-x' => [
                    'sha' => $fixture['candidate'],
                    'tree' => ['app/Example.php' => 'branch-hash'],
                    'results' => [
                        'example' => [
                            'status' => 0,
                            'message' => 'branch',
                            'time' => 2,
                            'assertions' => 1,
                            'file' => 'tests/ExampleTest.php',
                        ],
                    ],
                ],
            ],
            'test_tables' => [],
            'test_inertia_components' => [],
            'js_file_to_components' => [],
        ];
        $projectGraph = $fixture['root'].'/apps/gateway/.orbit-tia';
        mkdir($projectGraph, 0o700, true);
        file_put_contents($projectGraph.'/graph.json', json_encode($graph, JSON_THROW_ON_ERROR));
        $record = temporaryFile('orbit-gate-tia-graph-');
        $recordedDirectory = temporaryFile('orbit-gate-tia-directory-');
        orb277_install_recording_pest($fixture['root'], $record, $recordedDirectory);

        $run = orb277_run_gate($fixture, '');
        $seenDirectory = trim((string) file_get_contents($recordedDirectory));
        $recorded = json_decode((string) file_get_contents($record), true, flags: JSON_THROW_ON_ERROR);
        $preserved = json_decode((string) file_get_contents($projectGraph.'/graph.json'), true, flags: JSON_THROW_ON_ERROR);

        expect($run['receipt']['passed'])->toBeTrue();
        expect($seenDirectory)->toEndWith('/tia/apps-gateway')
            ->and($seenDirectory)->toContain('/orbit-checks/')
            ->and($seenDirectory)->not->toBe($projectGraph);
        expect(array_keys($recorded['baselines']))->toBe(['main'])
            ->and($recorded['baselines']['main'])->toBe($graph['baselines']['main'])
            ->and($recorded['files'])->toBe($graph['files'])
            ->and($recorded['edges'])->toBe($graph['edges']);
        expect(array_keys($preserved['baselines']))->toBe(['main', 'task-x']);
    });
});

describe('subtask test selection', function (): void {
    it('keeps docs on the branch base and narrows only intermediate handoff tests', function (bool $ci): void {
        $fixture = orb277_gate_fixture('apps/cli/composer.lock');
        $root = $fixture['root'];
        mkdir($root.'/apps/gateway/app', 0700, true);
        file_put_contents($root.'/apps/gateway/app/Later.php', '<?php final class Later {}');
        (new Process(['git', 'add', '.'], $root))->mustRun();
        (new Process(['git', 'commit', '-m', 'Later subtask'], $root))->mustRun();

        $run = orb277_run_gate($fixture, 'apps/cli', testBase: $fixture['candidate'], ci: $ci);

        expect($run['receipt']['base'])->toBe($fixture['main'])
            ->and($run['receipt']['test_base'])->toBe($ci ? $fixture['main'] : $fixture['candidate']);
        $fallbacks = array_values(array_filter($run['receipt']['checks'], static fn (array $check): bool => $check['project'] === 'apps/cli' && in_array('--no-tia', $check['command'], true)));
        expect($fallbacks)->toHaveCount($ci ? 1 : 0);
    })->with(['handoff' => false, 'CI' => true]);

    it('refuses a nonexistent or malformed handoff test base before running checks', function (string $base): void {
        $fixture = orb277_gate_fixture();
        $process = new Process([$fixture['root'].'/bin/review-check'], $fixture['root'], [
            'PATH' => $fixture['path'], 'ORBIT_TASK_CHECK_BASE' => $base, 'CI' => false, 'GITHUB_ACTIONS' => false,
        ]);
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('task test base');
    })->with([str_repeat('f', 40), '--all']);
});
