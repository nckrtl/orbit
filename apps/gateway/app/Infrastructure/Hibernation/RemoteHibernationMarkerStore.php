<?php

declare(strict_types=1);

namespace App\Infrastructure\Hibernation;

use App\Domain\Hibernation\HibernationException;
use App\Domain\Hibernation\HibernationMarkerStore;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

final readonly class RemoteHibernationMarkerStore implements HibernationMarkerStore
{
    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    public function markAwake(Node $node, string $key): void
    {
        $path = RuntimeHibernation::awakePath($key);
        $this->run($node, 'prepare-awake-dir', [
            'sudo',
            'install',
            '-d',
            '-o',
            'root',
            '-g',
            'caddy',
            '-m',
            '0755',
            RuntimeHibernation::MarkerDirectory,
        ]);
        $this->run($node, 'prepare-log-dir', [
            'sudo',
            'install',
            '-d',
            '-o',
            'root',
            '-g',
            'caddy',
            '-m',
            '2775',
            RuntimeHibernation::AccessLogDirectory,
        ]);
        $this->run($node, 'mark-awake', ['sudo', 'touch', '--', $path]);
        $this->run($node, 'mark-awake-mode', ['sudo', 'chmod', '0644', '--', $path]);
    }

    public function markAsleep(Node $node, string $key): void
    {
        $this->run($node, 'mark-asleep', ['sudo', 'rm', '-f', '--', RuntimeHibernation::awakePath($key)]);
    }

    public function lastActivityUnix(Node $node, string $key): ?int
    {
        $times = array_values(array_filter(
            [
                $this->mtime($node, RuntimeHibernation::accessLogPath($key)),
                $this->mtime($node, RuntimeHibernation::awakePath($key)),
            ],
            static fn (?int $time): bool => $time !== null,
        ));

        return $times === [] ? null : max($times);
    }

    public function isAwake(Node $node, string $key): bool
    {
        return $this->mtime($node, RuntimeHibernation::awakePath($key)) !== null;
    }

    private function mtime(Node $node, string $path): ?int
    {
        $result = $this->ssh->execute(
            $this->connection($node),
            new RemoteCommand(['sudo', 'stat', '-c', '%Y', '--', $path]),
        );

        if (! $result->succeeded()) {
            return null;
        }

        $stamp = trim($result->stdout);

        if (preg_match('/\A[1-9][0-9]*\z/D', $stamp) !== 1) {
            return null;
        }

        return (int) $stamp;
    }

    /** @param non-empty-list<string> $arguments */
    private function run(Node $node, string $step, array $arguments): void
    {
        $result = $this->ssh->execute($this->connection($node), new RemoteCommand($arguments));

        if ($result->succeeded()) {
            return;
        }

        throw new HibernationException(
            errorCode: 'hibernation.marker_failed',
            message: "Hibernation marker step [{$step}] failed on Node [{$node->name}].",
        );
    }

    private function connection(Node $node): SshConnection
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
        );
    }
}
