<?php

declare(strict_types=1);

use App\Infrastructure\AppInstances\ComposerDependencyUpdateProgram;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessCancelledException;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProtectedInput;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

function composer_update_program_directory(string $suffix = ''): string
{
    $path = sys_get_temp_dir().'/orbit-composer-update-program-'.Str::uuid().$suffix;
    mkdir($path, 0o700);

    return $path;
}

function composer_update_program_fixture(string $path, string $preUpdate): void
{
    file_put_contents($path.'/composer.json', json_encode([
        'name' => 'orbit/composer-update-program-fixture',
        'require' => new stdClass,
        'repositories' => [['packagist.org' => false]],
        'scripts' => [
            'pre-update-cmd' => $preUpdate,
            'post-update-cmd' => 'printf done > done-marker',
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

    $lock = new Process([
        '/usr/bin/composer',
        '--working-dir',
        $path,
        'update',
        '--no-install',
        '--no-scripts',
        '--no-interaction',
        '--no-ansi',
        '--no-audit',
    ], timeout: 30);
    $lock->mustRun();
}

function composer_update_program_run(string $path, string $deadline, ?Closure $cancelled, float $timeout): CommandResult
{
    return new NativeProcessRunner()->run(new ProcessInvocation(
        arguments: [
            '/usr/bin/setsid',
            '--wait',
            '/usr/bin/bash',
            '-eu',
            '-c',
            ComposerDependencyUpdateProgram::render(),
            'composer-update',
            $path,
            $deadline,
        ],
        timeout: $timeout,
        protectedInput: ProtectedInput::holdOpen(),
        cancelled: $cancelled,
    ));
}

describe('Composer update supervisor', function (): void {
    it('stops delayed Composer hooks after cancellation', function (): void {
        $root = composer_update_program_directory();

        try {
            composer_update_program_fixture(
                $root,
                'printf start > start-marker; sleep 5; printf later > later-marker',
            );
            $started = microtime(true);
            $cancelled = false;

            try {
                composer_update_program_run(
                    $root,
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
                $this->fail('The cancelled Composer supervisor unexpectedly completed.');
            } catch (ProcessCancelledException) {
            }

            $deadline = microtime(true) + 4.0;
            while (microtime(true) < $deadline && ! is_file($root.'/later-marker') && ! is_file($root.'/done-marker')) {
                usleep(50_000);
            }

            expect(is_file($root.'/start-marker'))->toBeTrue();
            expect(is_file($root.'/later-marker'))->toBeFalse();
            expect(is_file($root.'/done-marker'))->toBeFalse();
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('stops delayed Composer hooks when the remote deadline expires', function (): void {
        $root = composer_update_program_directory();

        try {
            composer_update_program_fixture(
                $root,
                'printf start > start-marker; sleep 30; printf later > later-marker',
            );

            $result = composer_update_program_run($root, '8', null, 25.0);

            expect($result->exitCode)->toBe(124);
            expect(is_file($root.'/start-marker'))->toBeTrue();
            expect(is_file($root.'/later-marker'))->toBeFalse();
            expect(is_file($root.'/done-marker'))->toBeFalse();
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('updates a recorded Composer root that contains a space', function (): void {
        $root = composer_update_program_directory(' spaced');

        try {
            expect($root)->toContain(' ');
            composer_update_program_fixture($root, 'true');

            $result = composer_update_program_run($root, '30', null, 30.0);

            expect($result->exitCode)->toBe(0);
            expect(is_file($root.'/done-marker'))->toBeTrue();
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('rejects a relative root before starting Composer', function (): void {
        $result = composer_update_program_run('relative/root', '30', null, 5.0);

        expect($result->exitCode)->toBe(1);
    });
});
