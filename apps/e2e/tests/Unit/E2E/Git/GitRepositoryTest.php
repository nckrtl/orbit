<?php

declare(strict_types=1);

use App\E2E\Git\GitRepository;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

/** @property string $path */
describe('GitRepository', function (): void {
    beforeEach(function (): void {
        configureProcessFacade();
        $this->path = temporaryPath('orbit-git-', 6);
        mkdir($this->path, 0700, true);
        git($this->path, ['init', '--quiet']);
        git($this->path, ['config', 'user.email', 'orbit@example.test']);
        git($this->path, ['config', 'user.name', 'Orbit']);
    });

    afterEach(function (): void {
        if (is_dir($this->path)) {
            git($this->path, ['clean', '-fdx']);
        }
    });

    it('reads deterministic regular blobs from an exact reachable commit', function (): void {
        mkdir($this->path.'/app', 0700);
        file_put_contents($this->path.'/app/a.php', "first\n");
        file_put_contents($this->path.'/app/b.php', "second\n");
        file_put_contents($this->path.'/root.txt', "root\n");
        git($this->path, ['add', '.']);
        git($this->path, ['commit', '--quiet', '-m', 'fixture']);

        $repository = new GitRepository($this->path);
        $commit = $repository->commit();
        file_put_contents($this->path.'/app/a.php', "dirty\n");

        expect($repository->blobs($commit, ['app/*.php', 'app/a.php']))->toBe([
            'app/a.php' => "first\n",
            'app/b.php' => "second\n",
        ]);
    });

    it('reads all selected blob contents through one Git batch process', function (): void {
        file_put_contents($this->path.'/first.txt', "first\n");
        file_put_contents($this->path.'/second.txt', "second\n");
        git($this->path, ['add', '.']);
        git($this->path, ['commit', '--quiet', '-m', 'fixture']);
        $real = new ProcessFactory;
        $commands = [];
        Process::fake(function (PendingProcess $process) use ($real, &$commands) {
            $commands[] = $process->command;

            return $real
                ->path((string) $process->path)
                ->input($process->input)
                ->run($process->command);
        });

        $blobs = new GitRepository($this->path)->blobs(
            new GitRepository($this->path)->commit(),
            ['first.txt', 'second.txt'],
        );

        expect($blobs)
            ->toBe([
                'first.txt' => "first\n",
                'second.txt' => "second\n",
            ])
            ->and(array_values(array_filter(
                $commands,
                static fn (array $command): bool => in_array('cat-file', $command, true),
            )))
            ->toHaveCount(1);
    });

    it('rejects unsafe or unmatched selectors and non-blob tree entries', function (string $selector): void {
        file_put_contents($this->path.'/tracked.txt', "tracked\n");
        git($this->path, ['add', '.']);
        git($this->path, ['commit', '--quiet', '-m', 'fixture']);

        $repository = new GitRepository($this->path);

        expect(fn (): array => $repository->blobs($repository->commit(), [$selector]))
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'absolute path' => '/tracked.txt',
        'parent component' => '../tracked.txt',
        'dot component' => './tracked.txt',
        'NUL byte' => "tracked\0.txt",
        'newline' => "tracked\n.txt",
        'missing pattern' => 'missing/*.php',
        'unsupported glob' => 'tracked[.]txt',
    ]);

    it('reads the tree of an exact reachable commit and refuses an unreachable one', function (): void {
        file_put_contents($this->path.'/tracked.txt', "tracked\n");
        git($this->path, ['add', '.']);
        git($this->path, ['commit', '--quiet', '-m', 'tracked']);
        $repository = new GitRepository($this->path);
        $commit = $repository->commit();
        $unreachable = git($this->path, ['commit-tree', 'HEAD^{tree}', '-m', 'unreachable']);

        expect($repository->tree($commit))
            ->toBe(git($this->path, ['rev-parse', 'HEAD^{tree}']))
            ->toMatch('/\A[0-9a-f]{40}\z/')
            ->and(fn (): string => $repository->tree($unreachable))
            ->toThrow(InvalidArgumentException::class, 'not reachable')
            ->and(fn (): string => $repository->tree(substr($commit, 0, 7)))
            ->toThrow(InvalidArgumentException::class, 'exact full SHA');
    });

    it('answers whether one commit is an ancestor of another', function (): void {
        file_put_contents($this->path.'/tracked.txt', "one\n");
        git($this->path, ['add', '.']);
        git($this->path, ['commit', '--quiet', '-m', 'one']);
        $repository = new GitRepository($this->path);
        $first = $repository->commit();
        file_put_contents($this->path.'/tracked.txt', "two\n");
        git($this->path, ['commit', '--quiet', '-am', 'two']);
        $second = $repository->commit();
        $orphan = git($this->path, ['commit-tree', 'HEAD^{tree}', '-m', 'orphan']);

        expect($repository->isAncestor($first, $second))
            ->toBeTrue()
            ->and($repository->isAncestor($first, $first))
            ->toBeTrue()
            ->and($repository->isAncestor($second, $first))
            ->toBeFalse()
            ->and($repository->isAncestor($orphan, $second))
            ->toBeFalse()
            ->and(fn (): bool => $repository->isAncestor(substr($first, 0, 7), $second))
            ->toThrow(InvalidArgumentException::class, 'exact full SHA')
            ->and(fn (): bool => $repository->isAncestor(str_repeat('0', 40), $second))
            ->toThrow(InvalidArgumentException::class);
    });

    it('rejects symlinks, submodules, and unreachable commits', function (): void {
        file_put_contents($this->path.'/target.txt', "target\n");
        symlink('target.txt', $this->path.'/link.txt');
        git($this->path, ['add', '.']);
        git($this->path, ['commit', '--quiet', '-m', 'linked']);

        $repository = new GitRepository($this->path);

        expect(fn (): array => $repository->blobs($repository->commit(), ['link.txt']))
            ->toThrow(InvalidArgumentException::class);

        unlink($this->path.'/link.txt');
        git($this->path, ['add', '-u']);
        git($this->path, ['update-index', '--add', '--cacheinfo', '160000,'.$repository->commit().',module']);
        git($this->path, ['commit', '--quiet', '-m', 'gitlink']);

        expect(fn (): array => $repository->blobs($repository->commit(), ['module']))
            ->toThrow(InvalidArgumentException::class);

        $unreachable = git($this->path, ['commit-tree', 'HEAD^{tree}', '-m', 'unreachable']);

        expect(fn (): array => $repository->blobs($unreachable, ['target.txt']))
            ->toThrow(InvalidArgumentException::class);
    });
});

/** @param list<string> $arguments */
function git(string $path, array $arguments): string
{
    $command = array_map(escapeshellarg(...), ['git', '-C', $path, ...$arguments]);
    $output = [];
    $exitCode = 0;
    exec(implode(' ', $command), $output, $exitCode);

    if ($exitCode !== 0) {
        throw new RuntimeException('Git fixture command failed.');
    }

    return trim(implode("\n", $output));
}

function configureProcessFacade(): void
{
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    /** @mago-expect analysis:possibly-invalid-argument The process facade only needs the container contract. */
    Facade::setFacadeApplication($container);
}
