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

/** @return array{output:string,error:string,exitCode:int} */
function runReceiveSource(string ...$arguments): array
{
    $process = new Symfony\Component\Process\Process([
        'bash',
        dirname(__DIR__, 3).'/resources/guest/receive-source.sh',
        ...$arguments,
    ]);
    $process->run();

    return [
        'output' => $process->getOutput(),
        'error' => $process->getErrorOutput(),
        'exitCode' => $process->getExitCode() ?? -1,
    ];
}

describe('worktree source preparation', function () {
    it('removes the temporary index when effective tree construction fails', function () {
        $host = syncRepository();
        $guest = sys_get_temp_dir().'/orbit-sync-guest-'.bin2hex(random_bytes(8));
        mkdir($guest, 0700);
        $transfer = sys_get_temp_dir().'/orbit-transfer-'.bin2hex(random_bytes(8));
        mkdir($transfer, 0700);
        try {
            $repository = new GitRepository($host);
            $sha = $repository->commit();
            $bundle = $transfer.'/source.bundle';
            $repository->createBundle($bundle, $sha);
            file_put_contents($transfer.'/blocked', "blocked\n");
            new Symfony\Component\Process\Process([
                'tar',
                '--mode=000',
                '-cf',
                $transfer.'/bad.tar',
                '-C',
                $transfer,
                'blocked',
            ])->mustRun();
            $manifest = $transfer.'/manifest';
            file_put_contents($manifest, "blocked\0");
            $deletions = $transfer.'/deletions';
            file_put_contents($deletions, '');
            $tmp = $transfer.'/tmp';
            mkdir($tmp, 0700);
            $old = getenv('TMPDIR');
            putenv('TMPDIR='.$tmp);
            try {
                $result = runReceiveSource(
                    $guest,
                    $sha,
                    $bundle,
                    $transfer.'/bad.tar',
                    $manifest,
                    $deletions,
                    $repository->effectiveTreeHash(),
                );
            } finally {
                putenv($old === false ? 'TMPDIR' : 'TMPDIR='.$old);
            }
            expect($result['exitCode'])
                ->not
                ->toBe(0)
                ->and($result['error'])
                ->toContain('unable to index file')
                ->and(file_exists($guest.'/blocked'))
                ->toBeTrue()
                ->and(fileperms($guest.'/blocked') & 0777)
                ->toBe(0)
                ->and(scandir($tmp))
                ->toBe(['.', '..']);
        } finally {
            if (file_exists($guest.'/blocked'))
                chmod($guest.'/blocked', 0600);
            removeSyncRepository($host);
            removeSyncRepository($guest);
            removeSyncRepository($transfer);
        }
    });

    it('receives a bundle and replaces a dirty overlay with verified tree evidence', function () {
        $host = syncRepository();
        $guest = sys_get_temp_dir().'/orbit-sync-guest-'.bin2hex(random_bytes(8));
        mkdir($guest, 0700);

        try {
            file_put_contents($host.'/deleted.txt', "remove me\n");
            syncGit($host, 'add', 'deleted.txt');
            syncGit($host, 'commit', '--quiet', '-m', 'Overlay base');
            unlink($host.'/deleted.txt');
            file_put_contents($host.'/tracked.txt', "overlay\n");
            file_put_contents($host.'/untracked.txt', "new\n");

            $repository = new GitRepository($host);
            $overlay = $repository->dirtyOverlay();
            $expectedTree = $overlay?->treeHash ?? $repository->effectiveTreeHash();
            $sha = $repository->commit();
            $transfer = sys_get_temp_dir().'/orbit-transfer-'.bin2hex(random_bytes(8));
            mkdir($transfer, 0700);
            $bundle = $transfer.'/source.bundle';
            $archive = $transfer.'/overlay.tar';
            $manifest = $transfer.'/overlay.paths';
            $deletions = $transfer.'/overlay.deletions';
            $repository->createBundle($bundle, $sha);
            $repository->createOverlayArchive($archive, $overlay?->paths ?? []);
            file_put_contents($manifest, implode("\0", $overlay?->paths ?? [])."\0");
            file_put_contents(
                $deletions,
                implode(
                    "\0",
                    array_values(array_filter(
                        $overlay?->paths ?? [],
                        fn (string $path): bool => ! file_exists($host.'/'.$path),
                    )),
                )
                    ."\0",
            );

            $result = runReceiveSource($guest, $sha, $bundle, $archive, $manifest, $deletions, $expectedTree);
            expect($result['exitCode'])->toBe(0, $result['output']);
            $evidence = json_decode($result['output'], true, 16, JSON_THROW_ON_ERROR);

            expect($evidence)
                ->toEqual(['sha' => $sha, 'tree_hash' => $expectedTree])
                ->and(new GitRepository($guest)->effectiveTreeHash())
                ->toBe($expectedTree)
                ->and(file_exists($guest.'/deleted.txt'))
                ->toBeFalse()
                ->and(file_get_contents($guest.'/tracked.txt'))
                ->toBe("overlay\n")
                ->and(file_get_contents($guest.'/untracked.txt'))
                ->toBe("new\n");

            unlink($host.'/untracked.txt');
            file_put_contents($host.'/second.txt', "second\n");
            $overlay = $repository->dirtyOverlay();
            $repository->createOverlayArchive($archive, $overlay?->paths ?? []);
            file_put_contents($manifest, implode("\0", $overlay?->paths ?? [])."\0");
            $expectedTree = $repository->effectiveTreeHash();
            $result = runReceiveSource($guest, $sha, $bundle, $archive, $manifest, $deletions, $expectedTree);

            expect($result['exitCode'])
                ->toBe(0)
                ->and(file_exists($guest.'/untracked.txt'))
                ->toBeFalse()
                ->and(file_get_contents($guest.'/second.txt'))
                ->toBe("second\n");
        } finally {
            removeSyncRepository($host);
            removeSyncRepository($guest);
            if (isset($transfer)) {
                removeSyncRepository($transfer);
            }
        }
    });

    it('receives an overlay without a bundle only when the guest head still matches', function () {
        $host = syncRepository();
        $guest = sys_get_temp_dir().'/orbit-sync-guest-'.bin2hex(random_bytes(8));
        mkdir($guest, 0700);
        $transfer = sys_get_temp_dir().'/orbit-transfer-'.bin2hex(random_bytes(8));
        mkdir($transfer, 0700);

        try {
            $repository = new GitRepository($host);
            $sha = $repository->commit();
            $archive = $transfer.'/overlay.tar';
            $manifest = $transfer.'/overlay.paths';
            $deletions = $transfer.'/overlay.deletions';
            $repository->createOverlayArchive($archive, []);
            file_put_contents($manifest, '');
            file_put_contents($deletions, '');
            syncGit($guest, 'init', '--quiet');
            syncGit($guest, 'fetch', '--quiet', $host, $sha);
            syncGit($guest, 'reset', '--hard', '--quiet', 'FETCH_HEAD');

            $result = runReceiveSource(
                $guest,
                $sha,
                '-',
                $archive,
                $manifest,
                $deletions,
                $repository->effectiveTreeHash(),
            );
            $staleResult = runReceiveSource(
                $guest,
                str_repeat('f', 40),
                '-',
                $archive,
                $manifest,
                $deletions,
                $repository->effectiveTreeHash(),
            );

            expect($result['exitCode'])
                ->toBe(0, $result['error'])
                ->and(json_decode($result['output'], true, 16, JSON_THROW_ON_ERROR))
                ->toEqual(['sha' => $sha, 'tree_hash' => $repository->effectiveTreeHash()])
                ->and($staleResult['exitCode'])
                ->toBe(65);
        } finally {
            removeSyncRepository($host);
            removeSyncRepository($guest);
            removeSyncRepository($transfer);
        }
    });

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

            expect($repository->hasCommit($base))
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

    it('creates a complete bundle when the prerequisite matches the commit', function () {
        $path = syncRepository();
        try {
            $repository = new GitRepository($path);
            $commit = $repository->commit();
            $bundle = $path.'/same-commit.bundle';

            $repository->createBundle($bundle, $commit, $commit);

            expect(syncGit($path, 'bundle', 'verify', $bundle))
                ->toContain('The bundle records a complete history')
                ->and(filesize($bundle))
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

    it('preserves ignored dependency directories while cleaning the checkout', function () {
        $host = syncRepository();
        $guest = sys_get_temp_dir().'/orbit-sync-guest-'.bin2hex(random_bytes(8));
        mkdir($guest, 0700);
        $transfer = sys_get_temp_dir().'/orbit-transfer-'.bin2hex(random_bytes(8));
        mkdir($transfer, 0700);
        try {
            file_put_contents($host.'/.gitignore', "ignored.txt\nvendor/\n");
            syncGit($host, 'add', '.gitignore');
            syncGit($host, 'commit', '--quiet', '-m', 'Ignore dependencies');
            $repository = new GitRepository($host);
            $sha = $repository->commit();
            $bundle = $transfer.'/source.bundle';
            $archive = $transfer.'/overlay.tar';
            $manifest = $transfer.'/manifest';
            $deletions = $transfer.'/deletions';
            $repository->createBundle($bundle, $sha);
            $repository->createOverlayArchive($archive, []);
            file_put_contents($manifest, '');
            file_put_contents($deletions, '');
            mkdir($guest.'/vendor/package', 0700, true);
            file_put_contents($guest.'/vendor/package/installed.php', "dependency\n");

            $result = runReceiveSource(
                $guest,
                $sha,
                $bundle,
                $archive,
                $manifest,
                $deletions,
                $repository->effectiveTreeHash(),
            );

            expect($result['exitCode'])
                ->toBe(0, $result['error'])
                ->and(file_get_contents($guest.'/vendor/package/installed.php'))
                ->toBe("dependency\n");
        } finally {
            removeSyncRepository($host);
            removeSyncRepository($guest);
            removeSyncRepository($transfer);
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
