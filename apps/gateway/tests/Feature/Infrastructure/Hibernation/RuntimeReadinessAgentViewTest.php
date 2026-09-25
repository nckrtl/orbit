<?php

declare(strict_types=1);

use App\Domain\AgentView\AgentProcessView;
use App\Domain\Hibernation\HibernationException;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Infrastructure\Hibernation\RemoteAppInstanceRuntimeReadiness;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\AppInstance;
use App\Models\Process;
use Tests\Support\AppDevFakeSshExecutor;

/** A runtime manager that counts the SSH status reads a wake makes. */
final class CountingStatusRuntimeManager implements ProcessRuntimeManager
{
    public int $statusReads = 0;

    /** @param list<string> $statuses */
    public function __construct(public array $statuses = ['active']) {}

    public function assertCanStart(Process $process): void {}

    public function converge(Process $process): void {}

    public function start(Process $process): void {}

    public function stop(Process $process): void {}

    public function restart(Process $process): void {}

    public function remove(Process $process): void {}

    public function status(Process $process): string
    {
        $this->statusReads++;

        return count($this->statuses) > 1 ? array_shift($this->statuses) : $this->statuses[0];
    }

    public function logs(Process $process, int $lines): string
    {
        return '';
    }
}

final class AgentViewReadinessKeys implements SshKeyProvider
{
    public function privateKeyPath(): string
    {
        return '/orbit/ssh/id_ed25519';
    }

    public function publicKey(): string
    {
        return 'ssh-ed25519 test';
    }
}

final class AgentViewReadinessKnownHosts implements KnownHostsStore
{
    public function path(): string
    {
        return '/orbit/ssh/known_hosts';
    }

    public function put(string $host, int $port, HostKey $key): void {}
}

function agent_view_readiness(CountingStatusRuntimeManager $runtime, int $timeoutSeconds = 5): RemoteAppInstanceRuntimeReadiness
{
    return new RemoteAppInstanceRuntimeReadiness(
        runtime: $runtime,
        ssh: new AppDevFakeSshExecutor,
        keys: new AgentViewReadinessKeys,
        knownHosts: new AgentViewReadinessKnownHosts,
        timeoutSeconds: $timeoutSeconds,
        agents: app(AgentProcessView::class),
    );
}

describe('wake readiness with a Gateway view of the Node agent', function (): void {
    it('reads a running Process from a fresh view without SSH', function (): void {
        $node = agent_view_node();
        $queue = agent_view_instance_process($node, 'queue');
        seed_agent_view($node->id, ["systemd:orbit-process-{$queue->id}-queue" => 'active']);
        $runtime = new CountingStatusRuntimeManager;

        agent_view_readiness($runtime)->waitUntilReady(AppInstance::query()->firstOrFail(), [$queue]);

        expect($runtime->statusReads)->toBe(0);
    });

    it('confirms a failed view over SSH before it fails the wake', function (): void {
        $node = agent_view_node();
        $queue = agent_view_instance_process($node, 'queue');
        seed_agent_view($node->id, ["systemd:orbit-process-{$queue->id}-queue" => 'failed']);
        $runtime = new CountingStatusRuntimeManager(['active']);

        agent_view_readiness($runtime)->waitUntilReady(AppInstance::query()->firstOrFail(), [$queue]);

        expect($runtime->statusReads)->toBe(1);
    });

    it('fails the wake when SSH confirms the failure', function (): void {
        $node = agent_view_node();
        $queue = agent_view_instance_process($node, 'queue');
        seed_agent_view($node->id, ["systemd:orbit-process-{$queue->id}-queue" => 'failed']);
        $runtime = new CountingStatusRuntimeManager(['failed']);

        expect(fn () => agent_view_readiness($runtime)->waitUntilReady(AppInstance::query()->firstOrFail(), [$queue]))
            ->toThrow(HibernationException::class, 'failed while waking');
    });

    it('reads the Node over SSH when the view is missing', function (): void {
        $node = agent_view_node();
        $queue = agent_view_instance_process($node, 'queue');
        $runtime = new CountingStatusRuntimeManager(['activating', 'active']);

        agent_view_readiness($runtime)->waitUntilReady(AppInstance::query()->firstOrFail(), [$queue]);

        expect($runtime->statusReads)->toBe(2);
    });

    it('checks over SSH once at the deadline instead of timing out on the view alone', function (): void {
        $node = agent_view_node();
        $queue = agent_view_instance_process($node, 'queue');
        seed_agent_view($node->id, ["systemd:orbit-process-{$queue->id}-queue" => 'activating']);
        $runtime = new CountingStatusRuntimeManager(['active']);

        agent_view_readiness($runtime, timeoutSeconds: 0)->waitUntilReady(AppInstance::query()->firstOrFail(), [$queue]);

        expect($runtime->statusReads)->toBe(1);
    });
});
