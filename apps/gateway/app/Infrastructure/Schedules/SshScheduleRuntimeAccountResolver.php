<?php

declare(strict_types=1);

namespace App\Infrastructure\Schedules;

use App\Domain\Schedules\ScheduleErrorCode;
use App\Domain\Schedules\ScheduleOperationException;
use App\Domain\Schedules\ScheduleRuntimeAccount;
use App\Domain\Schedules\ScheduleRuntimeAccountResolver;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

final readonly class SshScheduleRuntimeAccountResolver implements ScheduleRuntimeAccountResolver
{
    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    public function resolve(Node $node, string $user): ScheduleRuntimeAccount
    {
        $host = $node->wireguard_ip;

        if (! is_string($host) || $host === '') {
            $this->unreachable();
        }

        $result = $this->ssh->execute(
            new SshConnection($host, $node->user, 22, $this->keys->privateKeyPath(), $this->knownHosts->path()),
            new RemoteCommand(['getent', 'passwd', $user], maxOutputBytes: 4096, timeout: 10.0),
        );

        if ($result->exitCode === 255) {
            $this->unreachable();
        }

        if (! $result->succeeded() || $result->truncated || $result->stderr !== '') {
            $this->unavailable();
        }

        $line = rtrim($result->stdout, "\n");
        $parts = explode(':', $line);

        if (
            count($parts) !== 7
            || $parts[0] !== $user
            || preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $parts[0]) !== 1
        ) {
            $this->unavailable();
        }

        $group = $this->ssh->execute(
            new SshConnection($host, $node->user, 22, $this->keys->privateKeyPath(), $this->knownHosts->path()),
            new RemoteCommand(['id', '-gn', $user], maxOutputBytes: 256, timeout: 10.0),
        );
        $groupName = rtrim($group->stdout, "\n");

        if ($group->exitCode === 255) {
            $this->unreachable();
        }

        if (
            ! $group->succeeded()
            || $group->truncated
            || $group->stderr !== ''
            || preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $groupName) !== 1
        ) {
            $this->unavailable();
        }

        return new ScheduleRuntimeAccount($parts[0], $groupName, $parts[5], $parts[6]);
    }

    private function unreachable(): never
    {
        throw new ScheduleOperationException(
            'resolve-account',
            ScheduleErrorCode::NodeUnreachable,
            'The Schedule host Node is unreachable.',
        );
    }

    private function unavailable(): never
    {
        throw new ScheduleOperationException(
            'resolve-account',
            ScheduleErrorCode::TargetUnavailable,
            'The Schedule runtime account is unavailable.',
        );
    }
}
