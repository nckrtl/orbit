<?php

declare(strict_types=1);

use App\E2E\DeliveryFlow;
use Illuminate\Process\Factory as ProcessFactory;

/** @return array{root:string,script:string,run:ProcessFactory} */
function loopFlowFixture(): array
{
    $root = temporaryPath('orbit-flow-', 6);
    mkdir($root.'/bin', 0700, true);
    mkdir($root.'/.agents/skills/planning-features', 0700, true);
    $run = new ProcessFactory;
    foreach ([
        ['init',   '-b',         'main'],
        ['config', 'user.name',  'Orbit'],
        ['config', 'user.email', 'orbit@example.test'],
        ['config', 'orbit.worktreeRoot', $root.'-worktrees'],
    ] as $args) {
        expect($run->path($root)->run(['git', ...$args])->successful())->toBeTrue();
    }
    file_put_contents($root.'/.gitignore', "/.e2e/\n/.loop/\n/.worktrees/\n");
    file_put_contents($root.'/shared.txt', "base\n");
    foreach (['loop-flow', 'worktree-create', 'worktree-remove'] as $script) {
        copy(dirname(__DIR__, 5).'/bin/'.$script, $root.'/bin/'.$script);
        chmod($root.'/bin/'.$script, 0755);
    }
    copy(
        dirname(__DIR__, 5).'/.agents/skills/planning-features/template.md',
        $root.'/.agents/skills/planning-features/template.md',
    );
    file_put_contents($root.'/bin/bootstrap', "#!/bin/sh\nexit 0\n");
    chmod($root.'/bin/bootstrap', 0755);
    file_put_contents(
        $root.'/bin/tia-cache',
        "#!/bin/sh\nprintf '%s\\n' \"\$*\" > \"\$(git rev-parse --git-common-dir)/tia-queued\"\nexit 1\n",
    );
    chmod($root.'/bin/tia-cache', 0755);
    expect($run->path($root)->run(['git', 'add', '.'])->successful())->toBeTrue();
    expect($run->path($root)->run(['git', 'commit', '-m', 'base'])->successful())->toBeTrue();

    return ['root' => $root, 'script' => $root.'/bin/loop-flow', 'run' => $run];
}

it('pins a repository default per worktree and requires explicit switching', function (): void {
    ['root' => $root, 'script' => $script, 'run' => $run] = loopFlowFixture();
    $head = $run->path($root)->run(['git', 'rev-parse', 'HEAD'])->output();
    $index = $run->path($root)->run(['git', 'write-tree'])->output();
    expect($run->path($root)->run([$script, 'status'])->output())->toBe("discovery\n");
    expect($run->path($root)->run([$script, 'default'])->output())->toBe("discovery\n");
    expect(DeliveryFlow::forWorktree($root))->toBe('discovery');
    expect($run->path($root)->run([$script, 'init'])->output())->toBe("discovery\n");
    expect($run->path($root)->run([$script, 'default', '--flow=proof'])->successful())->toBeTrue();
    expect($run->path($root)->run([$script, 'init'])->output())->toBe("discovery\n");
    expect($run->path($root)->run([$script, 'init', '--flow=proof'])->successful())->toBeFalse();
    expect(DeliveryFlow::forWorktree($root))->toBe('discovery');

    expect($run->path($root)->run([$script, 'select', '--flow=proof'])->output())->toBe("proof\n");
    expect(DeliveryFlow::forWorktree($root))->toBe('proof');
    DeliveryFlow::requireProof($root);
    expect($run->path($root)->run(['git', 'rev-parse', 'HEAD'])->output())->toBe($head);
    expect($run->path($root)->run(['git', 'write-tree'])->output())->toBe($index);
});

it('creates worktrees with the flag or shared default and preserves existing selections', function (): void {
    ['root' => $root, 'script' => $script, 'run' => $run] = loopFlowFixture();
    $remote = temporaryPath('orbit-flow-remote-', 6);
    expect($run->run(['git', 'init', '--bare', $remote])->successful())->toBeTrue();
    expect($run->path($root)->run(['git', 'remote', 'add', 'origin', $remote])->successful())->toBeTrue();
    expect($run->path($root)->run(['git', 'push', '-u', 'origin', 'main'])->successful())->toBeTrue();
    $create = $root.'/bin/worktree-create';

    $first = $run->path($root)->run([$create, 'TST-42']);
    expect($first->successful())->toBeTrue($first->errorOutput());
    expect(DeliveryFlow::forWorktree($root.'-worktrees/tst-42'))->toBe('discovery');
    $second = $run->path($root)->run([$create, 'TST-43', '--flow=proof']);
    expect($second->successful())->toBeTrue($second->errorOutput());
    expect(DeliveryFlow::forWorktree($root.'-worktrees/tst-43'))->toBe('proof');
    expect($run->path($root)->run([$create, 'TST-43'])->successful())->toBeTrue();
    expect(DeliveryFlow::forWorktree($root.'-worktrees/tst-43'))->toBe('proof');
    expect(file_exists($root.'-worktrees/tst-42/.loop/plan.md'))->toBeTrue();
    expect(is_dir($root.'/.worktrees'))->toBeFalse();
    expect(trim($run->path($root.'-worktrees/tst-42')->run(['git', 'branch', '--show-current'])->output()))->toBe('tst-42');
    expect($run->path($root)->run([$create, 'TST-42', 'redundant-slug'])->successful())->toBeFalse();
    expect($run->path($root)->run(['git', 'status', '--porcelain'])->output())->toBe('');
    expect($run->path($root)->run([$script, 'default', '--flow=proof'])->successful())->toBeTrue();
    expect($run->path($root)->run([$create, 'TST-44'])->successful())->toBeTrue();
    expect(DeliveryFlow::forWorktree($root.'-worktrees/tst-44'))->toBe('proof');
    expect($run->path($root)->run([$root.'/bin/worktree-remove', 'TST-42'])->successful())->toBeTrue();
    expect(is_dir($root.'-worktrees/tst-42'))->toBeFalse();
    expect($run->path($root)->run(['git', 'show-ref', '--verify', 'refs/heads/tst-42'])->successful())->toBeFalse();
});

it('pulls clean primary main and queues warming without making it a worktree creation gate', function (): void {
    ['root' => $root, 'run' => $run] = loopFlowFixture();
    $remote = temporaryPath('orbit-flow-new-main-', 6);
    $run->run(['git', 'init', '--bare', $remote]);
    $run->path($root)->run(['git', 'remote', 'add', 'origin', $remote]);
    $run->path($root)->run(['git', 'push', '-u', 'origin', 'main']);
    $source = $root.'/.worktrees/advance-main';
    $run->path($root)->run(['git', 'worktree', 'add', '-b', 'advance-main', $source]);
    file_put_contents($source.'/next.txt', 'new main');
    $run->path($source)->run(['git', 'add', '.']);
    $run->path($source)->run(['git', 'commit', '-m', 'advance main']);
    $run->path($source)->run(['git', 'push', 'origin', 'HEAD:main']);

    $result = $run->path($root)->run([$root.'/bin/worktree-create', 'TST-46']);

    expect($result->successful())->toBeTrue($result->errorOutput());
    expect(file_get_contents($root.'/next.txt'))->toBe('new main');
    expect(file_get_contents($root.'-worktrees/tst-46/next.txt'))->toBe('new main');
    expect(trim(file_get_contents($root.'/.git/tia-queued')))->toBe('refresh --background --repository='.$root);
    expect($run->path($root)->run(['git', 'status', '--porcelain'])->output())->toBe('');
});

it('preserves dirty primary main before attempting new worktree setup', function (): void {
    ['root' => $root, 'run' => $run] = loopFlowFixture();
    file_put_contents($root.'/shared.txt', 'active migration');

    $result = $run->path($root)->run([$root.'/bin/worktree-create', 'TST-47']);

    expect($result->successful())->toBeFalse();
    expect(file_get_contents($root.'/shared.txt'))->toBe('active migration');
    expect(file_exists($root.'-worktrees/tst-47'))->toBeFalse();
    expect(file_exists($root.'/.git/tia-queued'))->toBeFalse();
});

it('refuses a configured worktree root inside primary or a relative path', function (string $suffix): void {
    ['root' => $root, 'run' => $run] = loopFlowFixture();
    $path = $suffix === 'relative' ? 'relative/worktrees' : $root.$suffix;
    $run->path($root)->run(['git', 'config', 'orbit.worktreeRoot', $path]);

    $result = $run->path($root)->run([$root.'/bin/worktree-create', 'TST-48']);

    expect($result->successful())->toBeFalse();
    expect($result->errorOutput())->toContain('orbit.worktreeRoot must be');
    expect(file_exists($root.'/.git/tia-queued'))->toBeFalse();
})->with(['', '/nested', 'relative']);

it('reuses an existing registered branch after its worktree moves', function (): void {
    ['root' => $root, 'run' => $run] = loopFlowFixture();
    $run->path($root)->run(['git', 'remote', 'add', 'origin', $root]);
    $run->path($root)->run(['git', 'fetch', 'origin']);
    $moved = $root.'-moved worktree';
    $run->path($root)->run(['git', 'worktree', 'add', '-b', 'tst-49-existing', $moved]);

    $result = $run->path($root)->run([$root.'/bin/worktree-create', 'TST-49']);

    expect($result->successful())->toBeTrue($result->errorOutput());
    expect(trim($result->output()))->toEndWith($moved);
    expect(file_exists($moved.'/.loop/plan.md'))->toBeTrue();
    expect(is_dir($root.'-worktrees/tst-49'))->toBeFalse();
});

it('refuses ambiguous legacy branches for issue-only creation and cleanup', function (): void {
    ['root' => $root, 'run' => $run] = loopFlowFixture();
    $run->path($root)->run(['git', 'branch', 'tst-50-first']);
    $run->path($root)->run(['git', 'branch', 'tst-50-second']);
    foreach (['worktree-create', 'worktree-remove'] as $script) {
        $result = $run->path($root)->run([$root.'/bin/'.$script, 'TST-50']);
        expect($result->successful())->toBeFalse();
        expect($result->errorOutput())->toContain('More than one branch matches TST-50');
    }
    expect(file_exists($root.'/.git/tia-queued'))->toBeFalse();
});

it('accepts a conflict-free merge after main advances only in discovery flow', function (): void {
    ['root' => $root, 'script' => $script, 'run' => $run] = loopFlowFixture();
    expect($run->path($root)->run(['git', 'switch', '-c', 'feature'])->successful())->toBeTrue();
    file_put_contents($root.'/feature.txt', 'feature');
    $run->path($root)->run(['git', 'add', '.']);
    $run->path($root)->run(['git', 'commit', '-m', 'feature']);
    $candidate = trim($run->path($root)->run(['git', 'rev-parse', 'HEAD'])->output());
    $run->path($root)->run(['git', 'switch', 'main']);
    file_put_contents($root.'/other.txt', 'new main');
    $run->path($root)->run(['git', 'add', '.']);
    $run->path($root)->run(['git', 'commit', '-m', 'main advance']);
    expect($run->path($root)->run(['git', 'merge', '--no-ff', 'feature', '-m', 'land'])->successful())->toBeTrue();
    $merge = trim($run->path($root)->run(['git', 'rev-parse', 'HEAD'])->output());
    $args = [$script, 'verify-merge', '--candidate='.$candidate, '--merge='.$merge];

    $run->path($root)->run([$script, 'select', '--flow=proof']);
    expect($run->path($root)->run($args)->successful())->toBeFalse();
    $run->path($root)->run([$script, 'select', '--flow=discovery']);
    $result = $run->path($root)->run($args);
    expect($result->successful())->toBeTrue($result->errorOutput());
    expect(json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR)['candidate'])->toBe($candidate);
    file_put_contents($root.'/unreviewed.txt', 'extra change');
    $run->path($root)->run(['git', 'add', '.']);
    $run->path($root)->run(['git', 'commit', '--amend', '--no-edit']);
    expect(
        $run
            ->path($root)
            ->run([$script, 'verify-merge', '--candidate='.$candidate, '--merge=HEAD'])
            ->successful(),
    )
        ->toBeFalse();
});

it('keeps proof merge validation and rejects a different approved candidate', function (): void {
    ['root' => $root, 'script' => $script, 'run' => $run] = loopFlowFixture();
    $run->path($root)->run([$script, 'select', '--flow=proof']);
    $base = trim($run->path($root)->run(['git', 'rev-parse', 'HEAD'])->output());
    $run->path($root)->run(['git', 'switch', '-c', 'feature']);
    file_put_contents($root.'/shared.txt', "feature\n");
    $run->path($root)->run(['git', 'commit', '-am', 'feature']);
    $candidate = trim($run->path($root)->run(['git', 'rev-parse', 'HEAD'])->output());
    $run->path($root)->run(['git', 'switch', 'main']);
    expect($run->path($root)->run(['git', 'merge', '--no-ff', 'feature', '-m', 'land'])->successful())->toBeTrue();

    $result = $run->path($root)->run([$script, 'verify-merge', '--candidate='.$candidate, '--merge=HEAD']);
    expect($result->successful())->toBeTrue($result->errorOutput());
    expect(
        $run
            ->path($root)
            ->run([$script, 'verify-merge', '--candidate='.$base, '--merge=HEAD'])
            ->successful(),
    )
        ->toBeFalse();
});

it('rejects malformed selections in the selector and harness', function (string $content): void {
    ['root' => $root, 'script' => $script, 'run' => $run] = loopFlowFixture();
    mkdir($root.'/.loop');
    file_put_contents($root.'/.loop/flow.json', $content);

    expect($run->path($root)->run([$script, 'status'])->successful())->toBeFalse();
    expect(fn () => DeliveryFlow::forWorktree($root))->toThrow(InvalidArgumentException::class);
})->with(['{', '{"schema":1,"flow":"off"}', '{"schema":2,"flow":"proof"}', '[]']);

it('closes out a merged worktree when the repository has a long worktree listing', function (): void {
    ['root' => $root, 'run' => $run] = loopFlowFixture();
    $remote = temporaryPath('orbit-flow-cleanup-remote-', 6);
    expect($run->run(['git', 'init', '--bare', $remote])->successful())->toBeTrue();
    $run->path($root)->run(['git', 'remote', 'add', 'origin', $remote]);
    $run->path($root)->run(['git', 'push', '-u', 'origin', 'main']);
    $worktree = $root.'/.worktrees/tst-44-cleanup';
    expect(
        $run->path($root)->run(['git', 'worktree', 'add', '-b', 'tst-44-cleanup', $worktree])->successful(),
    )->toBeTrue();
    $wrapper = temporaryPath('orbit-flow-git-wrapper-', 6);
    mkdir($wrapper);
    $git = trim($run->run(['which', 'git'])->output());
    file_put_contents(
        $wrapper.'/git',
        "#!/usr/bin/env python3\n"
        .'import subprocess,sys'
        ."\n"
        .'args=sys.argv[1:]'
        ."\n"
        .'real='
        .json_encode($git, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        ."\n"
        .'result=subprocess.run([real,*args],stdout=subprocess.PIPE)'
        ."\n"
        .'sys.stdout.buffer.write(result.stdout)'
        ."\n"
        .'if args == ["worktree","list","--porcelain"]:'
        ."\n"
        .'    sys.stdout.write("".join(f"worktree /tmp/listing-{i}\\nHEAD {i:040x}\\nbranch refs/heads/listing-{i}\\n\\n" for i in range(4000)))'
        ."\n"
        .'sys.exit(result.returncode)'
        ."\n",
    );
    chmod($wrapper.'/git', 0755);

    $result = $run->path($root)->env(['PATH' => $wrapper.':'.getenv('PATH')])->run([
        $root.'/bin/worktree-remove',
        'TST-44',
    ]);

    expect($result->successful())->toBeTrue($result->errorOutput());
    expect(trim(file_get_contents($root.'/.git/tia-queued')))->toBe('refresh --background --repository='.$root);
    expect(is_dir($worktree))->toBeFalse();
    expect(
        $run->path($root)->run(['git', 'show-ref', '--verify', 'refs/heads/tst-44-cleanup'])->successful(),
    )->toBeFalse();
});

it('refuses to remove an active captured proof when its mutable proof result is absent', function (): void {
    ['root' => $root, 'run' => $run] = loopFlowFixture();
    $remote = temporaryPath('orbit-flow-retained-proof-remote-', 6);
    $run->run(['git', 'init', '--bare', $remote])->throw();
    $run->path($root)->run(['git', 'remote', 'add', 'origin', $remote])->throw();
    $run->path($root)->run(['git', 'push', '-u', 'origin', 'main'])->throw();
    $worktree = $root.'-worktrees/tst-48';
    $run->path($root)->run(['git', 'worktree', 'add', '-b', 'tst-48', $worktree])->throw();
    $attempt = str_repeat('a', 32);
    mkdir($worktree.'/.e2e/captured-proof', 0700, true);
    file_put_contents(
        $worktree.'/.e2e/proof-attempt.json',
        json_encode(['attempt_id' => $attempt], JSON_THROW_ON_ERROR),
    );
    file_put_contents($worktree.'/.e2e/captured-proof/'.$attempt.'.json', '{}');

    $result = $run->path($root)->run([$root.'/bin/worktree-remove', 'TST-48']);

    expect($result->successful())
        ->toBeFalse()
        ->and($result->errorOutput())
        ->toContain('must be released by verified closeout')
        ->and(is_dir($worktree))
        ->toBeTrue()
        ->and(file_exists($root.'/.git/tia-queued'))
        ->toBeFalse();
});

it('does not queue cache refresh or remove an unmerged feature', function (): void {
    ['root' => $root, 'run' => $run] = loopFlowFixture();
    $run->path($root)->run(['git', 'remote', 'add', 'origin', $root]);
    $run->path($root)->run(['git', 'fetch', 'origin']);
    $worktree = $root.'/.worktrees/tst-45-unmerged';
    $run->path($root)->run(['git', 'worktree', 'add', '-b', 'tst-45-unmerged', $worktree]);
    file_put_contents($worktree.'/feature.txt', 'unmerged feature');
    $run->path($worktree)->run(['git', 'add', '.']);
    $run->path($worktree)->run(['git', 'commit', '-m', 'feature']);

    $result = $run->path($root)->run([$root.'/bin/worktree-remove', 'TST-45']);

    expect($result->successful())->toBeFalse();
    expect(is_dir($worktree))->toBeTrue();
    expect(file_exists($root.'/.git/tia-queued'))->toBeFalse();
});
