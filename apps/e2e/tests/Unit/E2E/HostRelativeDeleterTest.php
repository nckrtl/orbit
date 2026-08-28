<?php

declare(strict_types=1);

use App\E2E\HostRelativeDeleter;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;

beforeEach(function (): void {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
});

describe('descriptor-relative host deletion', function (): void {
    it('rejects a parent replaced with a symlink without touching outside the safe root', function (): void {
        $root = sys_get_temp_dir().'/orbit-safe-root-'.bin2hex(random_bytes(5));
        $safe = $root.'/safe';
        $outside = $root.'/outside';
        mkdir($safe.'/parent', 0700, true);
        mkdir($outside, 0700);
        file_put_contents($outside.'/secret.json', 'protected');
        rmdir($safe.'/parent');
        symlink($outside, $safe.'/parent');

        expect(
            fn () => new HostRelativeDeleter(dirname(__DIR__, 3).'/resources/host/delete-relative.py')->delete(
                'manifests',
                $safe,
                $safe.'/parent/secret.json',
            ),
        )
            ->toThrow(RuntimeException::class, 'parent');
        expect(is_file($outside.'/secret.json'))->toBeTrue();

        unlink($safe.'/parent');
        rmdir($safe);
        unlink($outside.'/secret.json');
        rmdir($outside);
        rmdir($root);
    });

    it('does not delete a final symbolic link', function (): void {
        $root = sys_get_temp_dir().'/orbit-safe-root-'.bin2hex(random_bytes(5));
        mkdir($root.'/files', 0700, true);
        $outside = $root.'/outside.json';
        file_put_contents($outside, 'protected');
        symlink($outside, $root.'/files/manifest.json');

        expect(
            fn () => new HostRelativeDeleter(dirname(__DIR__, 3).'/resources/host/delete-relative.py')->delete(
                'manifests',
                $root,
                $root.'/files/manifest.json',
            ),
        )
            ->toThrow(RuntimeException::class, 'symbolic link');
        expect(is_file($outside))
            ->toBeTrue()
            ->and(is_link($root.'/files/manifest.json'))
            ->toBeTrue();

        unlink($root.'/files/manifest.json');
        unlink($outside);
        rmdir($root.'/files');
        rmdir($root);
    });

    it('deletes only the expected regular file or directory', function (): void {
        $root = sys_get_temp_dir().'/orbit-safe-root-'.bin2hex(random_bytes(5));
        mkdir($root.'/files', 0700, true);
        mkdir($root.'/source', 0700);
        file_put_contents($root.'/files/manifest.json', '{}');
        $deleter = new HostRelativeDeleter(dirname(__DIR__, 3).'/resources/host/delete-relative.py');

        $deleter->delete('manifests', $root, $root.'/files/manifest.json');
        $deleter->delete('source_paths', $root, $root.'/source');

        expect(is_file($root.'/files/manifest.json'))
            ->toBeFalse()
            ->and(is_dir($root.'/source'))
            ->toBeFalse();
        rmdir($root.'/files');
        rmdir($root);
    });

    it('reports an unavailable helper before attempting deletion', function (): void {
        expect(
            fn () => new HostRelativeDeleter('/missing/delete-relative.py')->delete(
                'locks',
                '/tmp',
                '/tmp/lock',
            ),
        )
            ->toThrow(RuntimeException::class, 'helper is unavailable');
    });
});
