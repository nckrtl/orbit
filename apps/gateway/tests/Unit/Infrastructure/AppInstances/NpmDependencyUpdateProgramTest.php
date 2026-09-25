<?php

declare(strict_types=1);

use App\Infrastructure\AppInstances\NpmDependencyUpdateProgram;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessCancelledException;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProtectedInput;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

function npm_update_program_directory(string $suffix = ''): string
{
    $path = sys_get_temp_dir().'/orbit-npm-update-program-'.Str::uuid().$suffix;
    mkdir($path, 0o700);

    return $path;
}

function npm_update_program_vp(string $body): string
{
    $directory = npm_update_program_directory('-bin');
    file_put_contents($directory.'/vp', "#!/usr/bin/env bash\nset -eu\n[ \$# -eq 2 ] && [ \"\$1\" = update ] && [ \"\$2\" = --no-save ] || exit 9\n".$body);
    chmod($directory.'/vp', 0o700);

    return $directory.'/vp';
}

function npm_update_program_run(string $root, string $vp, string $deadline, ?Closure $cancelled, float $timeout): CommandResult
{
    return new NativeProcessRunner()->run(new ProcessInvocation(
        arguments: [
            '/usr/bin/setsid',
            '--wait',
            '/usr/bin/bash',
            '-eu',
            '-c',
            NpmDependencyUpdateProgram::render(),
            'npm-update',
            $root,
            $vp,
            $deadline,
        ],
        timeout: $timeout,
        protectedInput: ProtectedInput::holdOpen(),
        cancelled: $cancelled,
    ));
}

describe('npm update supervisor', function (): void {
    it('stops delayed Vite+ hooks after cancellation', function (): void {
        $root = npm_update_program_directory();
        $vp = npm_update_program_vp("printf start > start-marker\nsleep 5\nprintf later > later-marker\n");

        try {
            $started = microtime(true);
            $cancelled = false;

            try {
                npm_update_program_run(
                    $root,
                    $vp,
                    '30',
                    function () use (&$cancelled, $started, $root): bool {
                        if ($cancelled) {
                            return true;
                        }

                        if (is_file($root.'/start-marker') || microtime(true) - $started >= 2.0) {
                            $cancelled = true;

                            return true;
                        }

                        return false;
                    },
                    15.0,
                );
                $this->fail('The cancelled npm supervisor unexpectedly completed.');
            } catch (ProcessCancelledException) {
            }

            $deadline = microtime(true) + 4.0;
            while (microtime(true) < $deadline && ! is_file($root.'/later-marker')) {
                usleep(50_000);
            }

            expect(is_file($root.'/start-marker'))->toBeTrue();
            expect(is_file($root.'/later-marker'))->toBeFalse();
        } finally {
            new Filesystem()->deleteDirectory($root);
            new Filesystem()->deleteDirectory(dirname($vp));
        }
    });

    it('stops delayed Vite+ hooks when the remote deadline expires', function (): void {
        $root = npm_update_program_directory();
        $vp = npm_update_program_vp("printf start > start-marker\nsleep 5\nprintf later > later-marker\n");

        try {
            $result = npm_update_program_run($root, $vp, '1', null, 15.0);

            expect($result->exitCode)->toBe(124);
            expect(is_file($root.'/start-marker'))->toBeTrue();
            expect(is_file($root.'/later-marker'))->toBeFalse();
        } finally {
            new Filesystem()->deleteDirectory($root);
            new Filesystem()->deleteDirectory(dirname($vp));
        }
    });

    it('runs vp update --no-save in a recorded root that contains a space', function (): void {
        $root = npm_update_program_directory(' spaced');
        $vp = npm_update_program_vp("printf done > done-marker\n");

        try {
            expect($root)->toContain(' ');

            $result = npm_update_program_run($root, $vp, '30', null, 30.0);

            expect($result->exitCode)->toBe(0);
            expect(is_file($root.'/done-marker'))->toBeTrue();
        } finally {
            new Filesystem()->deleteDirectory($root);
            new Filesystem()->deleteDirectory(dirname($vp));
        }
    });

    it('propagates a failed Vite+ exit status', function (): void {
        $root = npm_update_program_directory();
        $vp = npm_update_program_vp("exit 3\n");

        try {
            $result = npm_update_program_run($root, $vp, '30', null, 15.0);

            expect($result->exitCode)->toBe(3);
        } finally {
            new Filesystem()->deleteDirectory($root);
            new Filesystem()->deleteDirectory(dirname($vp));
        }
    });

    it('does not substitute a raw npm command', function (): void {
        expect(NpmDependencyUpdateProgram::render())->toContain('"$vp" update --no-save');
        expect(NpmDependencyUpdateProgram::render())->toContain('--no-save');
        expect(NpmDependencyUpdateProgram::render())->toContain('export VP_HOME=/opt/orbit/vite-plus');
        expect(NpmDependencyUpdateProgram::render())->not->toContain('/usr/bin/npm');
        expect(NpmDependencyUpdateProgram::render())->not->toContain('--latest');
        expect(NpmDependencyUpdateProgram::render())->not->toContain('--dev');
        expect(NpmDependencyUpdateProgram::render())->not->toContain('--prod');
    });

    it('records vp update --no-save as the only child arguments', function (): void {
        $root = npm_update_program_directory();
        $vp = npm_update_program_vp("printf '%s\\n' \"\$*\" > argv-marker\n");

        try {
            $result = npm_update_program_run($root, $vp, '30', null, 15.0);

            expect($result->exitCode)->toBe(0);
            expect(trim((string) file_get_contents($root.'/argv-marker')))->toBe('update --no-save');
        } finally {
            new Filesystem()->deleteDirectory($root);
            new Filesystem()->deleteDirectory(dirname($vp));
        }
    });

    it('rejects a relative root and a missing Vite+ binary before starting', function (): void {
        $vp = npm_update_program_vp("exit 0\n");

        try {
            expect(npm_update_program_run('relative/root', $vp, '30', null, 5.0)->exitCode)->toBe(1);
            expect(npm_update_program_run(sys_get_temp_dir(), $vp.'-missing', '30', null, 5.0)->exitCode)->toBe(1);
        } finally {
            new Filesystem()->deleteDirectory(dirname($vp));
        }
    });
});
