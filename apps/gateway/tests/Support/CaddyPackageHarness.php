<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Nodes\CaddyPackageSourceProgram;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Runs the rendered Caddy package program against a sandboxed root with shimmed `sysctl`,
 * root-owned `install`, `stat`, `dpkg`, `curl`, `apt-get`, `python3`, and `caddy`. The `curl` shim serves a
 * fake package, `apt-get` "installs" it by changing the release the `caddy` shim reports, and the
 * pinned digests are those of the fake package unless a run asks for a mismatch.
 */
final class CaddyPackageHarness
{
    private readonly string $root;

    private readonly Filesystem $files;

    public function __construct()
    {
        $this->root = sys_get_temp_dir().'/orbit-caddy-package-'.bin2hex(random_bytes(8));
        $this->files = new Filesystem;

        foreach (['etc/sysctl.d', 'etc/apt/sources.list.d', 'usr/share/keyrings', 'bin', 'release'] as $directory) {
            $this->files->ensureDirectoryExists(path: $this->root.'/'.$directory, mode: 0o777);
        }

        foreach (['amd64', 'arm64', 'riscv64'] as $architecture) {
            file_put_contents($this->packagePath($architecture), "fake caddy package for {$architecture}\n");
        }

        $this->writeShims();
    }

    public function settingPath(): string
    {
        return $this->root.'/etc/sysctl.d/60-orbit-caddy.conf';
    }

    public function legacySourcePath(): string
    {
        return $this->root.'/etc/apt/sources.list.d/orbit-caddy.sources';
    }

    public function legacyKeyringPath(): string
    {
        return $this->root.'/usr/share/keyrings/orbit-caddy.gpg';
    }

    public function installedSetting(): ?string
    {
        if (! is_file($this->settingPath())) {
            return null;
        }

        $contents = file_get_contents($this->settingPath());

        return $contents === false ? null : $contents;
    }

    /** Sets the release the `caddy` shim reports, or removes Caddy for null. */
    public function caddy(?string $version): void
    {
        if ($version === null) {
            $this->files->delete($this->root.'/caddy-version');

            return;
        }

        file_put_contents($this->root.'/caddy-version', $version);
    }

    /**
     * Runs the program once and returns its exit code, its standard output, its standard error,
     * and the shimmed commands it called, in order.
     *
     * @return array{int, string, string, list<string>}
     */
    public function run(
        bool $kernelAccepts = true,
        bool $liveFileSafe = true,
        string $architecture = 'amd64',
        bool $digestMatches = true,
        string $installs = CaddyPackageSourceProgram::RELEASE,
    ): array {
        $log = $this->root.'/calls.log';
        $this->files->delete($log);

        $arguments = CaddyPackageSourceProgram::arguments();
        $arguments[1] = 'https://release.example.test';
        $arguments[2] = $digestMatches ? $this->digest('amd64') : str_repeat('0', 128);
        $arguments[3] = $this->digest('arm64');
        $arguments[5] = $this->settingPath();
        $arguments[7] = $this->legacyKeyringPath();
        $arguments[8] = $this->legacySourcePath();

        $process = new Process(['bash', '-seu', '--', ...$arguments], $this->root, [
            'PATH' => $this->root.'/bin:'.getenv('PATH'),
            'HARNESS_ROOT' => $this->root,
            'HARNESS_CALL_LOG' => $log,
            'HARNESS_KERNEL_ACCEPTS' => $kernelAccepts ? '1' : '0',
            'HARNESS_LIVE_MODE' => $liveFileSafe ? '644' : '600',
            'HARNESS_ARCHITECTURE' => $architecture,
            'HARNESS_INSTALLS' => $installs,
        ]);
        $process->setInput(CaddyPackageSourceProgram::render());
        $process->run();

        return [$process->getExitCode() ?? 1, $process->getOutput(), $process->getErrorOutput(), $this->calls($log)];
    }

    public function cleanup(): void
    {
        $this->files->deleteDirectory($this->root);
    }

    private function packagePath(string $architecture): string
    {
        return $this->root.'/release/caddy_'.CaddyPackageSourceProgram::RELEASE.'_linux_'.$architecture.'.deb';
    }

    private function digest(string $architecture): string
    {
        return (string) hash_file('sha512', $this->packagePath($architecture));
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
            exec {{host:install}} "${args[@]}"
            BASH);
        $this->writeShim('stat', <<<'BASH'
            #!/usr/bin/env bash
            case "$2" in
              %U:%G) printf 'root:root\n' ;;
              %a) printf '%s\n' "${HARNESS_LIVE_MODE}" ;;
              *) exit 2 ;;
            esac
            BASH);
        $this->writeShim('dpkg', <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            case "$1" in
              --print-architecture) printf '%s\n' "${HARNESS_ARCHITECTURE}" ;;
              --compare-versions)
                test "$3" = ge
                lowest=$(printf '%s\n%s\n' "$2" "$4" | sort -V | head -n 1)
                test "$lowest" = "$4"
                ;;
              *) exit 2 ;;
            esac
            BASH);
        $this->writeShim('curl', <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            output=''
            url=''
            while [ "$#" -gt 0 ]; do
              case "$1" in
                --output) output=$2; shift 2 ;;
                --proto) shift 2 ;;
                -*) shift ;;
                *) url=$1; shift ;;
              esac
            done
            printf 'curl %s\n' "${url##*/}" >> "${HARNESS_CALL_LOG}"
            cp -- "${HARNESS_ROOT}/release/${url##*/}" "$output"
            BASH);
        $this->writeShim('sha512sum', <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            read -r expected path
            actual=$({{host:php}} -r 'echo hash_file("sha512", $argv[1]);' "$path")
            test "$expected" = "$actual"
            BASH);
        $this->writeShim('apt-get', <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            package=${!#}
            printf 'apt-get install %s\n' "${package##*/}" >> "${HARNESS_CALL_LOG}"
            printf '%s' "${HARNESS_INSTALLS}" > "${HARNESS_ROOT}/caddy-version"
            BASH);
        $this->writeShim('caddy', <<<'BASH'
            #!/usr/bin/env bash
            test -f "${HARNESS_ROOT}/caddy-version" || exit 127
            printf 'v%s h1:abc\n' "$(cat -- "${HARNESS_ROOT}/caddy-version")"
            BASH);
    }

    private function writeShim(string $name, string $contents): void
    {
        file_put_contents(filename: $this->root.'/bin/'.$name, data: HostBinary::expand($contents));
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
