<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Support\LinuxHost;

function linux_host_temporary_directory(): string
{
    $directory = realpath(sys_get_temp_dir()).'/orbit-linux-host-test-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o700);

    return $directory;
}

/** @param list<string> $arguments */
function linux_host_run(array $arguments, ?string $directory = null): Process
{
    $process = new Process($arguments, $directory);
    $process->run();

    return $process;
}

describe('the Linux test host copy', function (): void {
    it('holds only the files Git would track and vendor', function (): void {
        $root = linux_host_temporary_directory();

        try {
            $files = new Filesystem;
            $files->put("{$root}/.gitignore", "/vendor\n.env\n*.key\n/bootstrap/cache/*.php\n");
            $files->put("{$root}/.env.example", "APP_KEY=\n");
            $files->put("{$root}/tracked.php", '<?php');
            $files->put("{$root}/deleted.php", '<?php');
            linux_host_run(['git', 'init', '--quiet'], $root);
            linux_host_run(['git', 'add', '.'], $root);
            unlink("{$root}/deleted.php");
            $files->put("{$root}/untracked-test.php", '<?php');
            $files->put("{$root}/.env", 'APP_KEY=secret');
            $files->ensureDirectoryExists("{$root}/storage/app");
            $files->put("{$root}/storage/app/nested.key", 'secret');
            $files->ensureDirectoryExists("{$root}/bootstrap/cache");
            $files->put("{$root}/bootstrap/cache/config.php", '<?php return [];');
            $files->ensureDirectoryExists("{$root}/vendor/package");
            $files->put("{$root}/vendor/package/code.php", '<?php');

            $paths = LinuxHost::syncPaths($root);
            sort($paths);

            expect($paths)->toBe(['.env.example', '.gitignore', 'tracked.php', 'untracked-test.php', 'vendor']);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('removes only copies whose directory and marker are both stale, in private directories', function (): void {
        $base = linux_host_temporary_directory().'/base';
        $old = '200001010000';

        try {
            mkdir($base, 0o755);
            foreach (['abandoned', 'busy', 'unmarked-old', 'starting'] as $copy) {
                mkdir("{$base}/{$copy}");
            }
            foreach (['abandoned', 'busy'] as $copy) {
                touch("{$base}/{$copy}/alive");
            }
            linux_host_run(['touch', '-t', $old, "{$base}/abandoned/alive"]);
            // rsync gives a copy the source's old modification time; only the marker says it is in use.
            foreach (['abandoned', 'busy', 'unmarked-old'] as $copy) {
                linux_host_run(['touch', '-t', $old, "{$base}/{$copy}"]);
            }

            $prepare = linux_host_run(['sh', '-c', LinuxHost::prepareScript(escapeshellarg($base), 'fresh')]);

            expect($prepare->getExitCode())->toBe(0, $prepare->getErrorOutput())
                ->and(trim($prepare->getOutput()))->toBe("{$base}/fresh")
                ->and(is_dir("{$base}/abandoned"))->toBeFalse()
                ->and(is_dir("{$base}/unmarked-old"))->toBeFalse()
                ->and(is_dir("{$base}/busy"))->toBeTrue()
                ->and(is_dir("{$base}/starting"))->toBeTrue()
                ->and("{$base}/fresh/alive")->toBeFile()
                ->and(fileperms($base) & 0o777)->toBe(0o700)
                ->and(fileperms("{$base}/fresh") & 0o777)->toBe(0o700);
        } finally {
            new Filesystem()->deleteDirectory(dirname($base));
        }
    });

    it('refuses a base directory that is a symbolic link', function (): void {
        $directory = linux_host_temporary_directory();

        try {
            mkdir("{$directory}/elsewhere", 0o700);
            symlink("{$directory}/elsewhere", "{$directory}/base");

            $prepare = linux_host_run(['sh', '-c', LinuxHost::prepareScript(escapeshellarg("{$directory}/base"), 'fresh')]);

            expect($prepare->getExitCode())->not->toBe(0)
                ->and($prepare->getErrorOutput())->toContain('is not a directory that this account owns')
                ->and(is_dir("{$directory}/elsewhere/fresh"))->toBeFalse();
        } finally {
            new Filesystem()->deleteDirectory($directory);
        }
    });

    it('stops the test that runs from a copy and nothing else', function (): void {
        $directory = linux_host_temporary_directory();

        try {
            foreach (['copy', 'other'] as $copy) {
                mkdir("{$directory}/{$copy}/gateway/vendor/bin", 0o700, true);
                file_put_contents("{$directory}/{$copy}/gateway/vendor/bin/pest", "sleep 30\n");
            }
            $running = new Process(['sh', "{$directory}/copy/gateway/vendor/bin/pest"]);
            $other = new Process(['sh', "{$directory}/other/gateway/vendor/bin/pest"]);
            $running->start();
            $other->start();
            usleep(200_000);

            $stop = linux_host_run(['sh', '-c', LinuxHost::stopScript("{$directory}/copy")]);
            $deadline = microtime(true) + 5;
            while ($running->isRunning() && microtime(true) < $deadline) {
                usleep(50_000);
            }

            expect($stop->getExitCode())->toBe(0)
                ->and($running->isRunning())->toBeFalse()
                ->and($other->isRunning())->toBeTrue();
            $other->stop(0);
        } finally {
            new Filesystem()->deleteDirectory($directory);
        }
    });

    it('uses beast unless ORBIT_LINUX_TEST_HOST names another host', function (): void {
        $previous = getenv(LinuxHost::HostVariable);

        try {
            putenv(LinuxHost::HostVariable);
            expect(LinuxHost::host())->toBe('beast');
            putenv(LinuxHost::HostVariable.'=linux-builder');
            expect(LinuxHost::host())->toBe('linux-builder');
        } finally {
            putenv($previous === false ? LinuxHost::HostVariable : LinuxHost::HostVariable.'='.$previous);
        }
    });
});
