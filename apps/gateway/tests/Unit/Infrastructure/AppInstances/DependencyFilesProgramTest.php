<?php

declare(strict_types=1);

use App\Infrastructure\AppInstances\DependencyFilesProgram;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

function dependency_source_directory(): string
{
    $path = sys_get_temp_dir().'/orbit-dependency-files-'.Str::uuid();
    mkdir($path, 0o700);

    return $path;
}

/** @return array<string, mixed> */
function dependency_program(string $path, string $environment = 'development', ?string $program = null): array
{
    $process = new Process(['/usr/bin/python3', '-I', '-', $environment, $path], timeout: 10);
    $process->setInput($program ?? DependencyFilesProgram::render());
    $process->mustRun();
    expect($process->getErrorOutput())->toBeEmpty();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

describe('bounded dependency file reading', function (): void {
    it('reads only root files without installed trees or project execution', function (): void {
        $root = dependency_source_directory();
        $manifest = '{"scripts":{"install":"touch NEVER"}}';
        file_put_contents($root.'/package.json', $manifest);
        file_put_contents($root.'/pnpm-lock.yaml', 'lockfileVersion: 9.0');
        mkdir($root.'/public');
        file_put_contents($root.'/public/composer.json', 'not the source');
        file_put_contents($root.'/.pnpmfile.cjs', 'throw new Error("never execute")');

        try {
            $result = dependency_program($root);

            expect($result['root'])->toBe($root);
            expect($result['files']['package.json'])->toBe(['content' => base64_encode($manifest), 'hash' => hash('sha256', $manifest), 'error' => null]);
            expect($result['files']['composer.json'])->toBe(['content' => null, 'hash' => null, 'error' => null]);
            expect($result['files']['.pnpmfile.cjs']['hash'])->toBe(hash('sha256', 'throw new Error("never execute")'));
            expect(is_dir($root.'/vendor'))->toBeFalse();
            expect(is_dir($root.'/node_modules'))->toBeFalse();
            expect(file_exists($root.'/NEVER'))->toBeFalse();
            expect(dependency_program($root))->toBe($result);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('follows only the selected production release', function (): void {
        $root = dependency_source_directory();
        mkdir($root.'/releases/selected', 0o700, true);
        mkdir($root.'/releases/old');
        file_put_contents($root.'/releases/selected/composer.json', '{}');
        file_put_contents($root.'/releases/old/composer.json', 'old');
        symlink('releases/selected', $root.'/current');

        try {
            $result = dependency_program($root, 'production');

            expect($result['root'])->toBe($root.'/releases/selected');
            expect($result['reference'])->toBe('selected');
            expect($result['files']['composer.json']['content'])->toBe(base64_encode('{}'));
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('fails without an active production release', function (): void {
        $root = dependency_source_directory();
        try {
            expect(dependency_program($root, 'production'))->toBe(['error' => 'dependencies.source_unavailable']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('rejects unsafe root files and distinguishes oversized and unreadable files', function (string $kind, string $error): void {
        $root = dependency_source_directory();
        $path = $root.'/composer.json';
        match ($kind) {
            'symlink' => symlink('/etc/passwd', $path),
            'dangling' => symlink($root.'/missing', $path),
            'directory' => mkdir($path),
            'fifo' => posix_mkfifo($path, 0o600),
            'oversized' => file_put_contents($path, str_repeat('a', 1024 * 1024 + 1)),
            'unreadable' => file_put_contents($path, '{}'),
        };
        if ($kind === 'unreadable') {
            chmod($path, 0);
        }
        try {
            $result = dependency_program($root);

            expect($result['files']['composer.json'])->toBe(['content' => null, 'hash' => null, 'error' => $error]);
            expect($result['files']['package.json']['error'])->toBeNull();
        } finally {
            if ($kind === 'fifo') {
                unlink($path);
            }
            new Filesystem()->deleteDirectory($root);
        }
    })->with([
        ['symlink', 'dependencies.unsafe_source'], ['dangling', 'dependencies.unsafe_source'],
        ['directory', 'dependencies.unsafe_source'], ['fifo', 'dependencies.unsafe_source'],
        ['oversized', 'dependencies.source_too_large'], ['unreadable', 'dependencies.unreadable_source'],
    ]);

    it('rejects path escapes and symlink ancestors', function (string $kind): void {
        $root = dependency_source_directory();
        mkdir($root.'/actual');
        symlink($root.'/actual', $root.'/alias');
        $path = $kind === 'parent' ? $root.'/actual/..' : $root.'/alias';
        try {
            expect(dependency_program($path))->toBe(['error' => 'dependencies.unsafe_source']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    })->with(['parent', 'symlink']);

    it('rejects an escaped production selection', function (): void {
        $root = dependency_source_directory();
        symlink('/tmp', $root.'/current');
        try {
            expect(dependency_program($root, 'production'))->toBe(['error' => 'dependencies.unsafe_source']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('detects file and directory changes between collection passes', function (string $change): void {
        $root = dependency_source_directory();
        file_put_contents($root.'/composer.json', '{}');
        $mutation = match ($change) {
            'contents' => "open(os.path.join(root, 'composer.json'), 'w').write('[]')",
            'missing' => "open(os.path.join(root, 'package.json'), 'w').write('{}')",
            'removed' => "os.unlink(os.path.join(root, 'composer.json'))",
            'directory' => "os.rename(root, root + '.old'); os.mkdir(root)",
        };
        $program = str_replace('        again = ', '        '.$mutation."\n        again = ", DependencyFilesProgram::render());
        try {
            expect(dependency_program($root, program: $program))->toBe(['error' => 'dependencies.source_changed']);
        } finally {
            new Filesystem()->deleteDirectory($root);
            new Filesystem()->deleteDirectory($root.'.old');
        }
    })->with(['contents', 'missing', 'removed', 'directory']);

    it('detects a production pointer switch during collection', function (): void {
        $root = dependency_source_directory();
        mkdir($root.'/releases/one', 0o700, true);
        mkdir($root.'/releases/two');
        symlink('releases/one', $root.'/current');
        $program = str_replace('        again = ', "        os.unlink(os.path.join(path, 'current')); os.symlink('releases/two', os.path.join(path, 'current'))\n        again = ", DependencyFilesProgram::render());
        try {
            expect(dependency_program($root, 'production', $program))->toBe(['error' => 'dependencies.source_changed']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('enforces the lockfile size boundary', function (bool $oversized): void {
        $root = dependency_source_directory();
        $contents = str_repeat('x', 8 * 1024 * 1024 + (int) $oversized);
        file_put_contents($root.'/composer.lock', $contents);
        try {
            $result = dependency_program($root);

            expect($result['files']['composer.lock']['error'])->toBe($oversized ? 'dependencies.source_too_large' : null);
            expect($result['files']['composer.lock']['hash'])->toBe($oversized ? null : hash('sha256', $contents));
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    })->with([false, true]);

    it('bounds total collected bytes', function (): void {
        $root = dependency_source_directory();
        foreach (['composer.lock', 'npm-shrinkwrap.json', 'package-lock.json', 'pnpm-lock.yaml', 'bun.lock'] as $name) {
            file_put_contents($root.'/'.$name, str_repeat('x', 8 * 1024 * 1024));
        }
        try {
            expect(dependency_program($root))->toBe(['error' => 'dependencies.source_too_large']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });
});
