<?php

declare(strict_types=1);

use Pest\Plugins\Tia\ChangedFiles;
use Pest\Plugins\Tia\Storage;
use Symfony\Component\Process\Process;

/**
 * @return array{
 *     root:string,
 *     project:string,
 *     pest:string,
 *     script:string,
 *     patch:string,
 *     files:array<string, array{upstream:?string,patched:?string}>
 * }
 */
function pestSetupFixture(bool $upstream = true): array
{
    $repository = dirname(__DIR__, 5);
    $root = temporaryPath('orbit pest setup ', 6);
    $project = $root.'/linked worktree/apps/sample';
    $pest = $project.'/vendor/pestphp/pest';
    mkdir($project.'/vendor/composer', 0700, true);
    file_put_contents($project.'/composer.json', '{}');
    file_put_contents($project.'/composer.lock', 'unchanged lock');
    file_put_contents($project.'/vendor/composer/installed.json', json_encode([
        'packages' => [['name' => 'pestphp/pest', 'version' => 'v5.1.3']],
    ], JSON_THROW_ON_ERROR));
    $manifest = json_decode(
        file_get_contents($repository.'/bin/pest-support/manifest.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    foreach ($manifest['files'] as $file => $hashes) {
        if ($hashes['patched'] === null) {
            continue;
        }
        if (! is_dir(dirname($pest.'/'.$file))) {
            mkdir(dirname($pest.'/'.$file), 0700, true);
        }
        copy($repository.'/apps/e2e/vendor/pestphp/pest/'.$file, $pest.'/'.$file);
    }
    $patch = $repository.'/bin/pest-support/monorepo.patch';
    if ($upstream) {
        new Process([
            'git',
            'apply',
            '--unsafe-paths',
            '--directory='.$pest,
            '--reverse',
            $patch,
        ], DIRECTORY_SEPARATOR)->mustRun();
    }

    $fixture = [
        'root' => $root,
        'project' => $project,
        'pest' => $pest,
        'script' => $repository.'/bin/pest-setup',
        'patch' => $patch,
        'files' => $manifest['files'],
    ];
    expectPestSetupFiles($fixture, $upstream ? 'upstream' : 'patched');

    return $fixture;
}

/**
 * @param  array{pest:string,files:array<string, array{upstream:?string,patched:?string}>}  $fixture
 */
function expectPestSetupFiles(array $fixture, string $state): void
{
    foreach ($fixture['files'] as $file => $hashes) {
        $path = $fixture['pest'].'/'.$file;
        $actual = is_file($path) ? hash_file('sha256', $path) : null;
        expect($actual)->toBe($hashes[$state], $file.' does not match the '.$state.' manifest hash.');
    }
}

/**
 * @param  array{project:string,script:string}  $fixture
 * @param  array<string, string|false>  $environment
 */
function runPestSetup(array $fixture, array $environment = []): Process
{
    $process = new Process(
        [PHP_BINARY, $fixture['script']],
        $fixture['project'],
        ['COMPOSER_VENDOR_DIR' => false, ...$environment],
    );
    $process->run();

    return $process;
}

function initializePestSetupRepository(string $root): void
{
    mkdir($root, 0700, true);
    foreach ([
        ['init', '-b', 'main'],
        ['config', 'user.name', 'Orbit'],
        ['config', 'user.email', 'orbit@example.test'],
    ] as $arguments) {
        new Process(['git', ...$arguments], $root)->mustRun();
    }
    file_put_contents($root.'/tracked sentinel.txt', 'tracked');
    new Process(['git', 'add', '.'], $root)->mustRun();
    new Process(['git', 'commit', '-m', 'initial'], $root)->mustRun();
}

/** @return array{PATH:string,ORBIT_PEST_GIT_LOG:string,log:string} */
function pestSetupGitShim(string $result): array
{
    $root = temporaryPath('orbit pest git shim ', 6);
    mkdir($root, 0700, true);
    $log = $root.'/arguments.log';
    $exit = $result === 'failure' ? 19 : 0;
    file_put_contents($root.'/git', "#!/bin/sh\nprintf 'cwd=%s\\ntmpdir=%s\\n' \"\$PWD\" \"\$TMPDIR\" >> \"\$ORBIT_PEST_GIT_LOG\"\nprintf '%s\\n' \"\$@\" >> \"\$ORBIT_PEST_GIT_LOG\"\nprintf '%s\\n' 'injected {$result}' >&2\nexit {$exit}\n");
    chmod($root.'/git', 0700);

    return [
        'PATH' => $root.PATH_SEPARATOR.(getenv('PATH') ?: ''),
        'ORBIT_PEST_GIT_LOG' => $log,
        'log' => $log,
    ];
}

/** @return array<string, string> */
function pestSetupTreeSnapshot(string $root): array
{
    $snapshot = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $path = $file->getPathname();
            $snapshot[substr($path, strlen($root) + 1)] = hash_file('sha256', $path);
        }
    }
    ksort($snapshot);

    return $snapshot;
}

it('applies exact patched files from every temporary-directory Git context', function (string $context): void {
    $fixture = pestSetupFixture();
    $container = temporaryPath('orbit pest temp context ', 6);
    $primary = null;

    if ($context === 'outside Git') {
        mkdir($container, 0700, true);
        $temporaryDirectory = $container;
    } elseif ($context === 'repository root') {
        initializePestSetupRepository($container);
        $temporaryDirectory = $container;
    } elseif ($context === 'checkout subdirectory') {
        initializePestSetupRepository($container);
        $temporaryDirectory = $container.'/nested temp directory';
        mkdir($temporaryDirectory, 0700, true);
    } else {
        $primary = $container;
        initializePestSetupRepository($primary);
        $linked = $container.' linked worktree';
        new Process(['git', 'worktree', 'add', '-b', 'feature', $linked], $primary)->mustRun();
        $temporaryDirectory = $linked.'/nested temp directory';
        mkdir($temporaryDirectory, 0700, true);
    }

    try {
        $result = runPestSetup($fixture, ['TMPDIR' => $temporaryDirectory]);

        expect($result->isSuccessful())->toBeTrue($result->getErrorOutput());
        expect($result->getWorkingDirectory())->toBe($fixture['project']);
        expect($result->getEnv()['TMPDIR'])->toBe($temporaryDirectory);
        expectPestSetupFiles($fixture, 'patched');
    } finally {
        if ($primary !== null) {
            new Process(['git', 'worktree', 'remove', '--force', dirname($temporaryDirectory)], $primary)->mustRun();
        }
    }
})->with([
    'outside Git' => 'outside Git',
    'repository root' => 'repository root',
    'checkout subdirectory' => 'checkout subdirectory',
    'linked-worktree subdirectory' => 'linked-worktree subdirectory',
]);

it('changes only the selected Pest package and setup lock', function (): void {
    $fixture = pestSetupFixture();
    $neighbor = $fixture['root'].'/apps/neighbor';
    mkdir($neighbor.'/vendor/pestphp/pest', 0700, true);
    file_put_contents($neighbor.'/composer.lock', 'neighbor lock');
    file_put_contents($neighbor.'/vendor/pestphp/pest/neighbor.php', 'neighbor package');
    file_put_contents($fixture['project'].'/tracked sentinel.txt', 'tracked project sentinel');
    file_put_contents($fixture['project'].'/untracked sentinel.txt', 'untracked project sentinel');
    $before = pestSetupTreeSnapshot($fixture['root']);

    $repository = temporaryPath('orbit pest enclosing repository ', 6);
    initializePestSetupRepository($repository);
    file_put_contents($repository.'/staged sentinel.txt', 'staged');
    new Process(['git', 'add', 'staged sentinel.txt'], $repository)->mustRun();
    file_put_contents($repository.'/untracked sentinel.txt', 'untracked');
    $head = trim(new Process(['git', 'rev-parse', 'HEAD'], $repository)->mustRun()->getOutput());
    $index = trim(new Process(['git', 'write-tree'], $repository)->mustRun()->getOutput());
    $status = new Process(['git', 'status', '--porcelain=v1', '--untracked-files=all'], $repository)->mustRun()->getOutput();
    $temporaryDirectory = $repository.'/temporary directory';
    mkdir($temporaryDirectory, 0700, true);

    $result = runPestSetup($fixture, ['TMPDIR' => $temporaryDirectory]);

    expect($result->isSuccessful())->toBeTrue($result->getErrorOutput());
    expectPestSetupFiles($fixture, 'patched');
    $after = pestSetupTreeSnapshot($fixture['root']);
    $changed = array_keys(array_diff_assoc($after, $before) + array_diff_assoc($before, $after));
    $expected = array_map(
        fn (string $file): string => 'linked worktree/apps/sample/vendor/pestphp/pest/'.$file,
        array_keys($fixture['files']),
    );
    $expected[] = 'linked worktree/apps/sample/vendor/.orbit-pest-setup.lock';
    sort($changed);
    sort($expected);
    expect($changed)->toBe($expected);
    expect(trim(new Process(['git', 'rev-parse', 'HEAD'], $repository)->mustRun()->getOutput()))->toBe($head);
    expect(trim(new Process(['git', 'write-tree'], $repository)->mustRun()->getOutput()))->toBe($index);
    expect(new Process(['git', 'status', '--porcelain=v1', '--untracked-files=all'], $repository)->mustRun()->getOutput())->toBe($status);
});

it('recovers the retained upstream package after patch execution fails and remains idempotent', function (): void {
    $fixture = pestSetupFixture();
    $inode = fileinode($fixture['pest']);
    file_put_contents($fixture['pest'].'/package sentinel.txt', 'same package');
    $shim = pestSetupGitShim('failure');

    $failed = runPestSetup($fixture, [
        'PATH' => $shim['PATH'],
        'ORBIT_PEST_GIT_LOG' => $shim['log'],
    ]);
    expect($failed->getExitCode())->toBe(1);
    expectPestSetupFiles($fixture, 'upstream');

    $recovered = runPestSetup($fixture);
    $patched = pestSetupTreeSnapshot($fixture['pest']);
    $repeated = runPestSetup($fixture);

    expect($recovered->isSuccessful())->toBeTrue($recovered->getErrorOutput());
    expect($repeated->isSuccessful())->toBeTrue($repeated->getErrorOutput());
    expect(fileinode($fixture['pest']))->toBe($inode);
    expect(file_get_contents($fixture['pest'].'/package sentinel.txt'))->toBe('same package');
    expect(pestSetupTreeSnapshot($fixture['pest']))->toBe($patched);
    expectPestSetupFiles($fixture, 'patched');
});

it('reports patch execution failures without classifying upstream files as modified', function (string $result): void {
    $fixture = pestSetupFixture();
    $shim = pestSetupGitShim($result);
    $temporaryDirectory = temporaryPath('orbit pest injected process ', 6);
    mkdir($temporaryDirectory, 0700, true);

    $process = runPestSetup($fixture, [
        'PATH' => $shim['PATH'],
        'ORBIT_PEST_GIT_LOG' => $shim['log'],
        'TMPDIR' => $temporaryDirectory,
    ]);

    expect($process->getExitCode())->toBe(1);
    expect($process->getErrorOutput())->toContain('pinned monorepo patch');
    expect($process->getErrorOutput())->toContain('retry setup without reinstalling Pest');
    expect($process->getErrorOutput())->not->toContain('files differ from the pinned');
    expectPestSetupFiles($fixture, 'upstream');
    $arguments = file($shim['log'], FILE_IGNORE_NEW_LINES);
    $checkArguments = [
        'cwd='.DIRECTORY_SEPARATOR,
        'tmpdir='.$temporaryDirectory,
        'apply',
        '--unsafe-paths',
        '--directory='.$fixture['pest'],
        '--check',
        $fixture['patch'],
    ];
    $applyArguments = [
        'cwd='.DIRECTORY_SEPARATOR,
        'tmpdir='.$temporaryDirectory,
        'apply',
        '--unsafe-paths',
        '--directory='.$fixture['pest'],
        $fixture['patch'],
    ];
    expect($arguments)->toBe($result === 'failure' ? $checkArguments : [...$checkArguments, ...$applyArguments]);
})->with([
    'nonzero Git process' => 'failure',
    'exit-zero Git process that applies nothing' => 'no-op',
]);

it('refuses changed upstream files before changing any package file', function (): void {
    $fixture = pestSetupFixture();
    $entrypoint = file_get_contents($fixture['pest'].'/bin/pest');
    file_put_contents($fixture['pest'].'/src/Plugins/Tia.php', "\nlocal edit", FILE_APPEND);

    $result = runPestSetup($fixture);

    expect($result->getExitCode())->toBe(1);
    expect($result->getErrorOutput())->toContain('differ from the pinned');
    expect(file_get_contents($fixture['pest'].'/bin/pest'))->toBe($entrypoint);
    expect(file_exists($fixture['pest'].'/src/Plugins/Tia/GitRepository.php'))->toBeFalse();
});

it('refuses a Pest upgrade until its patch is reviewed', function (): void {
    $fixture = pestSetupFixture();
    file_put_contents($fixture['project'].'/vendor/composer/installed.json', json_encode([
        'packages' => [['name' => 'pestphp/pest', 'version' => 'v5.2.0']],
    ], JSON_THROW_ON_ERROR));

    $result = runPestSetup($fixture);

    expect($result->getExitCode())->toBe(1);
    expect($result->getErrorOutput())->toContain('unsupported Pest version');
    expect(file_exists($fixture['pest'].'/src/Plugins/Tia/GitRepository.php'))->toBeFalse();
});

it('refuses a changed pinned patch checksum from a disposable setup bundle', function (): void {
    $fixture = pestSetupFixture();
    $bundle = temporaryPath('orbit pest disposable bundle ', 6);
    mkdir($bundle.'/pest-support', 0700, true);
    copy($fixture['script'], $bundle.'/pest-setup');
    copy(dirname($fixture['patch']).'/manifest.json', $bundle.'/pest-support/manifest.json');
    copy($fixture['patch'], $bundle.'/pest-support/monorepo.patch');
    file_put_contents($bundle.'/pest-support/monorepo.patch', "\nchanged checksum", FILE_APPEND);
    $fixture['script'] = $bundle.'/pest-setup';

    $result = runPestSetup($fixture);

    expect($result->getExitCode())->toBe(1);
    expect($result->getErrorOutput())->toContain('pinned patch checksum does not match');
    expectPestSetupFiles($fixture, 'upstream');
});

it('skips production installations without Pest', function (): void {
    $fixture = pestSetupFixture();
    file_put_contents($fixture['project'].'/vendor/composer/installed.json', '{"packages":[]}');

    $result = runPestSetup($fixture);

    expect($result->isSuccessful())->toBeTrue();
    expect($result->getOutput())->toBe('');
    expect(file_exists($fixture['pest'].'/src/Plugins/Tia/GitRepository.php'))->toBeFalse();
});

it('keeps an already patched local fork and refuses to mutate a linked upstream checkout', function (bool $patched): void {
    $fixture = pestSetupFixture(! $patched);
    $external = $fixture['project'].'/external-pest';
    rename($fixture['pest'], $external);
    symlink($external, $fixture['pest']);

    $result = runPestSetup($fixture);

    expect($result->getExitCode())->toBe($patched ? 0 : 1);
    expect(file_exists($external.'/src/Plugins/Tia/GitRepository.php'))->toBe($patched);
    if (! $patched) {
        expect($result->getErrorOutput())->toContain('refusing to modify a linked');
    }
})->with([true, false]);

it('keeps project and linked-worktree baselines separate and resolves changed paths', function (): void {
    $root = temporaryPath('orbit-tia-repository-', 6);
    mkdir($root, 0700, true);
    foreach ([
        ['init', '-b', 'main'],
        ['config', 'user.name', 'Orbit'],
        ['config', 'user.email', 'orbit@example.test'],
        ['remote', 'add', 'origin', 'https://example.test/orbit.git'],
    ] as $args) {
        new Process(['git', ...$args], $root)->mustRun();
    }
    foreach (['apps/first', 'packages/second'] as $project) {
        mkdir($root.'/'.$project, 0700, true);
        file_put_contents($root.'/'.$project.'/source.php', '<?php return 1;');
    }
    new Process(['git', 'add', '.'], $root)->mustRun();
    new Process(['git', 'commit', '-m', 'initial'], $root)->mustRun();
    $linked = $root.'-linked';
    new Process(['git', 'worktree', 'add', '-b', 'feature', $linked], $root)->mustRun();
    try {
        $first = Storage::tempDir($root.'/apps/first');
        expect(Storage::tempDir($root.'/packages/second'))->not->toBe($first);
        expect(Storage::tempDir($linked.'/apps/first'))->not->toBe($first);
        file_put_contents($linked.'/apps/first/source.php', '<?php return 2;');
        $changes = new ChangedFiles($linked.'/apps/first');
        expect($changes->repoPrefix())->toBe('apps/first/');
        expect($changes->since($changes->currentSha()))->toBe(['source.php']);
    } finally {
        new Process(['git', 'worktree', 'remove', '--force', $linked], $root)->mustRun();
    }
});
