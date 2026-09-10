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
    symlink('runtime.txt', $root.'/product-link');
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

/** @param list<string> $arguments */
function loopArtifactGit(string $root, array $arguments, string $input = ''): string
{
    $result = (new ProcessFactory)->path($root)->input($input)->run(['git', ...$arguments]);
    expect($result->successful())->toBeTrue($result->errorOutput());

    return trim($result->output());
}

it('fetches a validated binding and preserves executable fixtures, product symlinks, HEAD and the index', function (): void {
    $fixture = loopArtifactFixture();
    $root = $fixture['root'];
    $run = new ProcessFactory;
    file_put_contents($root."/.loop/name\nwith tab\t.md", 'literal filename');
    file_put_contents($root.'/runtime.txt', 'staged product change');
    loopArtifactGit($root, ['add', 'runtime.txt']);
    $index = loopArtifactGit($root, ['write-tree']);
    $published = $run->path($root)->run([$fixture['script'], 'publish', 'TST-42']);
    expect($published->successful())->toBeTrue($published->errorOutput());
    $binding = json_decode($published->output(), true, 512, JSON_THROW_ON_ERROR);

    $repeated = $run->path($root)->run([$fixture['script'], 'publish', 'TST-42']);
    expect($repeated->successful())->toBeTrue($repeated->errorOutput());
    expect(json_decode($repeated->output(), true, 512, JSON_THROW_ON_ERROR))->toBe($binding);
    loopArtifactGit($root, ['update-ref', '-d', $binding['ref']]);
    symlink('../runtime.txt', $root.'/.loop/local-only-link');
    $fetched = $run->path($root)->run([
        $fixture['script'], 'fetch', 'TST-42', '--candidate='.$fixture['candidate'],
        '--expected-artifact='.$binding['artifacts'],
    ]);

    expect($fetched->successful())->toBeTrue($fetched->errorOutput());
    expect(json_decode($fetched->output(), true, 512, JSON_THROW_ON_ERROR))->toBe($binding);
    expect(loopArtifactGit($root, ['rev-parse', 'HEAD']))->toBe($fixture['candidate']);
    expect(loopArtifactGit($root, ['write-tree']))->toBe($index);
    expect(loopArtifactGit($root, ['ls-tree', $binding['artifacts'], '.loop/proof/check.sh']))
        ->toStartWith('100755 blob');
    expect(loopArtifactGit($root, ['ls-tree', $binding['artifacts'], 'product-link']))
        ->toStartWith('120000 blob');

    $legacy = $run->path($root)->run([$fixture['script'], 'fetch', 'TST-42']);
    expect($legacy->successful())->toBeTrue($legacy->errorOutput());
    expect(json_decode($legacy->output(), true, 512, JSON_THROW_ON_ERROR))->toBe($binding);
});

it('rejects malformed fetched artifacts without reporting a successful binding', function (Closure $malform, string $message): void {
    $fixture = loopArtifactFixture();
    $root = $fixture['root'];
    loopArtifactGit($root, ['add', '-f', '.loop']);
    $tree = loopArtifactGit($root, ['write-tree']);
    [$candidate, $artifact] = $malform($root, $fixture['candidate'], $tree);
    $ref = 'refs/tags/loop/tst-42/'.$candidate;
    loopArtifactGit($root, ['push', 'origin', $artifact.':'.$ref]);
    loopArtifactGit($root, ['reset', 'HEAD']);
    $index = loopArtifactGit($root, ['write-tree']);
    $result = (new ProcessFactory)->path($root)->run([
        $fixture['script'], 'fetch', 'TST-42', '--candidate='.$candidate, '--expected-artifact='.$artifact,
    ]);

    expect($result->successful())->toBeFalse();
    expect($result->output())->toBe('');
    expect($result->errorOutput())->toContain('Artifact validation failed:', $message);
    expect(loopArtifactGit($root, ['rev-parse', 'HEAD']))->toBe($fixture['candidate']);
    expect(loopArtifactGit($root, ['write-tree']))->toBe($index);
})->with([
    'no parent' => [fn (string $root, string $candidate, string $tree): array => [
        $candidate, loopArtifactGit($root, ['commit-tree', $tree, '-m', 'root artifact']),
    ], 'sole parent'],
    'wrong parent' => [function (string $root, string $candidate, string $tree): array {
        $other = loopArtifactGit($root, ['commit-tree', $tree, '-p', $candidate, '-m', 'other']);

        return [$candidate, loopArtifactGit($root, ['commit-tree', $tree, '-p', $other, '-m', 'artifact'])];
    }, 'sole parent'],
    'multiple parents' => [function (string $root, string $candidate, string $tree): array {
        $other = loopArtifactGit($root, ['commit-tree', $tree, '-m', 'other']);

        return [$candidate, loopArtifactGit($root, ['commit-tree', $tree, '-p', $candidate, '-p', $other, '-m', 'artifact'])];
    }, 'sole parent'],
    'product modification' => [function (string $root, string $candidate, string $tree): array {
        file_put_contents($root.'/runtime.txt', 'changed product');
        loopArtifactGit($root, ['add', 'runtime.txt']);
        $tree = loopArtifactGit($root, ['write-tree']);

        return [$candidate, loopArtifactGit($root, ['commit-tree', $tree, '-p', $candidate, '-m', 'artifact'])];
    }, 'outside .loop/'],
    'similar path prefix' => [function (string $root, string $candidate, string $tree): array {
        file_put_contents($root.'/.loop-other', 'outside');
        loopArtifactGit($root, ['add', '.loop-other']);
        $tree = loopArtifactGit($root, ['write-tree']);

        return [$candidate, loopArtifactGit($root, ['commit-tree', $tree, '-p', $candidate, '-m', 'artifact'])];
    }, 'outside .loop/'],
    'tracked candidate workspace' => [function (string $root, string $candidate, string $tree): array {
        $candidate = loopArtifactGit($root, ['commit-tree', $tree, '-p', $candidate, '-m', 'tracked workspace']);

        return [$candidate, loopArtifactGit($root, ['commit-tree', $tree, '-p', $candidate, '-m', 'artifact'])];
    }, 'contains .loop'],
    'empty tracked candidate workspace' => [function (string $root, string $candidate, string $tree): array {
        $empty = loopArtifactGit($root, ['mktree']);
        $entries = loopArtifactGit($root, ['ls-tree', $candidate]);
        $candidateTree = loopArtifactGit($root, ['mktree'], $entries."\n040000 tree $empty\t.loop\n");
        $candidate = loopArtifactGit($root, ['commit-tree', $candidateTree, '-m', 'tracked empty workspace']);

        return [$candidate, loopArtifactGit($root, ['commit-tree', $tree, '-p', $candidate, '-m', 'artifact'])];
    }, 'contains .loop'],
    'empty product directory addition' => [function (string $root, string $candidate, string $tree): array {
        $empty = loopArtifactGit($root, ['mktree']);
        $entries = loopArtifactGit($root, ['ls-tree', $tree]);
        $tree = loopArtifactGit($root, ['mktree'], $entries."\n040000 tree $empty\toutside\n");

        return [$candidate, loopArtifactGit($root, ['commit-tree', $tree, '-p', $candidate, '-m', 'artifact'])];
    }, 'outside .loop/'],
    'tag object instead of artifact commit' => [function (string $root, string $candidate, string $tree): array {
        $artifact = loopArtifactGit($root, ['commit-tree', $tree, '-p', $candidate, '-m', 'artifact']);
        loopArtifactGit($root, ['tag', '-a', 'annotated-artifact', $artifact, '-m', 'indirect artifact']);

        return [$candidate, loopArtifactGit($root, ['rev-parse', 'refs/tags/annotated-artifact'])];
    }, 'must name a commit object'],
    'artifact symlink' => [function (string $root, string $candidate, string $tree): array {
        symlink('../runtime.txt', $root.'/.loop/link');
        loopArtifactGit($root, ['add', '-f', '.loop/link']);
        $tree = loopArtifactGit($root, ['write-tree']);

        return [$candidate, loopArtifactGit($root, ['commit-tree', $tree, '-p', $candidate, '-m', 'artifact'])];
    }, 'regular file'],
    'artifact gitlink' => [function (string $root, string $candidate, string $tree): array {
        loopArtifactGit($root, ['update-index', '--add', '--cacheinfo', '160000,'.$candidate.',.loop/module']);
        $tree = loopArtifactGit($root, ['write-tree']);

        return [$candidate, loopArtifactGit($root, ['commit-tree', $tree, '-p', $candidate, '-m', 'artifact'])];
    }, 'regular file'],
    'empty artifact directory' => [function (string $root, string $candidate, string $tree): array {
        $empty = loopArtifactGit($root, ['mktree']);
        $entries = loopArtifactGit($root, ['ls-tree', $candidate]);
        $tree = loopArtifactGit($root, ['mktree'], $entries."\n040000 tree $empty\t.loop\n");

        return [$candidate, loopArtifactGit($root, ['commit-tree', $tree, '-p', $candidate, '-m', 'artifact'])];
    }, 'at least one regular file'],
]);

it('refuses an expected SHA mismatch and distinguishes fetch failures', function (): void {
    $fixture = loopArtifactFixture();
    $run = new ProcessFactory;
    $published = $run->path($fixture['root'])->run([$fixture['script'], 'publish', 'TST-42']);
    expect($published->successful())->toBeTrue($published->errorOutput());
    $result = $run->path($fixture['root'])->run([
        $fixture['script'], 'fetch', 'TST-42', '--expected-artifact='.$fixture['candidate'],
    ]);
    expect($result->successful())->toBeFalse();
    expect($result->output())->toBe('');
    expect($result->errorOutput())->toContain('Artifact validation failed: expected '.$fixture['candidate']);

    loopArtifactGit($fixture['root'], ['remote', 'set-url', 'origin', $fixture['root'].'/missing-remote']);
    $failed = $run->path($fixture['root'])->run([$fixture['script'], 'fetch', 'TST-42']);
    expect($failed->successful())->toBeFalse();
    expect($failed->output())->toBe('');
    expect($failed->errorOutput())->toContain('Git fetch failed:')->not->toContain('Artifact validation failed:');
});

it('refuses to publish a reused artifact with the right tree and wrong parent', function (): void {
    $fixture = loopArtifactFixture();
    $root = $fixture['root'];
    loopArtifactGit($root, ['add', '-f', '.loop']);
    $tree = loopArtifactGit($root, ['write-tree']);
    $artifact = loopArtifactGit($root, ['commit-tree', $tree, '-m', 'unbound artifact']);
    $ref = 'refs/tags/loop/tst-42/'.$fixture['candidate'];
    loopArtifactGit($root, ['update-ref', $ref, $artifact]);
    loopArtifactGit($root, ['reset', 'HEAD']);
    $index = loopArtifactGit($root, ['write-tree']);

    $result = (new ProcessFactory)->path($root)->run([$fixture['script'], 'publish', 'TST-42']);

    expect($result->successful())->toBeFalse();
    expect($result->output())->toBe('');
    expect($result->errorOutput())->toContain('sole parent');
    expect(loopArtifactGit($root, ['ls-remote', 'origin', $ref]))->toBe('');
    expect(loopArtifactGit($root, ['rev-parse', 'HEAD']))->toBe($fixture['candidate']);
    expect(loopArtifactGit($root, ['write-tree']))->toBe($index);
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
