<?php

declare(strict_types=1);

namespace App\Infrastructure\ProxyCli;

use App\Domain\ProxyCli\ProxyCliProcess;
use App\Domain\ProxyCli\ProxyCliRuntimeLifecycle;
use App\Models\Node;
use App\Models\Process;
use SensitiveParameter;

final class RecordingProxyCliRuntimeLifecycle implements ProxyCliRuntimeLifecycle
{
    public bool $converged = false;

    public bool $removed = false;

    /** @var array<string, string>|null */
    public ?array $environment = null;

    public function converge(
        Node $node,
        #[SensitiveParameter]
        array $environment,
        int $port = ProxyCliProcess::PORT,
    ): void {
        $this->converged = true;
        $this->environment = $environment;
        Process::query()->updateOrCreate(
            [
                'owner_type' => Node::class,
                'owner_id' => $node->id,
                'name' => ProxyCliProcess::NAME,
            ],
            [
                'runtime' => 'systemd',
                'working_directory' => '/var/lib/orbit/proxycli',
                'runtime_config' => [
                    'command' => [ProxyCliProcess::EXECUTABLE, '/var/lib/orbit/proxycli/server.py'],
                    'environment' => $environment,
                    'ports' => ['127.0.0.1:'.$port.':'.$port.'/tcp'],
                ],
                'restart_policy' => 'unless-stopped',
                'desired_state' => 'running',
                'status' => 'active',
            ],
        );
    }

    public function remove(Node $node): void
    {
        $this->removed = true;
        Process::query()
            ->where('owner_type', Node::class)
            ->where('owner_id', $node->id)
            ->where('name', ProxyCliProcess::NAME)
            ->delete();
    }
}
