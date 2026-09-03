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

    it('reads the regular files directly under one directory with their modes', function (): void {
        mkdir($this->path.'/.loop/proof', 0700, true);
        file_put_contents($this->path.'/.loop/proof/check.sh', "#!/bin/sh\n");
        chmod($this->path.'/.loop/proof/check.sh', 0755);
        file_put_contents($this->path.'/.loop/proof/TST-82.json', "{}\n");
        git($this->path, ['add', '.']);
        git($this->path, ['commit', '--quiet', '-m', 'fixture']);
        $repository = new GitRepository($this->path);
        $commit = $repository->commit();

        expect($repository->directoryBlobs($commit, '.loop/proof'))
            ->toBe([
                'TST-82.json' => ['mode' => '100644', 'content' => "{}\n"],
                'check.sh' => ['mode' => '100755', 'content' => "#!/bin/sh\n"],
            ])
            ->and($repository->directoryBlobs($commit, '.loop/missing'))
            ->toBe([]);

        mkdir($this->path.'/.loop/proof/nested', 0700);
        file_put_contents($this->path.'/.loop/proof/nested/deep.txt', "deep\n");
        git($this->path, ['add', '.']);
        git($this->path, ['commit', '--quiet', '-m', 'nested']);

        expect(fn (): array => $repository->directoryBlobs($repository->commit(), '.loop/proof'))
            ->toThrow(InvalidArgumentException::class, 'is not a regular file')
            ->and(fn (): array => $repository->directoryBlobs($commit, '../outside'))
            ->toThrow(InvalidArgumentException::class);
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

    it('reports every material tree change including modes, types, and exact renames', function (): void {
        foreach (['changed.txt', 'mode.txt', 'both.sh', 'deleted.txt', 'renamed-old.txt', 'type.txt'] as $file) {
            file_put_contents($this->path.'/'.$file, "before {$file}\n");
        }
        git($this->path, ['add', '.']);
        git($this->path, ['commit', '--quiet', '-m', 'before']);
        $before = git($this->path, ['rev-parse', 'HEAD']);

        file_put_contents($this->path.'/added.txt', "added\n");
        file_put_contents($this->path.'/changed.txt', "after\n");
        chmod($this->path.'/mode.txt', 0755);
        file_put_contents($this->path.'/both.sh', "after\n");
        chmod($this->path.'/both.sh', 0755);
        unlink($this->path.'/deleted.txt');
        rename($this->path.'/renamed-old.txt', $this->path.'/renamed-new.txt');
        unlink($this->path.'/type.txt');
        symlink('changed.txt', $this->path.'/type.txt');
        git($this->path, ['add', '-A']);
        git($this->path, ['commit', '--quiet', '-m', 'after']);
        $after = git($this->path, ['rev-parse', 'HEAD']);

        expect(new GitRepository($this->path)->changes($before, $after))->toBe([
            ['path' => 'added.txt', 'previous_path' => null, 'change' => 'added'],
            ['path' => 'both.sh', 'previous_path' => null, 'change' => 'content-and-mode-changed'],
            ['path' => 'changed.txt', 'previous_path' => null, 'change' => 'content-changed'],
            ['path' => 'deleted.txt', 'previous_path' => null, 'change' => 'deleted'],
            ['path' => 'mode.txt', 'previous_path' => null, 'change' => 'mode-changed'],
            ['path' => 'renamed-new.txt', 'previous_path' => 'renamed-old.txt', 'change' => 'renamed'],
            ['path' => 'type.txt', 'previous_path' => null, 'change' => 'type-changed'],
        ]);
    });

    it('pins a proved commit across branch replacement until proof release', function (): void {
        file_put_contents($this->path.'/proved.txt', "proved\n");
        git($this->path, ['add', '.']);
        git($this->path, ['commit', '--quiet', '-m', 'proved']);
        $repository = new GitRepository($this->path);
        $proved = $repository->commit();
        $originalBranch = $repository->branch();
        $attempt = new \App\E2E\Value\AttemptId(str_repeat('a', 32));
        $repository->pinProof('AUX-99', $attempt, $proved);

        git($this->path, ['switch', '--orphan', 'replacement']);
        file_put_contents($this->path.'/replacement.txt', "replacement\n");
        git($this->path, ['add', '.']);
        git($this->path, ['commit', '--quiet', '-m', 'replacement']);
        git($this->path, ['branch', '-D', $originalBranch]);

        expect($repository->tree($proved))->toMatch('/\A[0-9a-f]{40}\z/');
        $repository->unpinProof('AUX-99', $attempt);

        expect(fn (): string => $repository->tree($proved))
            ->toThrow(InvalidArgumentException::class, 'not reachable');
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
