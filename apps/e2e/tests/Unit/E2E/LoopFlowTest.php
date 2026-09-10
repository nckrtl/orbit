<?php

declare(strict_types=1);

use App\E2E\DeliveryFlow;
use Illuminate\Process\Factory as ProcessFactory;

/** @return array{root:string,script:string,run:ProcessFactory} */
function loopFlowFixture(): array
{
    $root = temporaryPath('orbit-flow-', 6);
    mkdir($root.'/bin', 0700, true);
    $run = new ProcessFactory;
    foreach ([
        ['init',   '-b',         'main'],
        ['config', 'user.name',  'Orbit'],
        ['config', 'user.email', 'orbit@example.test'],
    ] as $args) {
        expect($run->path($root)->run(['git', ...$args])->successful())->toBeTrue();
    }
    file_put_contents($root.'/.gitignore', "/.loop/\n/.worktrees/\n");
    file_put_contents($root.'/shared.txt', "base\n");
    foreach (['loop-flow', 'worktree-create'] as $script) {
        copy(dirname(__DIR__, 5).'/bin/'.$script, $root.'/bin/'.$script);
        chmod($root.'/bin/'.$script, 0755);
    }
    file_put_contents($root.'/bin/bootstrap', "#!/bin/sh\nexit 0\n");
    chmod($root.'/bin/bootstrap', 0755);
    expect($run->path($root)->run(['git', 'add', '.'])->successful())->toBeTrue();
    expect($run->path($root)->run(['git', 'commit', '-m', 'base'])->successful())->toBeTrue();

    return ['root' => $root, 'script' => $root.'/bin/loop-flow', 'run' => $run];
}

it('pins a repository default per worktree and requires explicit switching', function (): void {
    ['root' => $root, 'script' => $script, 'run' => $run] = loopFlowFixture();
    $head = $run->path($root)->run(['git', 'rev-parse', 'HEAD'])->output();
    $index = $run->path($root)->run(['git', 'write-tree'])->output();
    expect($run->path($root)->run([$script, 'status'])->output())->toBe("proof\n");
    expect($run->path($root)->run([$script, 'default', '--flow=discovery'])->successful())->toBeTrue();
    expect($run->path($root)->run([$script, 'init'])->output())->toBe("discovery\n");
    expect($run->path($root)->run([$script, 'default', '--flow=proof'])->successful())->toBeTrue();
    expect($run->path($root)->run([$script, 'init'])->output())->toBe("discovery\n");
    expect($run->path($root)->run([$script, 'init', '--flow=proof'])->successful())->toBeFalse();
    expect(DeliveryFlow::forWorktree($root))->toBe('discovery');

    expect($run->path($root)->run([$script, 'select', '--flow=proof'])->output())->toBe("proof\n");
    expect(DeliveryFlow::forWorktree($root))->toBe('proof');
    expect($run->path($root)->run(['git', 'rev-parse', 'HEAD'])->output())->toBe($head);
    expect($run->path($root)->run(['git', 'write-tree'])->output())->toBe($index);
});

it('creates worktrees with the flag or shared default and preserves existing selections', function (): void {
    ['root' => $root, 'script' => $script, 'run' => $run] = loopFlowFixture();
    $remote = temporaryPath('orbit-flow-remote-', 6);
    expect($run->run(['git', 'init', '--bare', $remote])->successful())->toBeTrue();
    expect($run->path($root)->run(['git', 'remote', 'add', 'origin', $remote])->successful())->toBeTrue();
    expect($run->path($root)->run(['git', 'push', '-u', 'origin', 'main'])->successful())->toBeTrue();
    expect($run->path($root)->run([$script, 'default', '--flow=discovery'])->successful())->toBeTrue();
    $create = $root.'/bin/worktree-create';

    $first = $run->path($root)->run([$create, 'TST-42', 'feature']);
    expect($first->successful())->toBeTrue($first->errorOutput());
    expect(DeliveryFlow::forWorktree($root.'/.worktrees/tst-42-feature'))->toBe('discovery');
    $second = $run->path($root)->run([$create, 'TST-43', 'feature', '--flow=proof']);
    expect($second->successful())->toBeTrue($second->errorOutput());
    expect(DeliveryFlow::forWorktree($root.'/.worktrees/tst-43-feature'))->toBe('proof');
    expect($run->path($root)->run([$create, 'TST-43', 'feature'])->successful())->toBeTrue();
    expect(DeliveryFlow::forWorktree($root.'/.worktrees/tst-43-feature'))->toBe('proof');
    expect(file_exists($root.'/.worktrees/tst-42-feature/.loop/plan.md'))->toBeTrue();
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
