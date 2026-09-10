<?php

declare(strict_types=1);

use Illuminate\Process\Factory as ProcessFactory;

/** @return array{root: string, worktree: string, run: ProcessFactory} */
function worktreeCacheFixture(): array
{
    $root = temporaryPath('orbit-cache-', 6);
    mkdir($root.'/bin', 0700, true);
    $run = new ProcessFactory;
    foreach (['bootstrap', 'worktree-cache'] as $script) {
        copy(dirname(__DIR__, 5).'/bin/'.$script, $root.'/bin/'.$script);
        chmod($root.'/bin/'.$script, 0755);
    }
    file_put_contents($root.'/.gitignore', "/.worktrees/\n**/vendor/\n");
    foreach (['apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'] as $project) {
        mkdir($root.'/'.$project, 0700, true);
        file_put_contents($root.'/'.$project.'/composer.lock', json_encode(['project' => $project], JSON_THROW_ON_ERROR));
        file_put_contents($root.'/'.$project.'/pint.json', '{"cache-file":"vendor/pint.cache"}');
        file_put_contents($root.'/'.$project.'/phpstan.neon', "parameters:\n    level: 1\n");
    }
    foreach ([
        ['init', '-b', 'main'],
        ['config', 'user.name', 'Orbit'],
        ['config', 'user.email', 'orbit@example.test'],
        ['add', '.'],
        ['commit', '-m', 'cache fixture'],
    ] as $arguments) {
        $result = $run->path($root)->run(['git', ...$arguments]);
        expect($result->successful())->toBeTrue($result->errorOutput());
    }
    $worktree = $root.'/.worktrees/feature with spaces';
    $result = $run->path($root)->run(['git', 'worktree', 'add', '-b', 'feature', $worktree]);
    expect($result->successful())->toBeTrue($result->errorOutput());

    return ['root' => $root, 'worktree' => $worktree, 'run' => $run];
}

function writeQualityCache(string $root, string $project, string $cache, string $contents): void
{
    $path = $root.'/'.$project.'/'.$cache;
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0700, true);
    }
    file_put_contents($path, $contents);
}

it('seeds all five projects during bootstrap with independent cache copies', function (): void {
    ['root' => $root, 'worktree' => $worktree, 'run' => $run] = worktreeCacheFixture();
    $projects = ['apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'];
    $caches = ['vendor/pint.cache', 'vendor/phpstan/cache/resultCache.php'];
    foreach ($projects as $project) {
        foreach ($caches as $cache) {
            writeQualityCache($root, $project, $cache, $project.'/'.$cache);
        }
        writeQualityCache($root, $project, 'vendor/phpstan/cache/cache/container.php', 'path-specific container');
    }
    $tools = temporaryPath('orbit-cache-tools-', 6);
    mkdir($tools);
    file_put_contents($tools.'/composer', <<<'SH'
        #!/bin/sh
        if [ "$1" = "install" ]; then
            mkdir -p vendor
            touch vendor/installed
        elif [ "$1" = "guidance:check" ]; then
            test -f vendor/installed && test -f vendor/pint.cache && test -f vendor/phpstan/cache/resultCache.php
        else
            exit 2
        fi
        SH);
    chmod($tools.'/composer', 0755);
    $orbitHome = temporaryPath('orbit-cache-home-', 6);
    mkdir($orbitHome);
    file_put_contents($orbitHome.'/gateway.app-key', 'fixture-key');
    $result = $run->env(['PATH' => $tools.':'.getenv('PATH'), 'ORBIT_HOME' => $orbitHome])->run([$worktree.'/bin/bootstrap']);
    expect($result->successful())->toBeTrue($result->errorOutput().$result->output());

    foreach ($projects as $project) {
        foreach ($caches as $cache) {
            expect(file_get_contents($worktree.'/'.$project.'/'.$cache))->toBe($project.'/'.$cache);
            file_put_contents($worktree.'/'.$project.'/'.$cache, 'worktree result');
            expect(file_get_contents($root.'/'.$project.'/'.$cache))->toBe($project.'/'.$cache);
        }
        expect(file_exists($worktree.'/'.$project.'/vendor/phpstan/cache/cache/container.php'))->toBeFalse();
    }
    expect($run->run([$worktree.'/bin/worktree-cache'])->successful())->toBeTrue();
    expect(file_get_contents($worktree.'/apps/cli/vendor/pint.cache'))->toBe('worktree result');
});

it('selects the most recent compatible worktree for each cache', function (): void {
    ['root' => $root, 'worktree' => $worktree, 'run' => $run] = worktreeCacheFixture();
    $sibling = $root.'/.worktrees/sibling';
    expect($run->path($root)->run(['git', 'worktree', 'add', '-b', 'sibling', $sibling])->successful())->toBeTrue();
    foreach (['vendor/pint.cache', 'vendor/phpstan/cache/resultCache.php'] as $cache) {
        writeQualityCache($root, 'apps/cli', $cache, 'primary cache');
        touch($root.'/apps/cli/'.$cache, time() - 60);
        writeQualityCache($sibling, 'apps/cli', $cache, 'sibling cache');
    }
    file_put_contents($sibling.'/apps/cli/phpstan.neon', 'different configuration');

    expect($run->run([$root.'/bin/worktree-cache', '--worktree='.$worktree])->successful())->toBeTrue();
    expect(file_get_contents($worktree.'/apps/cli/vendor/pint.cache'))->toBe('sibling cache');
    expect(file_get_contents($worktree.'/apps/cli/vendor/phpstan/cache/resultCache.php'))->toBe('primary cache');
});

it('skips incompatible dependency locks and caches that are missing or symlinked', function (): void {
    ['root' => $root, 'worktree' => $worktree, 'run' => $run] = worktreeCacheFixture();
    writeQualityCache($root, 'apps/cli', 'vendor/pint.cache', 'incompatible cache');
    writeQualityCache($root, 'apps/cli', 'vendor/phpstan/cache/resultCache.php', 'incompatible cache');
    file_put_contents($worktree.'/apps/cli/composer.lock', 'different dependencies');
    mkdir($root.'/apps/docs/vendor');
    symlink($root.'/apps/cli/vendor/pint.cache', $root.'/apps/docs/vendor/pint.cache');

    $result = $run->run([$worktree.'/bin/worktree-cache']);
    expect($result->successful())->toBeTrue();
    expect($result->output())->toBe('');
    expect(file_exists($worktree.'/apps/cli/vendor/pint.cache'))->toBeFalse();
    expect(file_exists($worktree.'/apps/cli/vendor/phpstan/cache/resultCache.php'))->toBeFalse();
    expect(file_exists($worktree.'/apps/docs/vendor/pint.cache'))->toBeFalse();
});

it('leaves source archives usable without Git worktree metadata', function (): void {
    $root = temporaryPath('orbit-cache-archive-', 6);
    mkdir($root);
    $result = new ProcessFactory()->run([dirname(__DIR__, 5).'/bin/worktree-cache', '--worktree='.$root]);

    expect($result->successful())->toBeTrue();
    expect($result->output())->toBe('');
});

it('reuses Pint results while checking edited source and excluding generated bootstrap caches', function (): void {
    $root = temporaryPath('orbit-pint-cache-', 6);
    mkdir($root.'/app', 0700, true);
    mkdir($root.'/bootstrap/cache', 0700, true);
    mkdir($root.'/vendor', 0700, true);
    $project = dirname(__DIR__, 3);
    copy($project.'/pint.json', $root.'/pint.json');
    $source = "<?php\n\ndeclare(strict_types=1);\n\nfunction cachedValue(): int\n{\n    return 1;\n}\n";
    file_put_contents($root.'/app/Example.php', $source);
    file_put_contents($root.'/bootstrap/cache/services.php', '<?php return array( 1,2 );');
    $run = new ProcessFactory;
    $arguments = [PHP_BINARY, $project.'/vendor/bin/pint', '--test'];
    $first = $run->path($root)->run($arguments);
    expect($first->successful())->toBeTrue($first->output().$first->errorOutput());
    $cache = $root.'/vendor/pint.cache';
    expect(file_exists($cache))->toBeTrue();
    touch($cache, time() - 60);
    clearstatcache(true, $cache);
    $modifiedAt = filemtime($cache);

    expect($run->path($root)->run($arguments)->successful())->toBeTrue();
    clearstatcache(true, $cache);
    expect(filemtime($cache))->toBe($modifiedAt);
    expect(file_get_contents($root.'/bootstrap/cache/services.php'))->toBe('<?php return array( 1,2 );');

    file_put_contents($root.'/app/Example.php', str_replace('return 1;', 'return  1;', $source));
    $edited = $run->path($root)->run($arguments);
    expect($edited->successful())->toBeFalse();
    expect($edited->output())->toContain('Example.php');
});
