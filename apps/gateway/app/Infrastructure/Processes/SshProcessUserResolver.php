<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use App\Domain\Nodes\LinuxUserName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Throwable;

final readonly class SshProcessUserResolver
{
    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    public function home(Node $node, string $user): string
    {
        if (! LinuxUserName::isValid($user) || $user === 'root') {
            throw new ResourceOperationException('process.user_invalid', 'Process user must be a valid non-root account name.');
        }

        try {
            $host = $node->wireguard_ip;
            if (! is_string($host) || $host === '') {
                $this->unavailable();
            }
            $result = $this->ssh->execute(
                new SshConnection($host, $node->user, 22, $this->keys->privateKeyPath(), $this->knownHosts->path()),
                new RemoteCommand(['getent', 'passwd', '--', $user]),
            );
            if (! $result->succeeded() || $result->truncated) {
                $this->unavailable();
            }

            $records = explode("\n", $result->stdout);
            if (count($records) !== 2 || $records[1] !== '') {
                $this->unavailable();
            }
            $fields = explode(':', $records[0]);
            if (
                count($fields) !== 7
                || $fields[0] !== $user
                || ! ctype_digit($fields[2])
                || (int) $fields[2] === 0
                || ! ctype_digit($fields[3])
                || ! $this->validHome($fields[5])
            ) {
                $this->unavailable();
            }

            return $fields[5];
        } catch (Throwable) {
            $this->unavailable();
        }
    }

    private function validHome(string $home): bool
    {
        return str_starts_with($home, '/')
            && strlen($home) <= 4096
            && preg_match('/[\x00-\x1F\x7F]/', $home) !== 1
            && array_all(
                array_slice(explode('/', $home), 1),
                static fn (string $segment): bool => ! in_array($segment, ['', '.', '..'], true),
            );
    }

    private function unavailable(): never
    {
        throw new ResourceOperationException('process.user_unavailable', 'The Process user account or its home is unavailable.');
    }
}
