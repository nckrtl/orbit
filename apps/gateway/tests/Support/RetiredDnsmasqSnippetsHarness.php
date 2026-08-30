<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\WireGuard\RetiredDnsmasqSnippets;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Runs the rendered stock dnsmasq snippet retirement script against a
 * sandboxed conf directory with shimmed root-owned `install` and `systemctl`.
 *
 * @mago-expect lint:too-many-methods The harness exposes one seam per sandboxed host observation.
 */
final class RetiredDnsmasqSnippetsHarness
{
    private readonly string $root;

    private readonly Filesystem $files;

    public function __construct()
    {
        $this->root = sys_get_temp_dir().'/orbit-dnsmasq-retire-'.bin2hex(random_bytes(8));
        $this->files = new Filesystem;
        $this->files->ensureDirectoryExists(path: $this->confDirectory(), mode: 0o777);
        $this->files->ensureDirectoryExists(path: $this->root.'/bin', mode: 0o777);
        $this->writeShims();
    }

    public function confDirectory(): string
    {
        return $this->root.'/etc/dnsmasq.d';
    }

    public function retiredDirectory(): string
    {
        return $this->root.'/var/lib/orbit/dnsmasq/disabled';
    }

    public function snippets(): RetiredDnsmasqSnippets
    {
        return new RetiredDnsmasqSnippets($this->confDirectory(), $this->retiredDirectory());
    }

    public function put(string $snippet, string $contents): void
    {
        file_put_contents(filename: $this->confDirectory().'/'.$snippet, data: $contents);
    }

    public function contents(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /** @return list<string> */
    public function confDirectoryEntries(): array
    {
        return $this->entries($this->confDirectory());
    }

    /** @return list<string> */
    public function retiredDirectoryEntries(): array
    {
        return $this->entries($this->retiredDirectory());
    }

    /** @return array{int, list<string>} */
    public function run(): array
    {
        $command = $this->snippets()->invocation();
        $log = $this->root.'/commands.log';

        if (is_file($log)) {
            $this->files->delete($log);
        }

        $process = new Process(array_slice(array: $command->arguments, offset: 1), $this->root, [
            'PATH' => $this->root.'/bin:'.getenv('PATH'),
            'HARNESS_COMMAND_LOG' => $log,
        ]);
        $process->setInput($command->input);
        $process->run();

        return [$process->getExitCode() ?? 1, $this->loggedCommands($log)];
    }

    public function cleanup(): void
    {
        $this->files->deleteDirectory($this->root);
    }

    /** @return list<string> */
    private function entries(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $entries = scandir($directory);

        if ($entries === false) {
            return [];
        }

        return array_values(array_diff($entries, ['.', '..']));
    }

    private function writeShims(): void
    {
        $this->writeShim('sudo', "#!/usr/bin/env bash\nprintf 'unexpected nested sudo\\n' >&2\nexit 97\n");
        $this->writeShim('systemctl', <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            printf 'systemctl %s\n' "$*" >> "${HARNESS_COMMAND_LOG}"
            exit 0
            BASH);
        $this->writeShim('install', <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            printf 'install %s\n' "$*" >> "${HARNESS_COMMAND_LOG}"
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
        $this->writeShim('mv', <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail
            printf 'mv %s\n' "$*" >> "${HARNESS_COMMAND_LOG}"
            exec /usr/bin/mv "$@"
            BASH);
    }

    private function writeShim(string $name, string $contents): void
    {
        file_put_contents(filename: $this->root.'/bin/'.$name, data: $contents);
        chmod($this->root.'/bin/'.$name, permissions: 0o755);
    }

    /** @return list<string> */
    private function loggedCommands(string $log): array
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
