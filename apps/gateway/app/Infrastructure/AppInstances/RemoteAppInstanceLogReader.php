<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppInstances\Logs\AppInstanceLogReader;
use App\Domain\Logs\LogReadLimit;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\AppInstance;
use App\Models\Node;

final readonly class RemoteAppInstanceLogReader implements AppInstanceLogReader
{
    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    public function tail(AppInstance $instance, int $lines): string
    {
        $result = $this->ssh->execute(
            $this->connection($instance->node),
            new RemoteCommand(
                ['sudo', 'bash', '-seu', '--', $this->checkout($instance), (string) $lines],
                self::script(),
                maxOutputBytes: LogReadLimit::Bytes,
            ),
        );

        if (! $result->succeeded()) {
            throw new ResourceOperationException(
                errorCode: 'instance.logs_failed',
                message: "The application log of AppInstance [{$instance->name}] could not be read.",
                status: 502,
            );
        }

        return LogReadLimit::wholeLines($result->stdout);
    }

    private function checkout(AppInstance $instance): string
    {
        $path = StoragePath::tryParse($instance->checkout_path);

        if ($path === null) {
            throw new ResourceOperationException(
                errorCode: 'instance.checkout_path_invalid',
                message: "AppInstance [{$instance->name}] has an invalid checkout path.",
            );
        }

        return $path->value;
    }

    private function connection(Node $node): SshConnection
    {
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new ResourceOperationException(
                errorCode: 'instance.wireguard_ip_missing',
                message: "Node [{$node->name}] has no WireGuard address.",
            );
        }

        return new SshConnection(
            host: $node->wireguard_ip,
            user: $node->user,
            port: 22,
            identityFile: $this->keys->privateKeyPath(),
            knownHostsFile: $this->knownHosts->path(),
            commandTimeout: 30.0,
        );
    }

    /**
     * The caller never names a file. The script reads only a regular file named
     * `laravel.log` or `laravel-*.log` that sits directly in `storage/logs`, so a
     * symlink in the checkout cannot point the read at another file.
     */
    private static function script(): string
    {
        return <<<'BASH'
            logs="$1/storage/logs"
            lines=$2

            test -d "$logs" && ! test -L "$logs" || exit 0

            file="$logs/laravel.log"

            if ! test -f "$file" || test -L "$file"; then
                file=$(find "$logs" -maxdepth 1 -type f -name 'laravel-*.log' -printf '%T@ %p\n' \
                    | sort -rn | head -n 1 | cut -d' ' -f2-)
            fi

            test -n "$file" && test -f "$file" && ! test -L "$file" || exit 0

            tail -n "$lines" -- "$file"
            BASH;
    }
}
