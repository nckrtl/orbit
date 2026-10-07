<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/** Pest's process count in CI: 6 on the self-hosted Sabre runner, 4 on GitHub-hosted runners. */
const CI_PEST_PROCESSES = '--processes=${{ runner.environment == \'self-hosted\' && 6 || 4 }}';

describe('Composer configuration', function (): void {
    it('enables TIA for every repository-owned Pest command', function (): void {
        foreach ([
            'bin/test',
            'apps/cli/composer.json',
            'apps/docs/composer.json',
            'apps/gateway/composer.json',
            'apps/e2e/composer.json',
            'apps/e2e/app/E2E/ScenarioPestProcess.php',
            'packages/php-sdk/composer.json',
        ] as $path) {
            $contents = file_get_contents(base_path('../../'.$path));

            expect($contents)
                ->toBeString()
                ->toContain('--tia')
                ->not->toContain(
                    '--no-tia',
                    '--filter',
                    '--exclude-filter',
                    '--group',
                    '--exclude-group',
                    '--testsuite',
                    '--exclude-testsuite',
                );

            if (str_ends_with($path, 'composer.json')) {
                /** @var array{scripts: array<string, mixed>} $composer */
                $composer = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

                foreach (['test', 'test:affected'] as $script) {
                    $command = $composer['scripts'][$script] ?? null;

                    expect($command)
                        ->toBeString()
                        ->toContain('vendor/bin/pest', '--colors=never')
                        ->not->toContain('--no-progress');
                }

                array_walk_recursive($composer['scripts'], function (mixed $command): void {
                    if (! is_string($command) || ! str_contains($command, 'vendor/bin/pest')) {
                        return;
                    }

                    expect(preg_match('/(?:^|\s)tests\//', $command))->toBe(0);
                });
            } else {
                expect($contents)->not->toMatch('/vendor\/bin\/pest[^\n]*tests\//');

                if ($path === 'bin/test') {
                    expect($contents)
                        ->toContain('--colors=never')
                        ->not->toContain('--no-progress');
                }

                expect($contents)->not->toContain('tests/');
            }
        }

        foreach ([
            'apps/cli/phpunit.guidance.xml' => ['tests/Feature/BoostGuidanceTest.php'],
            'apps/docs/phpunit.guidance.xml' => ['tests/Unit/RepositoryGuidanceTest.php'],
            'apps/gateway/phpunit.guidance.xml' => [
                'tests/Feature/Configuration/BoostGuidanceTest.php',
                'tests/Unit/Configuration/BoostConfigurationTest.php',
            ],
            'apps/e2e/phpunit.guidance.xml' => [
                'tests/Feature/Configuration/BoostConfigurationTest.php',
                'tests/Feature/Configuration/BoostGuidanceTest.php',
                'tests/Feature/Configuration/ComposerConfigurationTest.php',
            ],
            'packages/php-sdk/phpunit.guidance.xml' => ['tests/Unit/RepositoryGuidanceTest.php'],
        ] as $configuration => $contracts) {
            expect(file_get_contents(base_path('../../'.$configuration)))
                ->toBeString()
                ->toContain(...$contracts);
        }
    });

    it('strips ANSI from Pest output and keeps the summary and exit code', function (): void {
        $root = base_path('../..');
        $plain = $root.'/bin/pest-plain';

        $passed = new Process([
            $plain,
            'php',
            '-r',
            'echo "\e[1ATests: 1 passed\n";',
        ], $root);
        $passed->mustRun();

        expect($passed->getExitCode())->toBe(0)
            ->and($passed->getOutput())->toBe("Tests: 1 passed\n")
            ->and($passed->getErrorOutput())->toBe('');

        $failed = new Process([
            $plain,
            'php',
            '-r',
            'fwrite(STDERR, "\e[31mfail\e[0m\n"); exit(2);',
        ], $root);
        $failed->run();

        expect($failed->getExitCode())->toBe(2)
            ->and($failed->getOutput())->toBe("fail\n")
            ->and($failed->getErrorOutput())->toBe('');
    });

    it('requires analysis level 6 or higher in every Composer project', function (string $project): void {
        $configuration = file_get_contents(base_path('../../'.$project.'/phpstan.neon'));

        expect(preg_match_all('/^\s*level:\s*(\d+)\s*$/m', $configuration, $matches))->toBe(1);
        expect((int) $matches[1][0])->toBeGreaterThanOrEqual(6);
    })->with(['apps/cli', 'apps/gateway', 'apps/docs', 'apps/e2e', 'packages/php-sdk']);

    it('defines the database-free E2E project', function (): void {
        $composer = json_decode(
            (string) file_get_contents(base_path('composer.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($composer['name'])
            ->toBe('nckrtl/orbit-e2e')
            ->and($composer['require'])
            ->toHaveKeys(['php', 'composer/semver', 'laravel/framework'])
            ->and($composer['autoload']['psr-4'])
            ->not->toHaveKey('Database\\')->and(json_encode($composer['scripts'], JSON_THROW_ON_ERROR))
            ->not->toMatch('/migrate|artisan dev|sqlite|routes|database/i');

        expect($composer['scripts']['analyse'])
            ->toBe('vendor/bin/phpstan analyse --no-progress --memory-limit=1G');
        expect($composer['scripts']['lint'])
            ->toBe('@format:check');
        expect($composer['scripts'])
            ->not
            ->toHaveKey('test:live-incus')
            ->and($composer['scripts']['test'])
            ->toBe('../../bin/pest-plain vendor/bin/pest --parallel --tia --compact --colors=never')
            ->and($composer['scripts']['test:fresh'])
            ->toBe('vendor/bin/pest --parallel --tia --fresh --compact')
            ->and($composer['scripts']['guidance:check'])
            ->toBe('../../bin/guidance-check');
        expect($composer['scripts'])->not->toHaveKey('test:scenario-cold');
        expect($composer['scripts']['scenario:cold'])
            ->toBe([
                'Composer\\Config::disableProcessTimeout',
                '@php artisan scenario:cold',
            ]);
        expect($composer['scripts']['scenario:snapshot'])
            ->toBe([
                'Composer\\Config::disableProcessTimeout',
                '@php artisan scenario:snapshot',
            ]);
        expect($composer['scripts']['scenario:run'])
            ->toBe([
                'Composer\\Config::disableProcessTimeout',
                '@php artisan scenario:run',
            ]);
        expect($composer['scripts']['scenario:cleanup'])->toBe('@php artisan scenario:cleanup');
        expect(file_get_contents(base_path('phpunit.xml')))->not->toContain('<directory>tests/Scenario</directory>');
        expect(file_get_contents(base_path('phpunit.scenario-cold.xml')))
            ->toContain('<file>tests/Scenario/ColdTopologyAcceptanceTest.php</file>');
        expect(file_get_contents(base_path('phpunit.scenario-snapshot.xml')))
            ->toContain('<file>tests/Scenario/SnapshotTopologyAcceptanceTest.php</file>');
        expect(file_get_contents(base_path('../../bin/test')))
            ->toContain('--tia')
            ->not->toContain('incus-live');
        expect(file_get_contents(base_path('../../.github/workflows/ci.yml')))
            ->toContain('coverage: pcov', '--tia')
            ->not->toContain('incus-live');
        expect(file_get_contents(base_path('../../.github/pull_request_template.md')))->not->toContain('bin/e2e-live');

        foreach (['.env.example', 'config/app.php', 'phpunit.xml', 'tests/Pest.php'] as $file) {
            expect(file_get_contents(base_path($file)))
                ->not
                ->toMatch('/DB_|QUEUE_|RefreshDatabase|APP_URL|Gateway|gateway|sqlite|database/i');
        }

        expect(file_get_contents(base_path('config/e2e.php')))->not->toContain('ORBIT_E2E_PROFILE', "'profile'");

        foreach (['app', 'bootstrap', 'commands', 'state', 'tests', 'tooling'] as $rule) {
            expect(trim((string) file_get_contents(base_path(".ai/rules/{$rule}.md"))))->not->toBeEmpty();
        }

        foreach (['pint.json', 'phpstan.neon'] as $file) {
            expect(file_get_contents(base_path($file)))->not->toMatch('/database|routes/i');
        }
    });

    it('installs E2E dependencies without running other projects checks in the E2E job', function (): void {
        $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
        $project = $workflow['jobs']['project'];
        $steps = array_column($project['steps'], null, 'name');

        expect($steps['Install all project dependencies for E2E integration tests'])
            ->toMatchArray([
                'if' => "matrix.directory == 'apps/e2e'",
                'working-directory' => '.',
                'run' => 'bin/bootstrap --skip-checks',
            ]);
        expect($project['defaults']['run']['working-directory'])->toBe('${{ matrix.directory }}');
        expect($steps['Run project quality checks'])
            ->toMatchArray(['run' => 'composer check'])
            ->not->toHaveKey('if');
        expect($steps['Run affected tests'])
            ->toMatchArray([
                'if' => "github.event_name == 'pull_request'",
                'run' => 'vendor/bin/pest --parallel '.CI_PEST_PROCESSES.' --tia --compact',
            ]);
        // The fresh TIA run executes every test, so one run on main is both the full-suite gate and the graph refresh.
        expect($steps['Run full test suite and refresh Pest TIA graph'])
            ->toMatchArray([
                'if' => "github.event_name == 'push' || github.event_name == 'workflow_dispatch'",
                'run' => 'vendor/bin/pest --parallel '.CI_PEST_PROCESSES.' --tia --fresh --compact',
            ]);
        expect($steps)->not->toHaveKey('Run full test suite')->not->toHaveKey('Refresh Pest TIA graph');
        // Only pushes and pull requests from this repository may reach the self-hosted Sabre runner.
        expect($project['runs-on'])
            ->toContain("matrix.directory == 'apps/gateway'")
            ->toContain("vars.ORBIT_SABRE_RUNNER == 'true'")
            ->toContain("github.event_name != 'pull_request' || github.event.pull_request.head.repo.full_name == github.repository")
            ->toContain("fromJSON('[\"self-hosted\", \"sabre\"]')")
            ->toContain("'ubuntu-26.04'");
        expect($steps['Run architecture tests'])
            ->toMatchArray([
                'if' => "always() && github.event_name == 'pull_request'",
            ])
            ->and($steps['Run architecture tests']['run'])
            ->toContain('tests/Feature/CommandSurfaceTest.php')
            // TIA does not link workflow files to the tests that read them, so these contracts always run on pull requests.
            ->toContain('tests/Feature/CliBinaryBuildContractTest.php')
            ->toContain('tests/Feature/Configuration/ComposerConfigurationTest.php')
            ->toContain('tests/Unit/Architecture')
            ->toContain('tests/Feature/Infrastructure/Instances/ConfiguredOriginReadTest.php')
            ->toContain('tests/Feature/Infrastructure/Caddy/CaddyPublicationLockTest.php')
            ->toContain('tests/Unit/E2E/ProofFixtureContractTest.php')
            ->toContain('tests/Unit/E2E/ProofFixtureShellContractTest.php')
            ->toContain('tests/Unit/SuccessRequestIdBoundaryTest.php')
            ->toContain('tests/Unit/RepositoryGuidanceTest.php')
            ->toContain('tests/Unit/Requests/Workspaces/WorkspaceRequestsTest.php')
            ->toContain('tests/Unit/Requests/Deployments/DeploymentRequestsTest.php')
            ->toContain('vendor/bin/pest --parallel '.CI_PEST_PROCESSES.' --compact "$architecture_test"');
        expect($steps['Run architecture tests']['run'])->not->toContain('--tia');
    });

    it('persists per-project Pest TIA graphs on a named checkout', function (): void {
        $workflow = file_get_contents(base_path('../../.github/workflows/ci.yml'));

        expect($workflow)
            ->toBeString()
            ->toContain('fetch-depth: 0')
            ->toContain('ref: ${{ github.head_ref || github.ref_name }}')
            ->toContain('repository: ${{ github.event.pull_request.head.repo.full_name || github.repository }}')
            ->toContain('ORBIT_TIA_DIRECTORY: .orbit-tia')
            ->toContain('actions/cache/restore@v6')
            ->toContain('actions/cache/save@v6')
            ->toContain('path: ${{ matrix.directory }}/.orbit-tia')
            ->toContain("format('{0}/composer.lock', matrix.directory)")
            ->toContain("format('{0}/tests/Pest.php', matrix.directory)")
            ->toContain("format('{0}/phpunit.xml', matrix.directory)")
            ->toContain("format('{0}/phpunit.xml.dist', matrix.directory)")
            ->toContain('orbit-tia-php8.5-${{ matrix.directory }}-')
            ->toContain('${{ github.head_ref || github.ref_name }}-${{ github.sha }}')
            ->toContain('${{ steps.orbit-tia-key.outputs.prefix }}-${{ github.head_ref || github.ref_name }}-')
            ->toContain('${{ steps.orbit-tia-key.outputs.prefix }}-main-')
            ->toContain('if: success()')
            ->toContain('coverage: pcov')
            ->toContain('vendor/bin/pest --parallel '.CI_PEST_PROCESSES.' --tia --compact')
            ->toContain("github.event_name == 'push' || github.event_name == 'workflow_dispatch'")
            ->toContain('vendor/bin/pest --parallel '.CI_PEST_PROCESSES.' --tia --fresh --compact')
            ->toContain('tests/Unit/Architecture')
            ->toContain("github.event_name == 'pull_request'")
            ->toContain("github.event_name == 'workflow_dispatch'")
            ->not->toContain('bin/tia-cache')
            ->not->toContain('tia-baseline.yml')
            ->not->toContain('vendor/.orbit-guidance-tia');

        expect(strpos($workflow, 'Restore Pest TIA graph'))
            ->toBeLessThan(strpos($workflow, 'Run affected tests'));
        expect(strpos($workflow, 'Run affected tests'))
            ->toBeLessThan(strpos($workflow, 'Save Pest TIA graph'));

        $ignored = new Process(
            ['git', 'check-ignore', '-q', 'apps/cli/.orbit-tia/graph.json'],
            base_path('../..'),
        );
        $ignored->run();

        expect($ignored->getExitCode())->toBe(0, 'The hosted TIA graph must stay out of the candidate tree.');
    });

    it('persists per-project Pint and Rector caches around the quality checks', function (): void {
        $workflow = file_get_contents(base_path('../../.github/workflows/ci.yml'));

        expect($workflow)
            ->toBeString()
            ->toContain('${{ matrix.directory }}/vendor/pint.cache')
            ->toContain('${{ matrix.directory }}/vendor/rector/cache')
            ->toContain("format('{0}/pint.json', matrix.directory)")
            ->toContain("format('{0}/rector.php', matrix.directory)")
            ->toContain('orbit-lint-php8.5-${{ matrix.directory }}-')
            ->toContain('${{ steps.orbit-lint-key.outputs.prefix }}-${{ github.head_ref || github.ref_name }}-')
            ->toContain('${{ steps.orbit-lint-key.outputs.prefix }}-main-');

        expect(strpos($workflow, 'Install dependencies'))
            ->toBeLessThan(strpos($workflow, 'Restore Pint and Rector caches'));
        expect(strpos($workflow, 'Restore Pint and Rector caches'))
            ->toBeLessThan(strpos($workflow, 'Run project quality checks'));
        expect(strpos($workflow, 'Run project quality checks'))
            ->toBeLessThan(strpos($workflow, 'Save Pint and Rector caches'));
    });

    it('persists per-project portable PHPStan result caches after install', function (): void {
        $workflow = file_get_contents(base_path('../../.github/workflows/ci.yml'));

        expect($workflow)
            ->toBeString()
            ->toContain('path: ${{ matrix.directory }}/vendor/phpstan/cache/resultCache.php')
            ->toContain("format('{0}/composer.lock', matrix.directory)")
            ->toContain("format('{0}/phpstan.neon', matrix.directory)")
            ->toContain('orbit-phpstan-php8.5-${{ matrix.directory }}-')
            ->toContain('${{ steps.orbit-phpstan-key.outputs.prefix }}-${{ github.head_ref || github.ref_name }}-')
            ->toContain('${{ steps.orbit-phpstan-key.outputs.prefix }}-main-')
            ->not->toMatch('/path:\s*\$\{\{ matrix\.directory \}\}\/vendor\/phpstan\/cache\s*$/m')
            ->not->toMatch('/path:\s*\$\{\{ matrix\.directory \}\}\/vendor\s*$/m');

        expect(strpos($workflow, 'Install dependencies'))
            ->toBeLessThan(strpos($workflow, 'Restore PHPStan result cache'));
        expect(strpos($workflow, 'Restore PHPStan result cache'))
            ->toBeLessThan(strpos($workflow, 'Run project quality checks'));
        expect(strpos($workflow, 'Run project quality checks'))
            ->toBeLessThan(strpos($workflow, 'Save PHPStan result cache'));
        expect(strpos($workflow, 'Save PHPStan result cache'))
            ->toBeLessThan(strpos($workflow, 'Restore Pest TIA graph'));
    });

    it('executes a fresh TIA guidance contract when a guidance input is corrupt', function (): void {
        $source = base_path('../../apps/docs');
        $project = temporaryPath('orbit-guidance-check-', 6);

        mkdir($project.'/app', 0o700, true);
        mkdir($project.'/tests/Unit', 0o700, true);
        symlink($source.'/vendor', $project.'/vendor');

        foreach ([
            'composer.json',
            'phpunit.guidance.xml',
            'tests/Pest.php',
            'tests/Unit/RepositoryGuidanceTest.php',
        ] as $path) {
            $destination = $project.'/'.$path;
            $directory = dirname($destination);

            if (! is_dir($directory)) {
                mkdir($directory, 0o700, true);
            }

            copy($source.'/'.$path, $destination);
        }

        $guidance = (string) file_get_contents($source.'/AGENTS.md');
        expect($guidance)->toContain('repository-root `docs/`');
        file_put_contents(
            $project.'/AGENTS.md',
            str_replace('repository-root `docs/`', 'corrupt guidance contract', $guidance),
        );

        $process = new Process(['composer', 'guidance:check'], $project);
        $process->setTimeout(30);
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();

        expect($process->getExitCode())
            ->not->toBe(0)
            ->and($output)
            ->toContain('repository-root `docs/`')
            ->not->toContain('TIA does not apply to partial runs');
    });
});

it('keeps privileged tests required in CI on trusted and fork branches', function (): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
    $job = $workflow['jobs']['privileged'];
    expect($job['runs-on'])->toContain('self-hosted', 'sabre', 'head.repo.full_name', 'ubuntu-26.04');
    $commands = array_column($job['steps'], 'run');
    expect(implode("\n", $commands))->toContain('--group=privileged', '--no-tia', '--fail-on-empty-test-suite');
    expect($workflow['jobs']['required']['needs'])->toContain('privileged');
    expect($workflow['jobs']['required']['steps'][0]['run'])->toContain('test "$PRIVILEGED_RESULT" = success');
});

it('lets every main push finish CI while a pull request keeps only its newest run', function (): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));

    // A shared main group would still replace a pending main run, so each main commit needs its own group.
    expect($workflow['concurrency'])->toBe([
        'group' => "ci-\${{ github.workflow }}-\${{ github.event_name == 'pull_request' && github.ref || github.sha }}",
        'cancel-in-progress' => "\${{ github.event_name == 'pull_request' }}",
    ]);
});

it('tests exactly the run commit on main even when the branch moved before the job started', function (): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
    $steps = $workflow['jobs']['project']['steps'];
    $names = array_column($steps, 'name');
    $checkout = array_search('Check out repository', $names, true);

    // The checkout names the branch, so a later push would otherwise be tested under this run's commit.
    expect($steps[$checkout]['with']['ref'])->toBe('${{ github.head_ref || github.ref_name }}')
        ->and($steps[$checkout + 1])->toBe([
            'name' => "Pin the run's commit",
            'if' => "github.event_name != 'pull_request'",
            'working-directory' => '.',
            'run' => 'git reset --hard "$GITHUB_SHA"',
        ]);
});

it('publishes the web build of each main push for the Gateway to install', function (): void {
    $workflow = Yaml::parseFile(base_path('../../.github/workflows/ci.yml'));
    $steps = array_column($workflow['jobs']['web']['steps'], null, 'name');
    $names = array_keys($steps);

    expect($steps['Publish web build'])->toBe([
        'name' => 'Publish web build',
        'if' => "github.ref == 'refs/heads/main' && github.event_name != 'pull_request'",
        'uses' => 'actions/upload-artifact@v7',
        'with' => [
            'name' => 'web-dist-${{ github.sha }}',
            'path' => 'apps/web/dist',
            'include-hidden-files' => true,
            'if-no-files-found' => 'error',
            'retention-days' => 14,
        ],
    ]);
    expect(array_search('Publish web build', $names, true))
        ->toBe(array_search('Build', $names, true) + 1);
});

it('excludes privileged feedback through configuration while retaining TIA and ignores feedback in CI', function (bool $ci): void {
    $directory = temporaryPath('orbit-feedback-', 6);
    mkdir($directory);
    file_put_contents($directory.'/composer.json', '{"name":"nckrtl/orbit-gateway"}');
    file_put_contents($directory.'/phpunit.xml', '<phpunit bootstrap="tests/bootstrap.php"><testsuites><testsuite name="Feature"><directory>tests</directory></testsuite></testsuites></phpunit>');
    $probe = <<<'PY'
import json,os,sys,xml.etree.ElementTree as ET
args=sys.argv[1:]
config=ET.parse(args[args.index('--configuration')+1]).getroot() if '--configuration' in args else None
print(json.dumps({'args':args,'excluded':None if config is None else config.find('groups/exclude/group').text,'bootstrap':None if config is None else config.get('bootstrap'),'cache':os.environ.get('ORBIT_TIA_DIRECTORY')}))
PY;
    file_put_contents($directory.'/probe.py', $probe);
    $process = new Process(['python3', base_path('../../bin/task-feedback-tests'), 'python3', $directory.'/probe.py', '--tia'], $directory, [
        'ORBIT_TASK_FEEDBACK' => 'shared', 'CI' => $ci ? 'true' : false, 'GITHUB_ACTIONS' => false, 'ORBIT_TIA_DIRECTORY' => false,
    ]);
    try {
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect($result['args'])->toContain('--tia')->not->toContain('--exclude-group');
        expect($result['excluded'])->toBe($ci ? null : 'privileged');
        expect($result['bootstrap'])->toBe($ci ? null : $directory.'/tests/bootstrap.php');
        expect($result['cache'])->toBe($ci ? null : $directory.'/.orbit-tia/task-feedback');
        if (! $ci) {
            expect(file_exists($result['args'][array_search('--configuration', $result['args'], true) + 1]))->toBeFalse();
        }
    } finally {
        unlink($directory.'/probe.py');
        unlink($directory.'/composer.json');
        unlink($directory.'/phpunit.xml');
        rmdir($directory);
    }
})->with([true, false]);
