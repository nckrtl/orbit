<?php

declare(strict_types=1);

use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use Symfony\Component\Process\Process;

describe('AtomicJsonStore', function () {
    it('writes validated JSON with private permissions and reads it', function () {
        $paths = new StatePaths(temporaryPath('orbit-json-', 4));
        $store = new AtomicJsonStore($paths);
        $store->write('nested/state.json', ['answer' => 42]);

        expect($store->read('nested/state.json'))
            ->toBe(['answer' => 42])
            ->and(fileperms($paths->path('nested/state.json')) & 0777)
            ->toBe(0600)
            ->and(fileperms(dirname($paths->path('nested/state.json'))) & 0777)
            ->toBe(0700);
    });

    it('keeps a named user grant effective on state written below a default ACL', function () {
        $base = temporaryPath('orbit-json-acl-', 4);
        mkdir($base, 0700, true);
        $worker = (string) (posix_geteuid() + 1);
        (new Process(['setfacl', '--modify', 'user:'.$worker.':rwx,mask::rwx,default:user:'.$worker.':rwx,default:mask::rwx', $base]))->mustRun();
        $paths = new StatePaths($base);
        $store = new AtomicJsonStore($paths);
        $store->write('nested/state.json', ['answer' => 42]);
        $store->write('nested/state.json', ['answer' => 43]);

        foreach ([$paths->path('nested/state.json'), dirname($paths->path('nested/state.json'))] as $path) {
            $acl = (new Process(['getfacl', '--omit-header', '--numeric', $path]))->mustRun()->getOutput();
            expect($acl)->toContain('user:'.$worker.':rw')
                ->and($acl)->not->toContain('mask::---')
                ->and($acl)->toContain('group::---')
                ->and($acl)->toContain('other::---')
                ->and($acl)->not->toContain('#effective:---');
        }
        expect($store->read('nested/state.json'))->toBe(['answer' => 43]);
    });

    it('preserves old bytes and removes temporary files for each injected pre-rename failure', function (string $phase) {
        $paths = new StatePaths(temporaryPath('orbit-json-', 4));
        $initial = new AtomicJsonStore($paths);
        $initial->write('state.json', ['version' => 1]);
        $before = file_get_contents($paths->path('state.json'));
        $failing = new AtomicJsonStore($paths, function (string $current) use ($phase): void {
            if ($current === $phase) {
                throw new RuntimeException('injected failure');
            }
        });

        expect(fn () => $failing->write('state.json', ['version' => 2]))
            ->toThrow(RuntimeException::class, 'injected failure')
            ->and(file_get_contents($paths->path('state.json')))
            ->toBe($before)
            ->and(glob($paths->root().'/.state-*'))
            ->toBe([]);
    })->with(['after_temporary_write', 'before_rename']);

    it('rejects malformed persisted JSON', function () {
        $paths = new StatePaths(temporaryPath('orbit-json-', 4));
        file_put_contents($paths->path('broken.json'), '{broken');

        expect(fn () => new AtomicJsonStore($paths)->read('broken.json'))
            ->toThrow(RuntimeException::class, 'malformed');
    });

    it('reports a post-rename permission failure while retaining the committed JSON', function () {
        $paths = new StatePaths(temporaryPath('orbit-json-', 4));
        $store = new AtomicJsonStore($paths, fn (string $phase): ?bool => $phase === 'post_rename_chmod'
            ? false
            : null);

        expect(fn () => $store->write('state.json', ['version' => 2]))
            ->toThrow(RuntimeException::class, 'was committed')
            ->and(new AtomicJsonStore($paths)->read('state.json'))
            ->toBe(['version' => 2])
            ->and(glob($paths->root().'/.state-*'))
            ->toBeEmpty();
    });

    it('deletes an existing state file and is idempotent when absent', function () {
        $paths = new StatePaths(temporaryPath('orbit-json-', 4));
        $store = new AtomicJsonStore($paths);
        $store->write('topology-snapshot/corrupt.json', ['reason' => 'recovery']);

        $store->delete('topology-snapshot/corrupt.json');
        $store->delete('topology-snapshot/corrupt.json');

        expect(file_exists($paths->path('topology-snapshot/corrupt.json')))->toBeFalse();
    });

    it('rejects unsafe existing deletion targets', function (string $target, callable $prepare, string $message): void {
        $paths = new StatePaths(temporaryPath('orbit-json-', 4));
        $prepare($paths->path($target), $paths);

        expect(fn () => new AtomicJsonStore($paths)->delete($target))
            ->toThrow($message);
    })->with([
        'directory' => [
            'topology-snapshot/corrupt.json',
            function (string $path): void {
                mkdir($path, 0700, true);
            },
            'unsafe',
        ],
        'symlink' => [
            'topology-snapshot/corrupt.json',
            function (string $path): void {
                mkdir(dirname($path), 0700, true);
                symlink('/tmp', $path);
            },
            'symbolic link',
        ],
    ]);
});
