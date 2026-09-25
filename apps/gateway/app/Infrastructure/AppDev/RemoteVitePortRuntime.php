<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\VitePortRuntime;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Processes\ProcessOperationException;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;

final readonly class RemoteVitePortRuntime implements VitePortRuntime
{
    public function __construct(
        private AppDevSshExecutor $ssh,
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
    public function ownsListener(Process $process, AppInstance $instance, int $port): bool
    {
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

    public function ready(Process $process, AppInstance $instance, int $port): bool
    {
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

    public function suspendTraffic(AppInstance $instance): void
    {
        $this->ssh->execute($instance->node, new RemoteCommand(arguments: ['sudo', 'rm', '-f', '--', RuntimeHibernation::awakePath(RuntimeHibernation::key($instance->id))], timeout: 10), 'vite-suspend-traffic', 'vite.suspend_failed');
    }

    public function prepare(Process $process, AppInstance $instance): void
    {
        $path = SystemdProcessRenderer::viteEnvironmentPath($instance->id);
        $marker = RuntimeHibernation::awakePath(RuntimeHibernation::key($instance->id));
        $this->ssh->execute($instance->node, new RemoteCommand(arguments: ['bash', '-c', <<<'BASH'
            set -euo pipefail
            test -x /usr/local/bin/vp
            test -r "$1/package.json"
            test -d "$1/node_modules"
            sudo install -d -m 0755 /etc/orbit/vite
            test ! -L "$2"
            if sudo test -e "$2"; then
                sudo grep -Fx -- "# Orbit AppInstance $3" "$2" >/dev/null
            fi
            sudo rm -f -- "$4"
            sudo install -m 0600 /dev/stdin "$2.pending"
            sudo mv -T -- "$2.pending" "$2"
            BASH, 'orbit-vite-environment', $instance->checkout_path, $path, (string) $instance->id, $marker], input: "# Orbit AppInstance {$instance->id}\nORBIT_DEV_SERVER_PORT={$instance->vite_port}\n", timeout: 15), 'vite-environment', 'vite.environment_failed');
    }

    public function project(AppInstance $instance): void
    {
        $this->projection->run(function () use ($instance): void {
            $instance->loadMissing('routes');
            if ($instance->routes->isNotEmpty()) {
                $this->caddy->build($instance->node);
            }
        });
    }
}
