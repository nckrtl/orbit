<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

afterEach(function (): void {
    foreach (cli_release_temp() as $path) {
        File::deleteDirectory($path);
    }

    cli_release_temp(reset: true);
});

/**
 * @return list<string>
 */
function cli_release_temp(?string $prefix = null, bool $reset = false): array|string
{
    static $paths = [];

    if ($reset) {
        $paths = [];

        return $paths;
    }

    if ($prefix === null) {
        return $paths;
    }

    $path = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(6));
    $paths[] = $path;

    return $path;
}

function cli_release_repo_root(): string
{
    return dirname(base_path(), 2);
}

/**
 * @param  list<string>  $command
 * @return array{int, string, string}
 */
function cli_release_run(array $command, string $cwd): array
{
    $process = new Process($command, $cwd, [
        'GIT_CONFIG_GLOBAL' => '/dev/null',
        'GIT_CONFIG_NOSYSTEM' => '1',
        'GIT_AUTHOR_NAME' => 'Orbit Test',
        'GIT_AUTHOR_EMAIL' => 'test@orbit.invalid',
        'GIT_COMMITTER_NAME' => 'Orbit Test',
        'GIT_COMMITTER_EMAIL' => 'test@orbit.invalid',
    ]);
    $process->run();

    return [(int) $process->getExitCode(), trim($process->getOutput()), trim($process->getErrorOutput())];
}

function cli_release_git(string $repo, string ...$arguments): string
{
    [$exitCode, $stdout, $stderr] = cli_release_run(['git', ...$arguments], $repo);

    expect($exitCode)->toBe(0, $stderr);

    return $stdout;
}

/**
 * A git repository with its own copy of bin/orbit-cli-release-version, so the
 * script counts the fixture's commits instead of the Orbit checkout's.
 */
function cli_release_fixture_repo(): string
{
    $repo = (string) cli_release_temp('orbit-cli-release');
    File::ensureDirectoryExists($repo.'/bin');
    File::copy(cli_release_repo_root().'/bin/orbit-cli-release-version', $repo.'/bin/orbit-cli-release-version');
    chmod($repo.'/bin/orbit-cli-release-version', 0755);

    cli_release_git($repo, 'init', '--quiet', '--initial-branch=main');
    cli_release_git($repo, 'add', 'bin');

    return $repo;
}

function cli_release_commit(string $repo, string $message): string
{
    cli_release_git($repo, 'commit', '--quiet', '--allow-empty', '-m', $message);

    return cli_release_git($repo, 'rev-parse', 'HEAD');
}

/**
 * @return array{int, string, string}
 */
function cli_release_version(string $repo, string ...$arguments): array
{
    return cli_release_run([$repo.'/bin/orbit-cli-release-version', ...$arguments], sys_get_temp_dir());
}

function cli_release_fake_binary(string $path, string $platform): void
{
    $header = match ($platform) {
        'linux-x86_64' => "\x7fELF\x02\x01\x01".str_repeat("\0", 11)."\x3e\x00",
        'linux-aarch64' => "\x7fELF\x02\x01\x01".str_repeat("\0", 11)."\xb7\x00",
        'macos-arm64' => "\xcf\xfa\xed\xfe\x0c\x00\x00\x01".str_repeat("\0", 12),
    };

    File::ensureDirectoryExists(dirname($path));
    File::put($path, $header.str_repeat($platform, 64));
}

function cli_release_artifacts(): string
{
    $root = (string) cli_release_temp('orbit-cli-artifacts');
    cli_release_fake_binary($root.'/artifacts/orbit-linux-x64/linux-x64', 'linux-x86_64');
    cli_release_fake_binary($root.'/artifacts/orbit-linux-arm64/linux-arm', 'linux-aarch64');
    cli_release_fake_binary($root.'/artifacts/orbit-macos-arm64/mac-arm', 'macos-arm64');

    return $root;
}

/**
 * @return array{int, string, string}
 */
function cli_release_assets(string $root, string $version): array
{
    return cli_release_run([
        cli_release_repo_root().'/bin/orbit-cli-release-assets',
        $version,
        $root.'/artifacts',
        $root.'/release',
    ], $root);
}

describe('CLI release version', function (): void {
    it('numbers a commit by the commits it reaches and tags it cli-v0.N.0', function (): void {
        $repo = cli_release_fixture_repo();
        $first = cli_release_commit($repo, 'first');
        cli_release_commit($repo, 'second');
        $third = cli_release_commit($repo, 'third');

        expect(cli_release_version($repo, $first))->toBe([0, '0.1.0', ''])
            ->and(cli_release_version($repo, $third))->toBe([0, '0.3.0', ''])
            ->and(cli_release_version($repo, '--tag', $third))->toBe([0, 'cli-v0.3.0', ''])
            ->and(cli_release_version($repo, 'HEAD~1'))->toBe([0, '0.2.0', '']);
    });

    it('orders a merge commit after every commit it brings onto main', function (): void {
        $repo = cli_release_fixture_repo();
        $base = cli_release_commit($repo, 'base');
        cli_release_git($repo, 'checkout', '--quiet', '-b', 'feature');
        cli_release_commit($repo, 'feature one');
        $feature = cli_release_commit($repo, 'feature two');
        cli_release_git($repo, 'checkout', '--quiet', 'main');
        $mainOnly = cli_release_commit($repo, 'main only');
        cli_release_git($repo, 'merge', '--quiet', '--no-ff', '-m', 'Merge feature', 'feature');
        $merge = cli_release_git($repo, 'rev-parse', 'HEAD');

        $versions = array_map(
            static fn (string $commit): int => (int) explode('.', cli_release_version($repo, $commit)[1])[1],
            [$base, $mainOnly, $feature, $merge],
        );

        expect($versions)->toBe([1, 2, 3, 5]);
    });

    it('releases only commits on the first-parent history of main', function (): void {
        $repo = cli_release_fixture_repo();
        $base = cli_release_commit($repo, 'base');
        cli_release_git($repo, 'checkout', '--quiet', '-b', 'feature');
        $feature = cli_release_commit($repo, 'feature');
        cli_release_git($repo, 'checkout', '--quiet', 'main');
        $mainOnly = cli_release_commit($repo, 'main only');
        cli_release_git($repo, 'merge', '--quiet', '--no-ff', '-m', 'Merge feature', 'feature');
        $merge = cli_release_git($repo, 'rev-parse', 'HEAD');

        expect(cli_release_version($repo, '--on-main', 'main', $base)[0])->toBe(0)
            ->and(cli_release_version($repo, '--on-main', 'main', $mainOnly)[0])->toBe(0)
            ->and(cli_release_version($repo, '--tag', '--on-main', 'main', $merge))->toBe([0, 'cli-v0.4.0', ''])
            ->and(cli_release_version($repo, '--on-main', 'main', $feature)[0])->toBe(1)
            ->and(cli_release_version($repo, '--on-main', 'main', $feature)[2])->toContain('first-parent history');
    });

    it('refuses a shallow clone because its count is wrong', function (): void {
        $repo = cli_release_fixture_repo();
        cli_release_commit($repo, 'first');
        cli_release_commit($repo, 'second');
        $shallow = (string) cli_release_temp('orbit-cli-release-shallow');
        cli_release_git(sys_get_temp_dir(), 'clone', '--quiet', '--depth=1', 'file://'.$repo, $shallow);

        [$exitCode, $stdout, $stderr] = cli_release_version($shallow, 'HEAD');

        expect($exitCode)->toBe(1)
            ->and($stdout)->toBe('')
            ->and($stderr)->toContain('shallow clone');
    });

    it('refuses an unknown commit and prints usage without one', function (): void {
        $repo = cli_release_fixture_repo();
        cli_release_commit($repo, 'first');

        [$unknownExit, , $unknownError] = cli_release_version($repo, str_repeat('0', 40));
        [$usageExit, , $usageError] = cli_release_version($repo);

        expect($unknownExit)->toBe(1)
            ->and($unknownError)->toContain('unknown commit')
            ->and($usageExit)->toBe(2)
            ->and($usageError)->toContain('Usage:');
    });
});

describe('CLI release assets', function (): void {
    it('names each binary for its platform and writes sorted SHA256SUMS', function (): void {
        $root = cli_release_artifacts();

        [$exitCode, , $stderr] = cli_release_assets($root, '0.4681.0');

        $names = [
            'orbit-0.4681.0-linux-aarch64',
            'orbit-0.4681.0-linux-x86_64',
            'orbit-0.4681.0-macos-arm64',
        ];
        $expectedSums = implode('', array_map(
            static fn (string $name): string => hash_file('sha256', $root.'/release/'.$name)."  {$name}\n",
            $names,
        ));
        $files = array_map(basename(...), File::files($root.'/release'));
        sort($files);

        expect($exitCode)->toBe(0, $stderr)
            ->and($files)->toBe(['SHA256SUMS', ...$names])
            ->and(File::get($root.'/release/SHA256SUMS'))->toBe($expectedSums)
            ->and(File::get($root.'/release/orbit-0.4681.0-macos-arm64'))->toBe(File::get($root.'/artifacts/orbit-macos-arm64/mac-arm'))
            ->and(fileperms($root.'/release/orbit-0.4681.0-linux-x86_64') & 0777)->toBe(0755);
    });

    it('refuses a binary built for another platform', function (): void {
        $root = cli_release_artifacts();
        cli_release_fake_binary($root.'/artifacts/orbit-linux-arm64/linux-arm', 'linux-x86_64');

        [$exitCode, , $stderr] = cli_release_assets($root, '0.4681.0');

        expect($exitCode)->toBe(1)
            ->and($stderr)->toContain('is not a linux-aarch64 executable')
            ->and($root.'/release')->not->toBeDirectory();
    });

    it('refuses a missing binary', function (): void {
        $root = cli_release_artifacts();
        File::delete($root.'/artifacts/orbit-macos-arm64/mac-arm');

        [$exitCode, , $stderr] = cli_release_assets($root, '0.4681.0');

        expect($exitCode)->toBe(1)
            ->and($stderr)->toContain('missing binary')
            ->and($root.'/release')->not->toBeDirectory();
    });

    it('refuses a version that is not a release version', function (string $version): void {
        $root = cli_release_artifacts();

        [$exitCode, , $stderr] = cli_release_assets($root, $version);

        expect($exitCode)->toBe(1)
            ->and($stderr)->toContain('not a release version');
    })->with(['agent-v0.3.0-12-g1a2b3c4', '0.0.0', '1.2.3', 'cli-v0.4681.0', '0.4681.0+dirty']);

    it('refuses to write into an existing output directory', function (): void {
        $root = cli_release_artifacts();
        File::ensureDirectoryExists($root.'/release');

        [$exitCode, , $stderr] = cli_release_assets($root, '0.4681.0');

        expect($exitCode)->toBe(1)
            ->and($stderr)->toContain('output directory already exists');
    });
});

/**
 * @return array<string, mixed>
 */
function cli_release_workflow(): array
{
    $workflow = Yaml::parseFile(cli_release_repo_root().'/.github/workflows/orbit-cli-release.yml');
    expect($workflow)->toBeArray();

    /** @var array<string, mixed> $workflow */
    return $workflow;
}

describe('CLI release workflow', function (): void {
    it('publishes only green main commits through the shared builder', function (): void {
        $workflow = (string) file_get_contents(cli_release_repo_root().'/.github/workflows/orbit-cli-release.yml');

        expect($workflow)->toContain("workflow_run:\n    workflows: [CI]\n    types: [completed]\n    branches: [main]")
            ->and($workflow)->toContain("github.event.workflow_run.conclusion == 'success'")
            ->and($workflow)->toContain('check_name=Required%20checks')
            ->and($workflow)->toContain('fetch-depth: 0')
            ->and($workflow)->toContain('bin/orbit-cli-release-version --on-main origin/main "$REQUESTED_COMMIT"')
            ->and($workflow)->toContain('bin/orbit-cli-release-version --tag --on-main origin/main "$REQUESTED_COMMIT"')
            ->and($workflow)->toContain('uses: ./.github/workflows/orbit-cli-binary.yml')
            ->and($workflow)->toContain('bin/orbit-cli-release-assets "$VERSION" artifacts release')
            ->and($workflow)->toContain('--latest=false')
            ->and($workflow)->toContain('Published releases are never replaced.')
            ->and(substr_count($workflow, 'contents: write'))->toBe(1)
            ->and($workflow)->toContain('cancel-in-progress: false');
    });

    it('releases after a green CI run on main from a push or a dispatch, never from a pull request', function (): void {
        $workflow = cli_release_workflow();

        // A CI dispatch on main can be the run that makes a commit green, and the Gateway releases any green commit.
        expect(preg_replace('/\s+/', ' ', $workflow['jobs']['resolve']['if']))->toBe(
            "(github.event_name == 'workflow_dispatch' && github.ref == 'refs/heads/main') || ( "
            ."github.event_name == 'workflow_run' && "
            ."contains(fromJSON('[\"push\", \"workflow_dispatch\"]'), github.event.workflow_run.event) && "
            ."github.event.workflow_run.conclusion == 'success' && "
            .'github.event.workflow_run.head_repository.full_name == github.repository )',
        );
    });

    it('names the refusal when GitHub refuses the tag of a commit whose workflows differ from main', function (int $ghExit, string $ghError, bool $workflowsDiffer, bool $named): void {
        $origin = (string) cli_release_temp('orbit-cli-release-origin');
        File::ensureDirectoryExists($origin.'/.github/workflows');
        File::put($origin.'/.github/workflows/ci.yml', "name: CI\n");
        cli_release_git($origin, 'init', '--quiet', '--initial-branch=main');
        // GitHub serves any commit by SHA; a local origin needs this to do the same.
        cli_release_git($origin, 'config', 'uploadpack.allowAnySHA1InWant', 'true');
        cli_release_git($origin, 'add', '.');
        $release = cli_release_commit($origin, 'release commit');

        if ($workflowsDiffer) {
            File::put($origin.'/.github/workflows/ci.yml', "name: CI\non: push\n");
            cli_release_git($origin, 'add', '.');
        }

        cli_release_commit($origin, 'main moved on');
        $checkout = (string) cli_release_temp('orbit-cli-release-checkout');
        cli_release_git(sys_get_temp_dir(), 'clone', '--quiet', '--depth=1', 'file://'.$origin, $checkout);
        $bin = (string) cli_release_temp('orbit-cli-release-bin');
        File::ensureDirectoryExists($bin);
        File::put($bin.'/gh', "#!/bin/sh\necho '{$ghError}' >&2\nexit {$ghExit}\n");
        chmod($bin.'/gh', 0755);

        $steps = array_column(cli_release_workflow()['jobs']['publish']['steps'], null, 'name');
        $process = new Process(['bash', '-e', '-c', $steps['Publish GitHub release']['run']], $checkout, [
            'PATH' => $bin.':'.getenv('PATH'),
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'REPOSITORY' => 'nckrtl/orbit',
            'COMMIT' => $release,
            'VERSION' => '0.2.0',
            'TAG' => 'cli-v0.2.0',
        ]);
        $process->run();

        expect($process->getExitCode())->toBe($ghExit === 0 ? 0 : 1)
            ->and($process->getErrorOutput())->toContain($ghError);

        if ($named) {
            expect($process->getOutput())->toContain('::error title=Release refused for a non-tip commit::', $release, 'cli-binaries#commits-without-a-release');
        } else {
            expect($process->getOutput())->not->toContain('::error');
        }
    })->with([
        'workflows differ from main' => [1, 'HTTP 403: Resource not accessible by integration (https://api.github.com/repos/nckrtl/orbit/releases)', true, true],
        'same workflows as main' => [1, 'HTTP 403: Resource not accessible by integration (https://api.github.com/repos/nckrtl/orbit/releases)', false, false],
        'another failure' => [1, 'HTTP 500 (https://api.github.com/repos/nckrtl/orbit/releases)', true, false],
        'secondary rate limit' => [1, 'HTTP 403: You have exceeded a secondary rate limit (https://api.github.com/repos/nckrtl/orbit/releases)', true, false],
        'published' => [0, 'Uploading assets', true, false],
    ]);

    it('still explains a refusal when it cannot fetch main to compare the workflows', function (): void {
        $checkout = (string) cli_release_temp('orbit-cli-release-checkout');
        File::ensureDirectoryExists($checkout);
        cli_release_git($checkout, 'init', '--quiet', '--initial-branch=main');
        cli_release_git($checkout, 'remote', 'add', 'origin', 'file://'.$checkout.'-missing');
        $bin = (string) cli_release_temp('orbit-cli-release-bin');
        File::ensureDirectoryExists($bin);
        File::put($bin.'/gh', "#!/bin/sh\necho 'HTTP 403: Resource not accessible by integration' >&2\nexit 1\n");
        chmod($bin.'/gh', 0755);

        $steps = array_column(cli_release_workflow()['jobs']['publish']['steps'], null, 'name');
        $process = new Process(['bash', '-e', '-c', $steps['Publish GitHub release']['run']], $checkout, [
            'PATH' => $bin.':'.getenv('PATH'),
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'REPOSITORY' => 'nckrtl/orbit',
            'COMMIT' => str_repeat('a', 40),
            'VERSION' => '0.2.0',
            'TAG' => 'cli-v0.2.0',
        ]);
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getOutput())->toContain('::error title=Release refused::', 'could not be compared');
    });

    it('never runs release-commit code with the write token', function (): void {
        $jobs = cli_release_workflow()['jobs'];

        expect($jobs['publish']['permissions'])->toBe(['contents' => 'write'])
            ->and($jobs['publish']['steps'][0])->toBe([
                'name' => 'Check out the release scripts',
                'uses' => 'actions/checkout@v7',
                'with' => ['persist-credentials' => false],
            ])
            ->and($jobs['build'])->not->toHaveKey('permissions')
            ->and(cli_release_workflow()['permissions'])->toBe(['contents' => 'read'])
            ->and($jobs['resolve']['permissions'])->toBe(['contents' => 'read', 'checks' => 'read']);
    });
});

/**
 * @return array<string, mixed>
 */
function cli_binary_workflow(): array
{
    $workflow = Yaml::parseFile(cli_release_repo_root().'/.github/workflows/orbit-cli-binary.yml');
    expect($workflow)->toBeArray();

    /** @var array<string, mixed> $workflow */
    return $workflow;
}

/**
 * @param  array<string, mixed>  $job
 * @return list<string>
 */
function cli_binary_step_uses(array $job): array
{
    return array_values(array_filter(array_map(
        static fn (array $step): ?string => $step['uses'] ?? null,
        $job['steps'],
    )));
}

describe('CLI binary workflow', function (): void {
    it('builds every target on Linux, including macOS', function (): void {
        $build = cli_binary_workflow()['jobs']['build'];
        $runners = array_column($build['strategy']['matrix']['include'], 'runner', 'target');

        // PHPacker appends the PHAR to a prebuilt PHP, so a macOS target needs no Mac to build.
        expect($runners)->toBe([
            'linux-x64' => 'ubuntu-26.04',
            'linux-arm64' => 'ubuntu-26.04-arm',
            'macos-arm64' => 'ubuntu-26.04',
        ])
            ->and($build['outputs'])->toBe(['version' => '${{ steps.version.outputs.value }}']);
    });

    it('runs each Linux binary where it was built', function (): void {
        $build = cli_binary_workflow()['jobs']['build'];
        $native = array_column($build['strategy']['matrix']['include'], 'native', 'target');
        $run = collect($build['steps'])->firstWhere('name', 'Run the binary on its own platform');

        expect($native)->toBe(['linux-x64' => true, 'linux-arm64' => true, 'macos-arm64' => false])
            ->and($run['if'])->toBe('matrix.native')
            ->and($run['run'])->toContain('env -i HOME="$home" PATH=/usr/bin:/bin "$BINARY" --version')
            ->and($run['run'])->toContain('test "$reported" = "Orbit ${VERSION}"');
    });

    it('runs the macOS binary on a hosted Mac without PHP', function (): void {
        $runMacos = cli_binary_workflow()['jobs']['run-macos'];
        $run = collect($runMacos['steps'])->firstWhere('name', 'Run the binary on its own platform');

        expect($runMacos['runs-on'])->toBe('macos-26')
            ->and($runMacos['needs'])->toBe('build')
            ->and(cli_binary_step_uses($runMacos))->toBe(['actions/download-artifact@v8'])
            ->and($runMacos['steps'][0]['with'])->toBe(['name' => 'orbit-macos-arm64', 'path' => 'binary'])
            ->and($run['env'])->toBe(['VERSION' => '${{ needs.build.outputs.version }}'])
            ->and($run['run'])->toContain('env -i HOME="$home" PATH=/usr/bin:/bin binary/mac-arm --version')
            ->and($run['run'])->toContain('test "$reported" = "Orbit ${VERSION}"')
            ->and($run['run'])->not->toContain('php')
            ->and($run['run'])->not->toContain('composer');
    });

    it('sets up PHP only on Linux runners', function (): void {
        $jobs = cli_binary_workflow()['jobs'];

        expect(cli_binary_step_uses($jobs['build']))->toContain('shivammathur/setup-php@v2');

        foreach ($jobs as $name => $job) {
            $runners = isset($job['strategy']) ? array_column($job['strategy']['matrix']['include'], 'runner') : [$job['runs-on']];
            $usesPhp = in_array('shivammathur/setup-php@v2', cli_binary_step_uses($job), true);

            foreach ($runners as $runner) {
                expect($usesPhp && ! str_starts_with((string) $runner, 'ubuntu-'))->toBeFalse("{$name} sets up PHP on {$runner}");
            }
        }
    });

    it('publishes only after every build job, including the macOS run, succeeds', function (): void {
        $jobs = cli_release_workflow()['jobs'];

        expect($jobs['publish']['needs'])->toBe(['resolve', 'build'])
            ->and($jobs['build']['uses'])->toBe('./.github/workflows/orbit-cli-binary.yml')
            ->and(cli_binary_workflow()['permissions'])->toBe(['contents' => 'read'])
            ->and(cli_binary_workflow()['jobs']['run-macos'])->not->toHaveKey('permissions');
    });
});
