<?php

declare(strict_types=1);

use App\E2E\Git\GitRepository;
use App\E2E\GuestTransport;
use App\E2E\ProofFixtureStager;
use App\E2E\Value\OperationId;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;

beforeEach(function (): void {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
});

/** @return array{root:string,script:string,candidate:string} */
function loopArtifactFixture(): array
{
    $root = temporaryPath('orbit-loop-', 6);
    mkdir($root.'/.loop/proof', 0700, true);
    $run = new ProcessFactory;
    foreach ([
        ['init',   '-b',         'tst-42-feature'],
        ['config', 'user.name',  'Orbit'],
        ['config', 'user.email', 'orbit@example.test'],
    ] as $args) {
        expect($run->path($root)->run(['git', ...$args])->successful())->toBeTrue();
    }
    file_put_contents($root.'/.gitignore', "/.loop/\n/.e2e/\n");
    file_put_contents($root.'/runtime.txt', "candidate\n");
    $run->path($root)->run(['git', 'add', '.']);
    $run->path($root)->run(['git', 'commit', '-m', 'candidate']);
    $run->run(['git', 'init', '--bare', $root.'-remote']);
    $run->path($root)->run(['git', 'remote', 'add', 'origin', $root.'-remote']);
    file_put_contents($root.'/.loop/plan.md', "Issue: TST-42\n");
    file_put_contents(
        $root.'/.loop/proof/TST-42.json',
        '{"acceptance":[{"id":"check","node":"app-dev","argv":["true"],"timeout_seconds":30}]}',
    );
    file_put_contents($root.'/.loop/proof/check.sh', "#!/bin/sh\nexit 0\n");
    chmod($root.'/.loop/proof/check.sh', 0755);

    return [
        'root' => $root,
        'script' => dirname(__DIR__, 5).'/bin/loop-artifacts',
        'candidate' => new GitRepository($root)->commit(),
    ];
}

it('publishes immutable candidate-bound artifacts without changing HEAD or the index', function (): void {
    $fixture = loopArtifactFixture();
    $run = new ProcessFactory;
    file_put_contents($fixture['root'].'/runtime.txt', "staged change\n");
    $run->path($fixture['root'])->run(['git', 'add', 'runtime.txt']);
    $index = $run->path($fixture['root'])->run(['git', 'write-tree'])->output();
    $result = $run->path($fixture['root'])->run([$fixture['script'], 'publish', 'TST-42']);
    expect($result->successful())->toBeTrue($result->errorOutput());
    $published = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);
    $repository = new GitRepository($fixture['root']);
    expect($repository->commit())
        ->toBe($fixture['candidate'])
        ->and($run->path($fixture['root'])->run(['git', 'write-tree'])->output())
        ->toBe($index)
        ->and($repository->loopCommit('TST-42', $fixture['candidate']))
        ->toBe($published['artifacts'])
        ->and($repository->blobs($published['artifacts'], ['runtime.txt'])['runtime.txt'])
        ->toBe("candidate\n")
        ->and($repository->entries($fixture['candidate']))
        ->not->toHaveKey('.loop/plan.md');
    $stager = new ProofFixtureStager(Mockery::mock(GuestTransport::class), new OperationId(str_repeat('a', 32)));
    file_put_contents($fixture['root'].'/.loop/proof/check.sh', "uncommitted change\n");
    expect($stager->inventory($repository, $fixture['candidate'], 'TST-42')['check.sh']['content'])
        ->toBe("#!/bin/sh\nexit 0\n");
    $changed = $run->path($fixture['root'])->run([$fixture['script'], 'publish', 'TST-42']);
    expect($changed->successful())
        ->toBeFalse()
        ->and($changed->errorOutput())
        ->toContain('already has different artifacts');
});

it('refuses artifact symlinks and bindings that change product files', function (): void {
    $fixture = loopArtifactFixture();
    $run = new ProcessFactory;
    symlink($fixture['root'].'/runtime.txt', $fixture['root'].'/.loop/proof/link');
    $result = $run->path($fixture['root'])->run([$fixture['script'], 'publish', 'TST-42']);
    expect($result->successful())->toBeFalse();
    unlink($fixture['root'].'/.loop/proof/link');
    file_put_contents($fixture['root'].'/runtime.txt', 'changed');
    $run->path($fixture['root'])->run(['git', 'commit', '-am', 'wrong artifact']);
    $run->path($fixture['root'])->run(['git', 'tag', 'loop/tst-42/'.$fixture['candidate']]);
    expect(fn () => new GitRepository($fixture['root'])->loopCommit('TST-42', $fixture['candidate']))
        ->toThrow(InvalidArgumentException::class, 'outside its delivery workspace');
});
