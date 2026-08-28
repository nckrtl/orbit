<?php

declare(strict_types=1);

use App\E2E\Git\GitRepository;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;

/** @property string $path */
describe('GitRepository', function (): void {
    beforeEach(function (): void {
        configureProcessFacade();
        $this->path = sys_get_temp_dir().'/orbit-git-'.bin2hex(random_bytes(6));
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
