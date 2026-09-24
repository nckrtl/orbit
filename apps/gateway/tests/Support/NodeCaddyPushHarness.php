<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Caddy\Build\NodeCaddyfile;
use App\Infrastructure\Caddy\Build\NodeCaddyPushScript;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Runs the Node Caddy build push script against a temporary /etc/caddy with shims for Caddy,
 * systemd, runuser, and ownership changes. `HARNESS_*` variables steer the shims.
 */
final readonly class NodeCaddyPushHarness
{
    public string $root;

    public string $caddyDirectory;

    private Filesystem $files;

    public function __construct(
        private ?string $packageDefault = null,
    ) {
        $this->root = sys_get_temp_dir().'/orbit-node-caddy-push-'.bin2hex(random_bytes(8));
        $this->caddyDirectory = $this->root.'/etc/caddy';
        $this->files = new Filesystem;
        $this->files->ensureDirectoryExists($this->caddyDirectory, 0o755);
        $this->files->ensureDirectoryExists($this->root.'/bin', 0o755);
        $this->writeShims();
    }

    public function script(): NodeCaddyPushScript
    {
        return new NodeCaddyPushScript(
            caddyDirectory: $this->caddyDirectory,
            caddyExecutable: $this->root.'/bin/caddy',
            caddyServiceName: 'caddy',
            lockPath: $this->root.'/lock/caddy.lock',
        );
    }

    /**
     * @param  array<string, string>  $environment
     * @return array{exit: int, stdout: string, stderr: string}
     */
    public function push(NodeCaddyfile $caddyfile, array $environment = []): array
    {
        $this->files->delete([$this->root.'/systemctl.log', $this->root.'/validate.log', $this->root.'/reload-failed']);
        $command = $this->script()->command($caddyfile);
        $process = new Process(array_slice($command->arguments, 1), $this->root, [
            'PATH' => $this->root.'/bin:'.getenv('PATH'),
            'HARNESS_ROOT' => $this->root,
            'HARNESS_CADDY_VERSION' => 'v2.11.4 h1:abc',
            'HARNESS_FAIL_VALIDATE' => '0',
            'HARNESS_FAIL_RELOAD' => '0',
            'HARNESS_PACKAGE_MD5' => $this->packageDefault === null ? '' : md5($this->packageDefault),
            'HARNESS_LIVE' => $this->caddyDirectory.'/Caddyfile',
            ...$environment,
        ]);
        $process->setInput($command->input);
        $process->run();

        return [
            'exit' => $process->getExitCode() ?? 1,
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }

    public function path(string $suffix): string
    {
        return $this->caddyDirectory.'/'.$suffix;
    }

    public function write(string $suffix, string $contents): void
    {
        $this->files->ensureDirectoryExists(dirname($this->path($suffix)), 0o755);
        file_put_contents($this->path($suffix), $contents);
    }

    public function link(string $target): void
    {
        $this->files->delete($this->path('Caddyfile'));
        symlink($target, $this->path('Caddyfile'));
    }

    /** @return list<string> */
    public function serviceCalls(): array
    {
        return $this->lines($this->root.'/systemctl.log');
    }

    /** @return list<string> */
    public function validations(): array
    {
        return $this->lines($this->root.'/validate.log');
    }

    /** @return list<string> Directory names directly under a path, sorted. */
    public function directories(string $suffix): array
    {
        if (! is_dir($this->path($suffix))) {
            return [];
        }

        $names = array_map(basename(...), $this->files->directories($this->path($suffix)));
        sort($names);

        return $names;
    }

    public function cleanup(): void
    {
        $this->files->deleteDirectory($this->root);
    }

    /** @return list<string> */
    private function lines(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        return array_values(array_filter(explode("\n", (string) file_get_contents($path))));
    }

    private function writeShims(): void
    {
        $this->shim('caddy', <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            if [ "$1" = version ]; then
                printf '%s\n' "$HARNESS_CADDY_VERSION"
                exit 0
            fi
            test "$1" = validate
            printf 'validate %s\n' "$3" >> "$HARNESS_ROOT/validate.log"
            test -f "$3"
            printf '{"level":"info","msg":"using config from file"}\n' >&2
            if [ "$HARNESS_FAIL_VALIDATE" = 1 ]; then
                printf 'Error: adapting config using caddyfile: unrecognized directive: broken\n' >&2
                exit 1
            fi
            BASH);
        $this->shim('runuser', <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            test "$1" = -u && test "$2" = caddy && test "$3" = --
            shift 3
            printf 'user=caddy\n' >> "$HARNESS_ROOT/validate.log"
            exec "$@"
            BASH);
        $this->shim('systemctl', <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            printf '%s\n' "$*" >> "$HARNESS_ROOT/systemctl.log"
            if [ "$HARNESS_FAIL_RELOAD" = 1 ] && [ "$1" = reload-or-restart ] && [ ! -e "$HARNESS_ROOT/reload-failed" ]; then
                touch "$HARNESS_ROOT/reload-failed"
                printf 'Job for caddy.service failed.\n' >&2
                exit 1
            fi
            BASH);
        $this->shim('install', <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            args=()
            skip=0
            for arg in "$@"; do
                if [ "$skip" = 1 ]; then skip=0; continue; fi
                case "$arg" in
                    -o|-g) skip=1 ;;
                    *) args+=("$arg") ;;
                esac
            done
            exec /usr/bin/install "${args[@]}"
            BASH);
        $this->shim('chown', "#!/usr/bin/env bash\nexit 0\n");
        $this->shim('journalctl', <<<'BASH'
            #!/usr/bin/env bash
            if [ -e "$HARNESS_ROOT/reload-failed" ]; then
                printf 'Error: sending configuration to instance: listen tcp 10.44.0.9:9103: bind: address already in use\n'
            fi
            BASH);
        $this->shim('dpkg-query', <<<'BASH'
            #!/usr/bin/env bash
            if [ -n "$HARNESS_PACKAGE_MD5" ]; then
                printf ' %s %s\n' "$HARNESS_LIVE" "$HARNESS_PACKAGE_MD5"
            fi
            BASH);
        $this->shim('sudo', "#!/usr/bin/env bash\nprintf 'unexpected nested sudo\\n' >&2\nexit 97\n");
    }

    private function shim(string $name, string $contents): void
    {
        file_put_contents($this->root.'/bin/'.$name, $contents.PHP_EOL);
        chmod($this->root.'/bin/'.$name, 0o755);
    }
}
