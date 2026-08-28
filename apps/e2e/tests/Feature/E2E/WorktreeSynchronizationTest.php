<?php

declare(strict_types=1);

use App\E2E\Git\GitRepository;

function syncGit(string $path, string ...$arguments): string
{
    $command = array_map('escapeshellarg', ['git', '-C', $path, ...$arguments]);
    $output = [];
    $exitCode = 0;
    exec(implode(' ', $command), $output, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException('Fixture Git command failed.');
    }

    return implode("\n", $output);
}

function syncRepository(): string
{
    $path = sys_get_temp_dir().'/orbit-sync-test-'.bin2hex(random_bytes(8));
    mkdir($path, 0700);
    syncGit($path, 'init', '--quiet');
    syncGit($path, 'config', 'user.name', 'Orbit Test');
    syncGit($path, 'config', 'user.email', 'orbit@example.test');
    file_put_contents($path.'/tracked.txt', "original\n");
    file_put_contents($path.'/.gitignore', "ignored.txt\n.env\n");
    syncGit($path, 'add', '.');
    syncGit($path, 'commit', '--quiet', '-m', 'Initial');

    return $path;
}

function removeSyncRepository(string $path): void
{
    new Illuminate\Filesystem\Filesystem()->deleteDirectory($path);
}

describe('worktree source preparation', function () {
    it('creates complete and prerequisite bundles for an exact local commit', function () {
        $path = syncRepository();
        try {
            $repository = new GitRepository($path);
            $base = $repository->commit();
            file_put_contents($path.'/tracked.txt', "second\n");
            syncGit($path, 'commit', '--quiet', '-am', 'Second');
            $head = $repository->commit();
            $complete = $path.'/complete.bundle';
            $incremental = $path.'/incremental.bundle';

            $repository->createBundle($complete, $head);
            $repository->createBundle($incremental, $head, $base);

            expect($repository->isPrerequisite($base, $head))
                ->toBeTrue()
                ->and(filesize($complete))
                ->toBeGreaterThan(0)
                ->and(filesize($incremental))
                ->toBeGreaterThan(0)
                ->and(syncGit($path, 'for-each-ref', '--format=%(refname)', 'refs/orbit/e2e-source'))
                ->toBe('');
        } finally {
            removeSyncRepository($path);
        }
    });

    it('keeps concurrent bundle operation refs isolated and cleans them exactly', function () {
        $first = syncRepository();
        $second = syncRepository();
        try {
            $firstRepository = new GitRepository($first);
            $secondRepository = new GitRepository($second);
            $firstBundle = $first.'/first.bundle';
            $secondBundle = $second.'/second.bundle';

            $firstRepository->createBundle($firstBundle, $firstRepository->commit());
            $secondRepository->createBundle($secondBundle, $secondRepository->commit());

            expect(syncGit($first, 'bundle', 'verify', $firstBundle))
                ->toContain('The bundle records a complete history')
                ->and(syncGit($second, 'bundle', 'verify', $secondBundle))
                ->toContain('The bundle records a complete history')
                ->and(syncGit($first, 'for-each-ref', '--format=%(refname)', 'refs/orbit/e2e-source'))
                ->toBe('')
                ->and(syncGit($second, 'for-each-ref', '--format=%(refname)', 'refs/orbit/e2e-source'))
                ->toBe('');
        } finally {
            removeSyncRepository($first);
            removeSyncRepository($second);
        }
    });

    it('inventories staged, unstaged, deleted, and untracked paths but excludes ignored paths', function () {
        $path = syncRepository();
        try {
            file_put_contents($path.'/deleted.txt', "deleted\n");
            syncGit($path, 'add', 'deleted.txt');
            syncGit($path, 'commit', '--quiet', '-m', 'Tracked deletion fixture');
            unlink($path.'/deleted.txt');
            file_put_contents($path.'/staged.txt', "staged\n");
            syncGit($path, 'add', 'staged.txt');
            file_put_contents($path.'/tracked.txt', "unstaged\n");
            file_put_contents($path.'/untracked.txt', "untracked\n");
            file_put_contents($path.'/ignored.txt', "ignored\n");

            $overlay = new GitRepository($path)->dirtyOverlay();

            expect($overlay?->paths)
                ->toBe(['deleted.txt', 'staged.txt', 'tracked.txt', 'untracked.txt'])
                ->and($overlay?->paths)
                ->not->toContain('ignored.txt');
        } finally {
            removeSyncRepository($path);
        }
    });

    it('rejects unsafe symlinks and secret paths', function (string $pathName) {
        $path = syncRepository();
        try {
            if ($pathName === 'unsafe-link') {
                symlink('/etc/passwd', $path.'/'.$pathName);
            } else {
                file_put_contents($path.'/'.$pathName, 'secret');
            }

            expect(fn () => new GitRepository($path)->dirtyOverlay())
                ->toThrow(InvalidArgumentException::class);
        } finally {
            removeSyncRepository($path);
        }
    })->with(['unsafe-link', 'credentials.json']);

    it('never archives a regular file reached through a symlink parent', function () {
        $path = syncRepository();
        $external = sys_get_temp_dir().'/orbit-archive-external-'.bin2hex(random_bytes(8));
        mkdir($external, 0700);
        file_put_contents($external.'/secret.txt', "outside\n");
        symlink($external, $path.'/linked');
        $archive = $path.'/unsafe.tar';

        try {
            expect(fn () => new GitRepository($path)->createOverlayArchive($archive, ['linked/secret.txt']))
                ->toThrow(InvalidArgumentException::class, 'symlink parent')
                ->and(file_exists($archive))
                ->toBeFalse()
                ->and(file_get_contents($external.'/secret.txt'))
                ->toBe("outside\n");
        } finally {
            removeSyncRepository($path);
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($external);
        }
    });

    it('refuses prior-overlay cleanup through a tracked symlink parent', function () {
        $host = syncRepository();
        $guest = syncRepository();
        $external = sys_get_temp_dir().'/orbit-sync-external-'.bin2hex(random_bytes(8));
        mkdir($external, 0700);
        file_put_contents($external.'/target.txt', "preserve\n");

        try {
            symlink($external, $host.'/linked');
            syncGit($host, 'add', 'linked');
            syncGit($host, 'commit', '--quiet', '-m', 'Track symlink');
            $repository = new GitRepository($host);
            $sha = $repository->commit();
            $bundle = $host.'/source.bundle';
            $archive = $host.'/overlay.tar';
            $manifest = $host.'/overlay.paths';
            $repository->createBundle($bundle, $sha);
            $repository->createOverlayArchive($archive, []);
            file_put_contents($manifest, '');
            syncGit($guest, 'fetch', '--quiet', $host, $sha);
            syncGit($guest, 'reset', '--hard', '--quiet', 'FETCH_HEAD');
            file_put_contents($guest.'/.git/orbit-overlay.paths', "linked/target.txt\0");

            $script = dirname(__DIR__, 3).'/resources/guest/receive-source.sh';
            $command = array_map('escapeshellarg', [
                'bash',
                $script,
                $guest,
                $sha,
                $bundle,
                $archive,
                $manifest,
                $repository->effectiveTreeHash(),
            ]);
            $output = [];
            $exitCode = 0;
            exec(implode(' ', $command), $output, $exitCode);

            expect($exitCode)
                ->toBe(65)
                ->and(file_get_contents($external.'/target.txt'))
                ->toBe("preserve\n");
        } finally {
            removeSyncRepository($host);
            removeSyncRepository($guest);
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($external);
        }
    });
});
