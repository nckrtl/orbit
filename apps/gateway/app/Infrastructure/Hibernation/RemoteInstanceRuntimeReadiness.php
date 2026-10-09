<?php

declare(strict_types=1);

namespace App\Infrastructure\Hibernation;

use App\Domain\AgentView\AgentProcessView;
use App\Domain\AppDev\AgentationEndpoint;
use App\Domain\AppDev\VitePortRuntime;
use App\Domain\Hibernation\HibernationException;
use App\Domain\Hibernation\InstanceRuntimeReadiness;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;

final readonly class RemoteInstanceRuntimeReadiness implements InstanceRuntimeReadiness
{
    public function __construct(
        private ProcessRuntimeManager $runtime,
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private int $timeoutSeconds = RuntimeHibernation::DefaultWakeTimeoutSeconds,
        private ?AgentProcessView $agents = null,
    ) {}

    /** @param list<Process> $processes */
    public function waitUntilReady(Instance $instance, array $processes): void
    {
        $deadline = time() + $this->timeoutSeconds;

        foreach ($processes as $process) {
            $this->waitUntilObservedRunning($process, $deadline);
        }

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
            if ($process->isAnnotator()) {
                $instance->refresh()->load('node');
                $this->waitUntilAgentationReady($instance, $deadline, annotator: true);
            }
            if ($process->isAgentationMcp()) {
                $instance->refresh()->load('node');
                $this->waitUntilAgentationReady($instance, $deadline);
            }
        }
    }

    private function waitUntilObservedRunning(Process $process, int $deadline): void
    {
        while (true) {
            $status = $this->observedStatus($process, $deadline);

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

    /**
     * The Process's state from a fresh agent view, without SSH. The Node answers instead when the
     * view cannot, when the view reports `failed`, which may predate this start, and at the
     * deadline, so a missed agent event never fails a wake on its own.
     */
    private function observedStatus(Process $process, int $deadline): string
    {
        $viewed = $this->agents?->status($process);

        if ($viewed === null || $viewed === 'failed' || time() >= $deadline) {
            return $this->runtime->status($process);
        }

        return $viewed;
    }

    private function waitUntilAgentationReady(Instance $instance, int $deadline, bool $annotator = false): void
    {
        $port = (string) ($annotator ? $instance->annotator_port : ($instance->agentation_port ?? AgentationEndpoint::PORT));
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
            errorCode: $annotator ? 'hibernation.annotator_not_ready' : 'hibernation.agentation_not_ready',
            message: 'The annotation HTTP endpoint did not become ready before the wake timeout.',
        );
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
