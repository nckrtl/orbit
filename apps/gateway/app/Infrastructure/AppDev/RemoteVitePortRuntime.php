<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\ViteEnvironmentProjection;
use App\Domain\AppDev\VitePortRuntime;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Instances\InstanceSandboxGuard;
use App\Domain\Processes\ProcessOperationException;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;

final readonly class RemoteVitePortRuntime implements ViteEnvironmentProjection, VitePortRuntime
{
    public function __construct(
        private DevelopmentSshExecutor $ssh,
        private RemoteAppDevCaddyManager $caddy,
        private DevelopmentProjectionOperationLock $projection,
        private SystemdProcessRenderer $systemd,
    ) {}

    public function selectPort(Node $node, int $preferred, array $excluded): int
    {
        $result = $this->ssh->execute($node, new RemoteCommand(arguments: ['python3', '-c', <<<'PY'
            import errno, json, pathlib, socket, sys
            data = json.load(sys.stdin)
            excluded = set(data['excluded'])
            for table in ['/proc/net/tcp', '/proc/net/tcp6']:
                if not pathlib.Path(table).exists() and table.endswith('tcp6'):
                    continue
                for row in pathlib.Path(table).read_text().splitlines()[1:]:
                    fields = row.split()
                    if fields[3] != '06':
                        excluded.add(int(fields[1].split(':')[1], 16))
            for port in range(data['preferred'], 65536):
                if port in excluded:
                    continue
                sockets = []
                available = True
                try:
                    for family, address in [(socket.AF_INET, '0.0.0.0'), (socket.AF_INET6, '::')]:
                        try:
                            sock = socket.socket(family, socket.SOCK_STREAM)
                            sockets.append(sock)
                            sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
                            if family == socket.AF_INET6:
                                sock.setsockopt(socket.IPPROTO_IPV6, socket.IPV6_V6ONLY, 1)
                            sock.bind((address, port))
                            sock.listen(1)
                        except OSError as error:
                            if family == socket.AF_INET6 and error.errno in (errno.EAFNOSUPPORT, errno.EADDRNOTAVAIL):
                                continue
                            if error.errno == errno.EADDRINUSE:
                                available = False
                                break
                            raise
                    if available:
                        print(port)
                        sys.exit(0)
                finally:
                    for sock in sockets:
                        sock.close()
            print('exhausted')
            PY], input: json_encode(['preferred' => $preferred, 'excluded' => $excluded], JSON_THROW_ON_ERROR), timeout: 30), 'vite-port-check', 'vite.port_check_failed');
        $port = filter_var(trim($result->stdout), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1024, 'max_range' => 65535]]);
        if (! is_int($port)) {
            if (trim($result->stdout) === 'exhausted') {
                throw new ProcessOperationException('vite-port-check', 'vite.ports_exhausted', 'No available Vite port remains on this Node.');
            }
            throw new ProcessOperationException('vite-port-check', 'vite.port_check_invalid', 'The Node returned an invalid port check result.');
        }

        return $port;
    }

    /** @phpstan-impure */
    public function ownsListener(Process $process, Instance $instance, int $port): bool
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        $result = $this->ssh->execute($instance->node, new RemoteCommand(arguments: ['sudo', 'python3', '-c', <<<'PY'
            import pathlib, re, subprocess, sys
            unit, port, process_id = sys.argv[1:]
            try:
                lines = pathlib.Path('/etc/systemd/system', unit).read_text().splitlines()
            except FileNotFoundError:
                print('unowned')
                sys.exit(0)
            if lines.count('X-Orbit-Process-ID=' + process_id) != 1:
                print('unowned')
                sys.exit(0)
            group = subprocess.run(['systemctl', 'show', '--property=ControlGroup', '--value', unit], check=True, capture_output=True, text=True).stdout.strip()
            rows = subprocess.run(['ss', '-H', '-ltnp', 'sport = :' + port], check=True, capture_output=True, text=True).stdout.splitlines()
            owned = bool(group and rows)
            for row in rows:
                pids = re.findall(r'pid=(\d+)', row)
                if not pids:
                    owned = False
                for pid in pids:
                    try:
                        groups = [line.split(':', 2)[2] for line in pathlib.Path('/proc', pid, 'cgroup').read_text().splitlines()]
                        if not any(value == group or value.startswith(group + '/') for value in groups):
                            owned = False
                    except (FileNotFoundError, PermissionError):
                        owned = False
            print('owned' if owned else 'unowned')
            PY, $this->systemd->unitName($process), (string) $port, (string) $process->id], timeout: 10), 'vite-listener-owner', 'vite.listener_check_failed');

        return trim($result->stdout) === 'owned';
    }

    public function ready(Process $process, Instance $instance, int $port): bool
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        if (! $this->ownsListener($process, $instance, $port)) {
            return false;
        }

        $result = $this->ssh->execute($instance->node, new RemoteCommand(arguments: ['python3', '-c', <<<'PY'
            import urllib.request, sys
            try:
                opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
                response = opener.open('http://127.0.0.1:' + sys.argv[1] + '/__orbit/vite/@vite/client', timeout=1)
                print('ready' if response.status == 200 else 'waiting')
            except Exception:
                print('waiting')
            PY, (string) $port], timeout: 5), 'vite-readiness', 'vite.readiness_check_failed');

        return trim($result->stdout) === 'ready' && $this->ownsListener($process, $instance, $port);
    }

    public function suspendTraffic(Instance $instance): void
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        $this->ssh->execute($instance->node, new RemoteCommand(arguments: ['sudo', 'rm', '-f', '--', RuntimeHibernation::awakePath(RuntimeHibernation::key($instance->id))], timeout: 10), 'vite-suspend-traffic', 'vite.suspend_failed');
    }

    public function markAwake(Instance $instance): void
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        $marker = RuntimeHibernation::awakePath(RuntimeHibernation::key($instance->id));
        $this->ssh->execute($instance->node, new RemoteCommand(arguments: ['sudo', 'install', '-d', '-o', 'root', '-g', 'caddy', '-m', '0755', '--', RuntimeHibernation::MarkerDirectory], timeout: 10), 'vite-awake-directory', 'vite.awake_failed');
        $this->ssh->execute($instance->node, new RemoteCommand(arguments: ['sudo', 'touch', '--', $marker], timeout: 10), 'vite-mark-awake', 'vite.awake_failed');
        $this->ssh->execute($instance->node, new RemoteCommand(arguments: ['sudo', 'chmod', '0644', '--', $marker], timeout: 10), 'vite-mark-awake-mode', 'vite.awake_failed');
    }

    public function prepare(Process $process, Instance $instance): void
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        $app = $instance->appConfiguration($process->app)[name];
        $applicationDirectory = $instance->applicationDirectory($app);
        $qualifiedApp = $instance->usesAppViteIdentity($app) ? $app : null;
        $ownership = SystemdProcessRenderer::viteEnvironmentMarker($instance->id, $qualifiedApp);
        $path = SystemdProcessRenderer::viteEnvironmentPath($instance->id, $qualifiedApp);
        $port = $instance->runtimeForApp($app)[vite_port];
        $marker = RuntimeHibernation::awakePath(RuntimeHibernation::key($instance->id));
        $this->ssh->execute($instance->node, new RemoteCommand(arguments: ['bash', '-c', <<<'BASH'
            set -euo pipefail
            test -x /usr/local/bin/vp
            test -r "$1/package.json"
            test -d "$1/node_modules"
            sudo install -d -m 0755 /etc/orbit/vite
            test ! -L "$2"
            for owned in "$2" "$2.pending"; do
                if sudo test -L "$owned"; then exit 1; fi
                if sudo test -e "$owned"; then
                    sudo test -f "$owned"
                    test "$(sudo grep -c '^# Orbit Instance ' "$owned")" = 1
                    sudo grep -Fx -- "# Orbit Instance $3" "$owned" >/dev/null
                    if [ "$5" != '' ]; then
                        test "$(sudo grep -c '^# Orbit App ' "$owned")" = 1
                        sudo grep -Fx -- "# Orbit App $5" "$owned" >/dev/null
                    else
                        test "$(sudo grep -c '^# Orbit App ' "$owned" || true)" = 0
                    fi
                fi
            done
            sudo rm -f -- "$4"
            sudo install -m 0600 /dev/stdin "$2.pending"
            sudo mv -T -- "$2.pending" "$2"
            BASH, 'orbit-vite-environment', $applicationDirectory, $path, (string) $instance->id, $marker, $qualifiedApp ?? ''], input: "{$ownership}\nORBIT_DEV_SERVER_PORT={$port}\n", timeout: 15), 'vite-environment', 'vite.environment_failed');
    }

    public function stageEnvironment(Instance $instance, string $app): void
    {
        $instance->appConfiguration($app);
        $port = $instance->runtimeForApp($app)['vite_port'];
        if (! is_int($port)) {
            throw new \InvalidArgumentException('An app runtime file requires its recorded Vite port.');
        }
        $qualifiedApp = $instance->usesAppViteIdentity($app) ? $app : null;
        $path = SystemdProcessRenderer::viteEnvironmentPath($instance->id, $qualifiedApp);
        $this->ssh->execute($instance->node, new RemoteCommand(arguments: ['sudo', 'python3', '-c', <<<'PYTHON'
            import os, pathlib, sys, tempfile
            path, identifier, app, port = sys.argv[1:]
            target = pathlib.Path(path)
            target.parent.mkdir(parents=True, exist_ok=True, mode=0o755)
            if target.parent.resolve() != target.parent or target.is_symlink(): raise SystemExit(1)
            directory = target.parent.stat()
            if directory.st_uid != os.geteuid() or directory.st_mode & 0o022: raise SystemExit(1)
            header = '# Orbit Instance ' + identifier
            app_header = ['# Orbit App ' + app] if app else []
            if target.exists():
                if not target.is_file() or target.stat().st_uid != os.geteuid(): raise SystemExit(1)
                lines = target.read_text().splitlines()
                if [line for line in lines if line.startswith('# Orbit Instance ')] != [header] or [line for line in lines if line.startswith('# Orbit App ')] != app_header: raise SystemExit(1)
            descriptor, temporary = tempfile.mkstemp(prefix='.orbit-vite-', dir=target.parent)
            try:
                with os.fdopen(descriptor, 'w') as handle:
                    handle.write('\n'.join([header, *app_header]) + '\nORBIT_DEV_SERVER_PORT=' + port + '\n')
                    handle.flush()
                    os.fsync(handle.fileno())
                os.replace(temporary, target)
            finally:
                if os.path.exists(temporary): os.unlink(temporary)
            PYTHON, $path, (string) $instance->id, $qualifiedApp ?? '', (string) $port]), 'vite-environment-migration', 'vite.environment_failed');
    }

    public function project(Instance $instance): void
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        $this->projection->run(function () use ($instance): void {
            $instance->loadMissing('routes');
            if ($instance->routes->isNotEmpty()) {
                $this->caddy->build($instance->node);
            }
        });
    }
}
