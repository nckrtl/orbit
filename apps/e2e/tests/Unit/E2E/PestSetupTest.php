<?php

declare(strict_types=1);

use Pest\Plugins\Tia\ChangedFiles;
use Pest\Plugins\Tia\Storage;
use Symfony\Component\Process\Process;

/** @return array{project:string,pest:string,script:string,patch:string} */
function pestSetupFixture(bool $upstream = true): array
{
    $repository = dirname(__DIR__, 5);
    $root = temporaryPath('orbit-pest-setup-', 6);
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
        ], sys_get_temp_dir())->mustRun();
    }

    return ['project' => $project, 'pest' => $pest, 'script' => $repository.'/bin/pest-setup', 'patch' => $patch];
}

/** @param array{project:string,pest:string,script:string,patch:string} $fixture */
function runPestSetup(array $fixture): Process
{
    $process = new Process([PHP_BINARY, $fixture['script']], $fixture['project'], ['COMPOSER_VENDOR_DIR' => false]);
    $process->run();

    return $process;
}

it('installs monorepo support in a linked worktree and permits repeated setup', function (): void {
    $fixture = pestSetupFixture();
    file_put_contents(dirname($fixture['project'], 2).'/.git', 'gitdir: /unused/worktree');
    file_put_contents($fixture['pest'].'/unrelated.txt', 'keep me');

    $first = runPestSetup($fixture);
    $second = runPestSetup($fixture);

    expect($first->isSuccessful())->toBeTrue($first->getErrorOutput());
    expect($second->isSuccessful())->toBeTrue($second->getErrorOutput());
    expect(file_exists($fixture['pest'].'/src/Plugins/Tia/GitRepository.php'))->toBeTrue();
    expect(file_exists($fixture['pest'].'/src/Exceptions/TiaRequiresRepositoryRoot.php'))->toBeFalse();
    expect(file_get_contents($fixture['project'].'/composer.lock'))->toBe('unchanged lock');
    expect(file_get_contents($fixture['pest'].'/unrelated.txt'))->toBe('keep me');
    $reverseCheck = new Process([
        'git',
        'apply',
        '--unsafe-paths',
        '--directory='.$fixture['pest'],
        '--reverse',
        '--check',
        $fixture['patch'],
    ], sys_get_temp_dir());
    $reverseCheck->run();
    expect($reverseCheck->isSuccessful())->toBeTrue($reverseCheck->getErrorOutput());
});

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
