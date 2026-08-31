<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\LinuxUserName;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Throwable;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class SshManagedUserAccountResolver implements ManagedUserAccountResolver
{
    public const string PROGRAM = 'passwd_entry=$(getent passwd -- "$1"); test "$(printf \'%s\\n\' "$passwd_entry" | wc -l)" -eq 1; managed_user=$(printf \'%s\\n\' "$passwd_entry" | cut -d: -f1); managed_home=$(printf \'%s\\n\' "$passwd_entry" | cut -d: -f6); managed_group=$(id -gn -- "$1"); printf "%s\\n%s\\n%s\\n" "$managed_user" "$managed_home" "$managed_group"';

    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    public function resolve(Node $node): ManagedUserAccount
    {
        try {
            $host = $node->wireguard_ip;
            if (! is_string($host) || $host === '') {
                throw new \RuntimeException;
            }
            $result = $this->ssh->execute(
                new SshConnection($host, $node->user, 22, $this->keys->privateKeyPath(), $this->knownHosts->path()),
                new RemoteCommand(['sh', '-c', self::PROGRAM, '--', $node->user]),
            );
            if (! $result->succeeded() || $result->truncated) {
                throw new \RuntimeException;
            }
            $records = explode("\n", $result->stdout);
            if (count($records) !== 4 || $records[3] !== '') {
                throw new \RuntimeException;
            }
            [$user, $home, $group] = $records;
            if (
                $user !== $node->user
                || $user === ''
                || ! LinuxUserName::isValid($group)
                || ! $this->validHome($home)
            ) {
                throw new \RuntimeException;
            }

            return new ManagedUserAccount($user, $group, $home);
        } catch (Throwable) {
            throw new NodeProvisioningException(
                'managed-user',
                'node.managed_user_unavailable',
                'The managed user account is unavailable.',
            );
        }
    }

    private function validHome(string $home): bool
    {
        if ($home === '' || $home[0] !== '/' || str_contains($home, "\n") || str_contains($home, "\r")) {
            return false;
        }

        return array_all(
            array_slice(explode('/', $home), offset: 1),
            static fn ($segment) => ! ($segment === '' || $segment === '.' || $segment === '..'),
        );
    }
}
