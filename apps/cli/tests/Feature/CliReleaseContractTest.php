<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

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

describe('CLI release workflow', function (): void {
    it('publishes only green main commits through the shared builder', function (): void {
        $workflow = (string) file_get_contents(cli_release_repo_root().'/.github/workflows/orbit-cli-release.yml');

        expect($workflow)->toContain("workflow_run:\n    workflows: [CI]\n    types: [completed]\n    branches: [main]")
            ->and($workflow)->toContain("github.event.workflow_run.event == 'push'")
            ->and($workflow)->toContain("github.event.workflow_run.conclusion == 'success'")
            ->and($workflow)->toContain('check_name=Required%20checks')
            ->and($workflow)->toContain('git merge-base --is-ancestor "$REQUESTED_COMMIT" origin/main')
            ->and($workflow)->toContain('fetch-depth: 0')
            ->and($workflow)->toContain('bin/orbit-cli-release-version "$REQUESTED_COMMIT"')
            ->and($workflow)->toContain('bin/orbit-cli-release-version --tag "$REQUESTED_COMMIT"')
            ->and($workflow)->toContain('uses: ./.github/workflows/orbit-cli-binary.yml')
            ->and($workflow)->toContain('bin/orbit-cli-release-assets "$VERSION" artifacts release')
            ->and($workflow)->toContain('--latest=false')
            ->and($workflow)->toContain('Published releases are never replaced.')
            ->and(substr_count($workflow, 'contents: write'))->toBe(1)
            ->and($workflow)->toContain("cancel-in-progress: false");
    });
});
