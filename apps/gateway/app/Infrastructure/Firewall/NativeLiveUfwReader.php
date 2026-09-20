<?php

declare(strict_types=1);

namespace App\Infrastructure\Firewall;

use App\Domain\Firewall\LiveFirewallBackendStatus;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Throwable;

final readonly class NativeLiveUfwReader
{
    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private CommandDeadline $deadline = new CommandDeadline,
    ) {}

    /**
     * @return array{backend: LiveFirewallBackendStatus, stdout: string}
     */
    public function read(Node $node): array
    {
        $host = $node->wireguard_ip;

        if ($node->platform !== 'linux' || ! is_string($host) || $host === '') {
            return ['backend' => LiveFirewallBackendStatus::Unreachable, 'stdout' => ''];
        }

        try {
            $result = $this->ssh->execute(
                new SshConnection(
                    $host,
                    $node->user,
                    22,
                    $this->keys->privateKeyPath(),
                    $this->knownHosts->path(),
                    commandTimeout: $this->deadline->cap(30.0),
                ),
                new RemoteCommand(['sudo', 'ufw', 'status', 'numbered']),
            );
        } catch (Throwable) {
            return ['backend' => LiveFirewallBackendStatus::Unreachable, 'stdout' => ''];
        }

        if (! $result->succeeded() || $result->truncated) {
            return ['backend' => LiveFirewallBackendStatus::Unreachable, 'stdout' => ''];
        }

        if (preg_match('/\AStatus:\s+inactive\s*$/mi', $result->stdout) === 1) {
            return ['backend' => LiveFirewallBackendStatus::Inactive, 'stdout' => $result->stdout];
        }

        if (preg_match('/\AStatus:\s+absent\s*$/mi', $result->stdout) === 1) {
            return ['backend' => LiveFirewallBackendStatus::Absent, 'stdout' => $result->stdout];
        }

        if (preg_match('/\AStatus:\s+active\s*$/mi', $result->stdout) !== 1) {
            return ['backend' => LiveFirewallBackendStatus::Unreachable, 'stdout' => $result->stdout];
        }

        return ['backend' => LiveFirewallBackendStatus::Active, 'stdout' => $result->stdout];
    }
}
