<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Nodes\CaddyPackageSourceProgram;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Runs the rendered Caddy install program against a sandboxed `/etc/sysctl.d` with shimmed
 * `sysctl`, root-owned `install`, and `stat`. The `curl` shim stops the program at the key
 * download, right after the kernel setting step, so no apt work runs.
 */
final class CaddyKernelSettingHarness
{
    private readonly string $root;

    private readonly Filesystem $files;

    public function __construct()
    {
        $this->root = sys_get_temp_dir().'/orbit-caddy-kernel-setting-'.bin2hex(random_bytes(8));
        $this->files = new Filesystem;
        $this->files->ensureDirectoryExists(path: $this->root.'/etc/sysctl.d', mode: 0o777);
        $this->files->ensureDirectoryExists(path: $this->root.'/bin', mode: 0o777);
        $this->writeShims();
    }

    public function settingPath(): string
    {
        return $this->root.'/etc/sysctl.d/60-orbit-caddy.conf';
    }

    public function installedSetting(): ?string
    {
        if (! is_file($this->settingPath())) {
            return null;
        }

        $contents = file_get_contents($this->settingPath());

        return $contents === false ? null : $contents;
    }

    /**
     * Runs the program once and returns its exit code, its standard error, and the shimmed
     * commands it called, in order.
     *
     * @return array{int, string, list<string>}
     */
    public function run(bool $kernelAccepts = true, bool $liveFileSafe = true): array
    {
        $log = $this->root.'/calls.log';
        $this->files->delete($log);

        $arguments = CaddyPackageSourceProgram::arguments();
        $arguments[4] = $this->root.'/usr/share/keyrings/orbit-caddy.gpg';
        $arguments[5] = $this->root.'/etc/apt/sources.list.d/orbit-caddy.sources';
        $arguments[9] = $this->settingPath();

        $process = new Process(['bash', '-seu', '--', ...$arguments], $this->root, [
            'PATH' => $this->root.'/bin:'.getenv('PATH'),
            'HARNESS_CALL_LOG' => $log,
            'HARNESS_KERNEL_ACCEPTS' => $kernelAccepts ? '1' : '0',
            'HARNESS_LIVE_MODE' => $liveFileSafe ? '644' : '600',
        ]);
        $process->setInput(CaddyPackageSourceProgram::render());
        $process->run();

        return [$process->getExitCode() ?? 1, $process->getErrorOutput(), $this->calls($log)];
    }

    public function cleanup(): void
    {
        $this->files->deleteDirectory($this->root);
    }

    private function writeShims(): void
    {
        $this->writeShim('sysctl', <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            loaded=${2#--load=}
            printf 'sysctl %s %s %s\n' "$1" "${2%%=*}" "$(cat -- "$loaded")" >> "${HARNESS_CALL_LOG}"
            test "${HARNESS_KERNEL_ACCEPTS}" = 1
            BASH);
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

            destination=${args[${#args[@]}-1]}
            if [ "${args[0]}" != -d ]; then
              printf 'install %s\n' "${destination##*/}" >> "${HARNESS_CALL_LOG}"
            fi
            exec /usr/bin/install "${args[@]}"
            BASH);
        $this->writeShim('stat', <<<'BASH'
            #!/usr/bin/env bash
            case "$2" in
              %U:%G) printf 'root:root\n' ;;
              %a) printf '%s\n' "${HARNESS_LIVE_MODE}" ;;
              *) exit 2 ;;
            esac
            BASH);
        $this->writeShim('curl', <<<'BASH'
            #!/usr/bin/env bash
            printf 'curl\n' >> "${HARNESS_CALL_LOG}"
            exit 22
            BASH);
    }

    private function writeShim(string $name, string $contents): void
    {
        file_put_contents(filename: $this->root.'/bin/'.$name, data: $contents);
        chmod($this->root.'/bin/'.$name, permissions: 0o755);
    }

    /** @return list<string> */
    private function calls(string $log): array
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
