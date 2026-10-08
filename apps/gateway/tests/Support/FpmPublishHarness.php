<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class FpmPublishHarness
{
    private string $root;

    private Filesystem $files;

    public function __construct()
    {
        $this->root = sys_get_temp_dir().'/orbit-fpm-publish-'.bin2hex(random_bytes(8));
        $this->files = new Filesystem;
        $this->files->ensureDirectoryExists(path: $this->root.'/bin', mode: 0o777);
        $this->files->ensureDirectoryExists(path: $this->lockDirectory(), mode: 0o777);
        $this->files->ensureDirectoryExists(path: $this->logDirectory(), mode: 0o777);
        $this->writeShims();
    }

    public function phpRoot(): string
    {
        return $this->root.'/php';
    }

    public function lockDirectory(): string
    {
        return $this->root.'/locks';
    }

    public function logDirectory(): string
    {
        return $this->root.'/logs';
    }

    public function prepare(string $version, string $managedFilename, string $previous): string
    {
        $poolDirectory = "{$this->phpRoot()}/{$version}/fpm/pool.d";
        $this->files->ensureDirectoryExists(path: $poolDirectory, mode: 0o777);
        $this->files->put(
            "{$this->phpRoot()}/{$version}/fpm/php-fpm.conf",
            "include={$poolDirectory}/*.conf\n",
        );
        $managed = "{$poolDirectory}/{$managedFilename}";
        $this->files->put($managed, $previous);
        chmod(filename: $managed, permissions: 0o600);

        return $managed;
    }

    /** The next service activation succeeds instead of failing once. */
    public function allowActivation(): void
    {
        $this->files->put($this->root.'/activation-failed', '');
    }

    /** `php-fpm -t` rejects the next candidate configuration. */
    public function failConfigTest(): void
    {
        $this->files->put($this->root.'/config-test-failed', '');
    }

    /** PHP-FPM is failed or stopped until a restart or reload-or-restart starts it. */
    public function stopService(): void
    {
        $this->files->put($this->root.'/service-inactive', '');
    }

    public function serviceActive(): bool
    {
        return ! is_file($this->root.'/service-inactive');
    }

    /** Runs a command as the Node runs it; a leading sudo runs through the shim. */
    public function run(RemoteCommand $command): CommandResult
    {
        $arguments = $command->arguments[0] === 'sudo'
            ? array_slice(array: $command->arguments, offset: 1)
            : $command->arguments;
        $process = new Process($arguments, $this->root, [
            'PATH' => $this->root.'/bin:'.getenv('PATH'),
            'HARNESS_SERVICE_LOG' => $this->root.'/systemctl.log',
            'HARNESS_ACTIVATION_MARKER' => $this->root.'/activation-failed',
            'HARNESS_INACTIVE_MARKER' => $this->root.'/service-inactive',
            'HARNESS_CONFIG_TEST_MARKER' => $this->root.'/config-test-failed',
        ]);
        $process->setInput($command->input);
        $process->run();

        return new CommandResult(
            exitCode: $process->getExitCode() ?? 1,
            stdout: $process->getOutput(),
            stderr: $process->getErrorOutput(),
            durationMs: 1,
            truncated: false,
        );
    }

    /** @return list<string> */
    public function serviceCalls(): array
    {
        $path = $this->root.'/systemctl.log';

        if (! is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        return array_values(array_filter(explode("\n", trim($contents))));
    }

    public function cleanup(): void
    {
        $this->files->deleteDirectory($this->root);
    }

    private function writeShims(): void
    {
        $this->files->put($this->root.'/bin/sudo', "#!/usr/bin/env bash\nexec \"\$@\"\n");
        $this->files->put(
            $this->root.'/bin/install',
            HostBinary::expand(<<<'BASH'
                #!/usr/bin/env bash
                set -euo pipefail
                args=()
                skip_next=0

                for arg in "$@"; do
                    if [ "$skip_next" = 1 ]; then
                        skip_next=0
                        continue
                    fi

                    case "$arg" in
                        -o|-g)
                            skip_next=1
                            ;;
                        *)
                            args+=("$arg")
                            ;;
                    esac
                done

                exec {{host:install}} "${args[@]}"
                BASH),
        );
        $this->files->put(
            $this->root.'/bin/php-fpm8.5',
            <<<'BASH'
                #!/usr/bin/env bash
                set -euo pipefail
                test "$1" = -y
                test -f "$2"
                test "$3" = -t
                test ! -e "${HARNESS_CONFIG_TEST_MARKER}"
                BASH,
        );
        $this->files->put(
            $this->root.'/bin/systemctl',
            <<<'BASH'
                #!/usr/bin/env bash
                set -euo pipefail
                printf '%s\n' "$*" >> "${HARNESS_SERVICE_LOG}"

                if [ "$1" = is-active ]; then
                    test ! -e "${HARNESS_INACTIVE_MARKER}"
                    exit
                fi

                if { [ "$1" = reload-or-restart ] || [ "$1" = restart ]; } && [ ! -e "${HARNESS_ACTIVATION_MARKER}" ]; then
                    touch "${HARNESS_ACTIVATION_MARKER}"
                    exit 1
                fi

                if [ "$1" = reload-or-restart ] || [ "$1" = restart ]; then
                    rm -f -- "${HARNESS_INACTIVE_MARKER}"
                fi

                exit 0
                BASH,
        );

        chmod(filename: $this->root.'/bin/sudo', permissions: 0o755);
        chmod(filename: $this->root.'/bin/install', permissions: 0o755);
        chmod(filename: $this->root.'/bin/php-fpm8.5', permissions: 0o755);
        chmod(filename: $this->root.'/bin/systemctl', permissions: 0o755);
    }
}
