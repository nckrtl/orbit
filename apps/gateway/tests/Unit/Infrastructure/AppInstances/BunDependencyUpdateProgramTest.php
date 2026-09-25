<?php

declare(strict_types=1);

use App\Infrastructure\AppInstances\BunDependencyUpdateProgram;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessCancelledException;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProtectedInput;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Tests\Support\TestToolchain;

function bun_update_program_directory(string $suffix = ''): string
{
    $path = sys_get_temp_dir().'/orbit-bun-update-program-'.Str::uuid().$suffix;
    mkdir($path, 0o700);

    return $path;
}

function bun_update_program_vp(string $body): string
{
    $directory = bun_update_program_directory('-bin');
    file_put_contents($directory.'/vp', "#!/usr/bin/env bash\nset -eu\n[ \$# -eq 5 ] && [ \"\$1\" = update ] && [ \"\$2\" = --no-save ] && [ \"\$3\" = -- ] && [ \"\$4\" = --lockfile-only ] && [ \"\$5\" = --save-text-lockfile ] || exit 9\n".$body);
    chmod($directory.'/vp', 0o700);

    return $directory.'/vp';
}

function bun_update_program_run(string $root, string $vp, string $deadline, ?Closure $cancelled, float $timeout): CommandResult
{
    TestToolchain::requireLinux('The supervisor runs /usr/bin/setsid and /usr/bin/bash and watches its owner through /proc.');

    return new NativeProcessRunner()->run(new ProcessInvocation(
        arguments: [
            '/usr/bin/setsid',
            '--wait',
            '/usr/bin/bash',
            '-eu',
            '-c',
            BunDependencyUpdateProgram::render(),
            'bun-update',
            $root,
            $vp,
            $deadline,
        ],
        timeout: $timeout,
        protectedInput: ProtectedInput::holdOpen(),
        cancelled: $cancelled,
    ));
}

describe('bun update supervisor', function (): void {
    it('stops delayed Vite+ hooks after cancellation', function (): void {
        $root = bun_update_program_directory();
        $vp = bun_update_program_vp("printf start > start-marker\nsleep 5\nprintf later > later-marker\n");

        try {
            $started = microtime(true);
            $cancelled = false;

            try {
                bun_update_program_run(
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
                $this->fail('The cancelled bun supervisor unexpectedly completed.');
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
        $root = bun_update_program_directory();
        $vp = bun_update_program_vp("printf start > start-marker\nsleep 5\nprintf later > later-marker\n");

        try {
            $result = bun_update_program_run($root, $vp, '1', null, 15.0);

            expect($result->exitCode)->toBe(124);
            expect(is_file($root.'/start-marker'))->toBeTrue();
            expect(is_file($root.'/later-marker'))->toBeFalse();
        } finally {
            new Filesystem()->deleteDirectory($root);
            new Filesystem()->deleteDirectory(dirname($vp));
        }
    });

    it('runs vp update --no-save in a recorded root that contains a space', function (): void {
        $root = bun_update_program_directory(' spaced');
        $vp = bun_update_program_vp("printf done > done-marker\n");

        try {
            expect($root)->toContain(' ');

            $result = bun_update_program_run($root, $vp, '30', null, 30.0);

            expect($result->exitCode)->toBe(0);
            expect(is_file($root.'/done-marker'))->toBeTrue();
        } finally {
            new Filesystem()->deleteDirectory($root);
            new Filesystem()->deleteDirectory(dirname($vp));
        }
    });

    it('propagates a failed Vite+ exit status', function (): void {
        $root = bun_update_program_directory();
        $vp = bun_update_program_vp("exit 3\n");

        try {
            $result = bun_update_program_run($root, $vp, '30', null, 15.0);

            expect($result->exitCode)->toBe(3);
        } finally {
            new Filesystem()->deleteDirectory($root);
            new Filesystem()->deleteDirectory(dirname($vp));
        }
    });

    it('does not substitute a raw bun command', function (): void {
        expect(BunDependencyUpdateProgram::render())->toContain('"$vp" update --no-save -- --lockfile-only --save-text-lockfile');
        expect(BunDependencyUpdateProgram::render())->toContain('--no-save');
        expect(BunDependencyUpdateProgram::render())->toContain('export VP_HOME=/opt/orbit/vite-plus');
        expect(BunDependencyUpdateProgram::render())->not->toContain('/usr/bin/bun');
        expect(BunDependencyUpdateProgram::render())->not->toContain('--latest');
        expect(BunDependencyUpdateProgram::render())->not->toContain('--dev');
        expect(BunDependencyUpdateProgram::render())->not->toContain('--prod');
    });

    it('records the verified bun pass-through as the child arguments', function (): void {
        $root = bun_update_program_directory();
        $vp = bun_update_program_vp("printf '%s\\n' \"\$*\" > argv-marker\n");

        try {
            $result = bun_update_program_run($root, $vp, '30', null, 15.0);

            expect($result->exitCode)->toBe(0);
            expect(trim((string) file_get_contents($root.'/argv-marker')))->toBe('update --no-save -- --lockfile-only --save-text-lockfile');
        } finally {
            new Filesystem()->deleteDirectory($root);
            new Filesystem()->deleteDirectory(dirname($vp));
        }
    });

    it('rejects a relative root and a missing Vite+ binary before starting', function (): void {
        $vp = bun_update_program_vp("exit 0\n");

        try {
            expect(bun_update_program_run('relative/root', $vp, '30', null, 5.0)->exitCode)->toBe(1);
            expect(bun_update_program_run(sys_get_temp_dir(), $vp.'-missing', '30', null, 5.0)->exitCode)->toBe(1);
        } finally {
            new Filesystem()->deleteDirectory(dirname($vp));
        }
    });
});
