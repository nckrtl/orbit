<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Hibernation\RuntimeHibernation;
use App\Infrastructure\Caddy\CaddyPublicationLock;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A Node's `/etc/caddy` in a temporary directory. It runs the real fragment publisher programs against it,
 * with the Node paths mapped into the directory and shims for `caddy`, `systemctl`, and root-only commands.
 * The programs need GNU coreutils and `flock`, so it runs on Linux.
 */
final class CaddyFragmentNodeHarness implements SshExecutor
{
    public readonly string $root;

    private int $versions = 0;

    public function __construct()
    {
        $this->root = sys_get_temp_dir().'/orbit-caddy-fragments-'.bin2hex(random_bytes(6));
        new Filesystem()->ensureDirectoryExists($this->root.'/bin');
        new Filesystem()->ensureDirectoryExists($this->root.'/etc/caddy/orbit-versions');
        $this->writeShims();
    }

    /** @param array<string, string> $fragments The live version's fragments by file name. */
    public function seed(array $fragments): void
    {
        $version = $this->root.'/etc/caddy/orbit-versions/seed-'.$this->versions++;
        new Filesystem()->ensureDirectoryExists($version.'/fragments');
        file_put_contents($version.'/Caddyfile', "import {$version}/fragments/*.caddy\n");

        foreach ($fragments as $name => $contents) {
            file_put_contents("{$version}/fragments/{$name}", $contents);
        }

        @unlink($this->root.'/etc/caddy/Caddyfile');
        symlink($version.'/Caddyfile', $this->root.'/etc/caddy/Caddyfile');
    }

    /** Runs Caddyfile publications. Every other command, such as a systemd drop-in, succeeds without running. */
    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        if (! str_contains($command->input ?? '', 'live_caddyfile')) {
            return new CommandResult(0, '', '', 1, false);
        }

        $process = $this->process($command);

        return new CommandResult((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput(), 1, false);
    }

    public function run(RemoteCommand $command): Process
    {
        $process = $this->process($command);

        if (! $process->isSuccessful()) {
            throw new RuntimeException('The publisher failed: '.$process->getErrorOutput());
        }

        return $process;
    }

    /** @return array<string, string> The live fragments by file name. */
    public function fragments(): array
    {
        $main = realpath($this->root.'/etc/caddy/Caddyfile');

        if ($main === false) {
            return [];
        }

        $fragments = [];

        foreach (glob(dirname($main).'/fragments/*.caddy') ?: [] as $path) {
            $fragments[basename($path)] = (string) file_get_contents($path);
        }

        ksort($fragments);

        return $fragments;
    }

    /** @return array<string, list<string>> Each live fragment's `bind` lines, trimmed. */
    public function binds(): array
    {
        $binds = [];

        foreach ($this->fragments() as $name => $contents) {
            preg_match_all('/^\s*(bind\s.*?)\s*$/m', $contents, $matches);
            $binds[$name] = $matches[1];
        }

        return $binds;
    }

    public function cleanup(): void
    {
        new Filesystem()->deleteDirectory($this->root);
    }

    private function process(RemoteCommand $command): Process
    {
        $arguments = $command->arguments;

        if (($arguments[0] ?? null) === 'sudo') {
            array_shift($arguments);
        }

        $paths = [
            '/etc/caddy/orbit-versions' => $this->root.'/etc/caddy/orbit-versions',
            '/etc/caddy/Caddyfile' => $this->root.'/etc/caddy/Caddyfile',
            CaddyPublicationLock::Path => $this->root.'/lock/caddy.lock',
            RuntimeHibernation::MarkerDirectory => $this->root.'/hibernation/markers',
            RuntimeHibernation::AccessLogDirectory => $this->root.'/hibernation/logs',
        ];
        $arguments = array_map(static fn (string $argument): string => $paths[$argument] ?? $argument, $arguments);
        $process = new Process($arguments, $this->root, ['PATH' => $this->root.'/bin:'.getenv('PATH')], $command->input);
        $process->run();

        return $process;
    }

    private function writeShims(): void
    {
        $shims = [
            'install' => <<<'BASH'
                #!/usr/bin/env bash
                set -euo pipefail
                args=()
                skip=0
                for arg in "$@"; do
                  if [ "$skip" = 1 ]; then skip=0; continue; fi
                  case "$arg" in -o|-g) skip=1 ;; *) args+=("$arg") ;; esac
                done
                exec /usr/bin/install "${args[@]}"
                BASH,
            'chown' => "#!/usr/bin/env bash\nexit 0\n",
            'caddy' => "#!/usr/bin/env bash\nexit 0\n",
            'systemctl' => "#!/usr/bin/env bash\nexit 0\n",
            'dpkg-query' => "#!/usr/bin/env bash\nexit 0\n",
            'runuser' => "#!/usr/bin/env bash\nshift 3\nexec \"\$@\"\n",
        ];

        foreach ($shims as $name => $script) {
            file_put_contents("{$this->root}/bin/{$name}", $script);
            chmod("{$this->root}/bin/{$name}", 0o755);
        }
    }
}
