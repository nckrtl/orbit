<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\SystemdVpnOrderingDropIn;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Runs the rendered VPN ordering drop-in install and removal scripts against a
 * sandboxed unit directory with shimmed root-owned `install`, `chown`, and
 * `systemctl`.
 *
 * @mago-expect lint:too-many-methods The harness exposes one seam per sandboxed host observation.
 */
final class VpnOrderingDropInHarness
{
    private readonly string $root;

    private readonly Filesystem $files;

    public function __construct()
    {
        $this->root = sys_get_temp_dir().'/orbit-vpn-ordering-'.bin2hex(random_bytes(8));
        $this->files = new Filesystem;
        $this->files->ensureDirectoryExists(path: $this->unitDirectory(), mode: 0o777);
        $this->files->ensureDirectoryExists(path: $this->root.'/bin', mode: 0o777);
        $this->writeShims();
    }

    public function unitDirectory(): string
    {
        return $this->root.'/etc/systemd/system';
    }

    public function dropIn(): SystemdVpnOrderingDropIn
    {
        return new SystemdVpnOrderingDropIn($this->unitDirectory());
    }

    public function installedContents(string $service): ?string
    {
        $path = $this->dropIn()->path($service);

        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /** @return list<string> */
    public function leftovers(string $service): array
    {
        $directory = dirname($this->dropIn()->path($service));

        if (! is_dir($directory)) {
            return [];
        }

        return collect($this->files->allFiles($directory, hidden: true))
            ->map(static fn (\SplFileInfo $file): string => $file->getFilename())
            ->reject(static fn (string $name): bool => $name === SystemdVpnOrderingDropIn::FILE_NAME)
            ->values()
            ->all();
    }

    /**
     * @return array{int, list<string>}
     *
     * @mago-expect lint:no-boolean-flag-parameter The flag models the observed unit activation state.
     */
    public function run(string $service, bool $serviceActive = true): array
    {
        return $this->execute($this->dropIn()->invocation($service), $serviceActive);
    }

    /** @return array{int, list<string>} */
    public function runRemoval(string $service): array
    {
        return $this->execute($this->dropIn()->removalInvocation($service), serviceActive: true);
    }

    public function cleanup(): void
    {
        $this->files->deleteDirectory($this->root);
    }

    /**
     * @return array{int, list<string>}
     *
     * @mago-expect lint:no-boolean-flag-parameter The flag models the observed unit activation state.
     */
    private function execute(ProcessInvocation $command, bool $serviceActive): array
    {
        $log = $this->root.'/systemctl.log';
        $this->files->delete($log);

        $process = new Process(array_slice(array: $command->arguments, offset: 1), $this->root, [
            'PATH' => $this->root.'/bin:'.getenv('PATH'),
            'HARNESS_SERVICE_LOG' => $log,
            'HARNESS_SERVICE_ACTIVE' => $serviceActive ? '1' : '0',
        ]);
        $process->setInput($command->input);
        $process->run();

        return [$process->getExitCode() ?? 1, $this->serviceCalls($log)];
    }

    private function writeShims(): void
    {
        $this->writeShim('sudo', "#!/usr/bin/env bash\nprintf 'unexpected nested sudo\\n' >&2\nexit 97\n");
        $this->writeShim('chown', "#!/usr/bin/env bash\nexit 0\n");
        $this->writeShim('install', <<<'BASH'
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

            exec /usr/bin/install "${args[@]}"
            BASH);
        $this->writeShim('systemctl', <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            printf '%s\n' "$*" >> "${HARNESS_SERVICE_LOG}"

            if [ "$1" = is-active ]; then
                test "${HARNESS_SERVICE_ACTIVE}" = 1
            fi

            exit 0
            BASH);
    }

    private function writeShim(string $name, string $contents): void
    {
        file_put_contents(filename: $this->root.'/bin/'.$name, data: $contents);
        chmod($this->root.'/bin/'.$name, permissions: 0o755);
    }

    /** @return list<string> */
    private function serviceCalls(string $log): array
    {
        if (! is_file($log)) {
            return [];
        }

        $contents = file_get_contents($log);

        if ($contents === false) {
            return [];
        }

        return array_values(array_filter(explode("\n", trim($contents))));
    }
}
