<?php

declare(strict_types=1);

use Illuminate\Process\Factory as ProcessFactory;

/** @return array{root: string, web: string, run: ProcessFactory, environment: array<string, string>} */
function webDeployFixture(): array
{
    $root = temporaryPath('orbit-web-deploy-', 6);
    $tools = $root.'/tools';
    $web = $root.'/gateway/web';
    $repository = $root.'/repository';
    foreach ([$repository.'/bin', $repository.'/apps/web', $repository.'/packages/agent-annotation', $tools, $web.'/releases'] as $directory) {
        mkdir($directory, 0700, true);
    }
    copy(dirname(__DIR__, 5).'/bin/web-deploy', $repository.'/bin/web-deploy');
    chmod($repository.'/bin/web-deploy', 0755);
    file_put_contents($repository.'/.gitignore', "apps/web/dist/\napps/web/.env.local\n");
    file_put_contents($repository.'/apps/web/package.json', "{}\n");
    file_put_contents($repository.'/packages/agent-annotation/package.json', "{}\n");

    // `bun run build` writes a small release named after the commit, so each deploy is distinguishable.
    file_put_contents($tools.'/bun', <<<'BASH'
        #!/usr/bin/env bash
        set -eu
        if [ "$*" = "run build" ]; then
            mkdir -p dist/assets
            git rev-parse HEAD > dist/index.html
            printf 'demo=%s local=%s\n' "${VITE_ORBIT_DEMO:-unset}" "$(test -f .env.local && echo present || echo absent)" >> dist/index.html
            printf 'asset\n' > dist/assets/app.js
        fi
        BASH);
    // The fake ssh drops the host and runs the remote command on this machine, for rsync too.
    file_put_contents($tools.'/ssh', <<<'BASH'
        #!/usr/bin/env bash
        shift
        exec "$@"
        BASH);
    chmod($tools.'/bun', 0755);
    chmod($tools.'/ssh', 0755);

    $run = new ProcessFactory;
    foreach ([
        ['init', '-b', 'main'],
        ['config', 'user.name', 'Orbit'],
        ['config', 'user.email', 'orbit@example.test'],
        ['add', '.'],
        ['commit', '-m', 'first'],
    ] as $arguments) {
        $result = $run->path($repository)->run(['git', ...$arguments]);
        expect($result->successful())->toBeTrue($result->errorOutput());
    }

    $group = trim((string) shell_exec('id -gn'));

    return ['root' => $repository, 'web' => $web, 'run' => $run, 'environment' => [
        'PATH' => $tools.':'.getenv('PATH'),
        'ORBIT_WEB_DEPLOY_SSH' => $tools.'/ssh',
        'ORBIT_WEB_DEPLOY_HOST' => 'gateway.test',
        'ORBIT_WEB_DIR' => $web,
        'ORBIT_WEB_GROUP' => $group,
    ]];
}

function webDeployCommit(ProcessFactory $run, string $root, string $message): string
{
    file_put_contents($root.'/apps/web/package.json', json_encode(['release' => $message], JSON_THROW_ON_ERROR)."\n");
    expect($run->path($root)->run(['git', 'commit', '-qam', $message])->successful())->toBeTrue();

    return substr(trim($run->path($root)->run(['git', 'rev-parse', 'HEAD'])->output()), 0, 12);
}

it('releases the clean commit without local build settings, switches current, and restricts the files to the web group', function (): void {
    ['root' => $root, 'web' => $web, 'run' => $run, 'environment' => $environment] = webDeployFixture();
    $release = substr(trim($run->path($root)->run(['git', 'rev-parse', 'HEAD'])->output()), 0, 12);
    file_put_contents($root.'/apps/web/.env.local', "VITE_ORBIT_DEMO=1\n");

    $result = $run->path($root)->env([...$environment, 'VITE_ORBIT_DEMO' => '1'])->run(['bin/web-deploy']);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output())->toContain("Serving release {$release}.")
        ->and(readlink($web.'/current'))->toBe("releases/{$release}")
        ->and(file_get_contents($web.'/current/assets/app.js'))->toBe("asset\n")
        ->and(file_get_contents($web.'/current/index.html'))->toContain('demo=unset local=absent')
        ->and(fileperms($web.'/current/index.html') & 0o777)->toBe(0o640)
        ->and(fileperms($web."/releases/{$release}/assets") & 0o777)->toBe(0o750)
        ->and(glob($web.'/releases/*.partial'))->toBe([]);
});

it('refuses uncommitted changes before building or uploading', function (): void {
    ['root' => $root, 'web' => $web, 'run' => $run, 'environment' => $environment] = webDeployFixture();
    file_put_contents($root.'/apps/web/package.json', "{\"dirty\":true}\n");

    $result = $run->path($root)->env($environment)->run(['bin/web-deploy']);

    expect($result->exitCode())->toBe(1)
        ->and($result->errorOutput())->toContain('Commit or discard every change first')
        ->and(is_dir($root.'/apps/web/dist'))->toBeFalse()
        ->and(glob($web.'/releases/*'))->toBe([]);
});

it('switches back to a retained release and refuses one that is gone', function (): void {
    ['root' => $root, 'web' => $web, 'run' => $run, 'environment' => $environment] = webDeployFixture();
    $first = substr(trim($run->path($root)->run(['git', 'rev-parse', 'HEAD'])->output()), 0, 12);
    expect($run->path($root)->env($environment)->run(['bin/web-deploy'])->successful())->toBeTrue();
    $second = webDeployCommit($run, $root, 'second');
    expect($run->path($root)->env($environment)->run(['bin/web-deploy'])->successful())->toBeTrue()
        ->and(readlink($web.'/current'))->toBe("releases/{$second}");

    $switched = $run->path($root)->env($environment)->run(['bin/web-deploy', '--switch', $first]);
    $missing = $run->path($root)->env($environment)->run(['bin/web-deploy', '--switch', 'abcdefabcdef']);
    $malformed = $run->path($root)->env($environment)->run(['bin/web-deploy', '--switch', 'main']);

    expect($switched->successful())->toBeTrue($switched->errorOutput())
        ->and(readlink($web.'/current'))->toBe("releases/{$first}")
        ->and($missing->exitCode())->toBe(1)
        ->and($missing->errorOutput())->toContain('No retained release abcdefabcdef.')
        ->and($malformed->exitCode())->toBe(2)
        ->and(readlink($web.'/current'))->toBe("releases/{$first}");
});

it('keeps the five newest releases', function (): void {
    ['root' => $root, 'web' => $web, 'run' => $run, 'environment' => $environment] = webDeployFixture();
    $oldest = substr(trim($run->path($root)->run(['git', 'rev-parse', 'HEAD'])->output()), 0, 12);
    expect($run->path($root)->env($environment)->run(['bin/web-deploy'])->successful())->toBeTrue();
    touch($web."/releases/{$oldest}", time() - 3600);
    $releases = [];
    foreach (range(1, 5) as $index) {
        $releases[] = webDeployCommit($run, $root, "release {$index}");
        expect($run->path($root)->env($environment)->run(['bin/web-deploy'])->successful())->toBeTrue();
        touch($web.'/releases/'.end($releases), time() - 3600 + $index * 60);
    }

    expect(array_map(basename(...), glob($web.'/releases/*')))->toEqualCanonicalizing($releases);

    $newest = webDeployCommit($run, $root, 'release 6');
    expect($run->path($root)->env($environment)->run(['bin/web-deploy'])->successful())->toBeTrue();

    expect(array_map(basename(...), glob($web.'/releases/*')))
        ->toEqualCanonicalizing([...array_slice($releases, 1), $newest]);
});
