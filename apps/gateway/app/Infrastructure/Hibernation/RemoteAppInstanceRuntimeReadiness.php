<?php

declare(strict_types=1);

namespace App\Infrastructure\Hibernation;

use App\Domain\AppDev\AgentationEndpoint;
use App\Domain\AppDev\DevelopmentServerEndpoint;
use App\Domain\AppDev\VitePortRuntime;
use App\Domain\Hibernation\AppInstanceRuntimeReadiness;
use App\Domain\Hibernation\HibernationException;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;

final readonly class RemoteAppInstanceRuntimeReadiness implements AppInstanceRuntimeReadiness
{
    public function __construct(
        private ProcessRuntimeManager $runtime,
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private int $timeoutSeconds = RuntimeHibernation::DefaultWakeTimeoutSeconds,
    ) {}

    /** @param list<Process> $processes */
    public function waitUntilReady(AppInstance $instance, array $processes): void
    {
        $deadline = time() + $this->timeoutSeconds;

        foreach ($processes as $process) {
            $this->waitUntilObservedRunning($process, $deadline);
        }

        $node = $instance->node;

        foreach ($processes as $process) {
            if ($process->isVpDev()) {
                $instance->refresh()->load('node');
                $runtime = app(VitePortRuntime::class);
                while (! $runtime->ready($process, $instance, $instance->vite_port ?? 0)) {
                    if (time() >= $deadline) {
                        throw new HibernationException('hibernation.development_server_not_ready', 'The owned Vite endpoint did not become ready before the wake timeout.');
                    }
                    usleep(250_000);
                }

                continue;
            }
            if ($process->isAgentationMcp()) {
                $instance->refresh()->load('node');
                $this->waitUntilAgentationReady($instance, $deadline);

                continue;
            }
            if ($instance->vite_port === null && $this->needsDevelopmentServer($process)) {
                $this->waitUntilDevelopmentServerListens($node, $deadline);

                return;
            }
        }
    }

    private function waitUntilObservedRunning(Process $process, int $deadline): void
    {
        while (true) {
            $status = $this->runtime->status($process);

            if (in_array($status, ['active', 'running'], true)) {
                return;
            }

            if ($status === 'failed') {
                throw new HibernationException(
                    errorCode: 'hibernation.runtime_failed',
                    message: "Process [{$process->name}] failed while waking.",
                );
            }

            if (time() >= $deadline) {
                throw new HibernationException(
                    errorCode: 'hibernation.runtime_not_ready',
                    message: "Process [{$process->name}] did not become ready before the wake timeout.",
                );
            }

            usleep(500_000);
        }
    }

    private function waitUntilDevelopmentServerListens(Node $node, int $deadline): void
    {
        $remaining = max(1, $deadline - time());
        $port = (string) DevelopmentServerEndpoint::PORT;
        $result = $this->ssh->execute(
            $this->connection($node, (float) ($remaining + 5)),
            new RemoteCommand(
                arguments: [
                    'bash',
                    '-c',
                    'for i in $(seq 1 '.((string) ($remaining * 4)).'); do timeout 0.2 bash -c "echo >/dev/tcp/127.0.0.1/'.$port.'" && exit 0; sleep 0.25; done; exit 1',
                ],
                timeout: (float) ($remaining + 5),
            ),
        );

        if ($result->succeeded()) {
            return;
        }

        throw new HibernationException(
            errorCode: 'hibernation.development_server_not_ready',
            message: 'The development server did not accept connections before the wake timeout.',
        );
    }

    private function waitUntilAgentationReady(AppInstance $instance, int $deadline): void
    {
        $port = (string) ($instance->agentation_port ?? AgentationEndpoint::PORT);
        $remaining = max(1, $deadline - time());
        $result = $this->ssh->execute(
            $this->connection($instance->node, (float) ($remaining + 5)),
            new RemoteCommand(
                arguments: [
                    'python3',
                    '-c',
                    <<<'PY'
import urllib.request, sys
for _ in range(int(sys.argv[2])):
    try:
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        response = opener.open('http://127.0.0.1:' + sys.argv[1] + '/health', timeout=1)
        if response.status == 200:
            print('ready')
            raise SystemExit(0)
    except Exception:
        pass
    import time
    time.sleep(0.25)
print('waiting')
raise SystemExit(1)
PY
                    ,
                    $port,
                    (string) ($remaining * 4),
                ],
                timeout: (float) ($remaining + 5),
            ),
        );

        if ($result->succeeded() && trim($result->stdout) === 'ready') {
            return;
        }

        throw new HibernationException(
            errorCode: 'hibernation.agentation_not_ready',
            message: 'The Agentation HTTP endpoint did not become ready before the wake timeout.',
        );
    }

    private function needsDevelopmentServer(Process $process): bool
    {
        if ($process->name === 'vite') {
            return true;
        }

        $command = $process->runtime_config['command'] ?? null;

        if (! is_array($command)) {
            return false;
        }

        return array_any($command, fn ($part) => is_string($part) && str_contains(strtolower($part), 'vite'));
    }

    private function connection(Node $node, float $timeout): SshConnection
    {
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new HibernationException(
                errorCode: 'hibernation.wireguard_ip_missing',
                message: "Node [{$node->name}] has no WireGuard address.",
            );
        }

        return new SshConnection(
            host: $node->wireguard_ip,
            user: $node->user,
            port: 22,
            identityFile: $this->keys->privateKeyPath(),
            knownHostsFile: $this->knownHosts->path(),
            commandTimeout: $timeout,
        );
    }
}
