<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppInstances\Logs\AppInstanceLogReader;
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
                ['sudo', '/usr/bin/python3', '-I', '-', $this->checkout($instance), (string) $lines],
                self::script(),
            ),
        );

        if (! $result->succeeded()) {
            throw new ResourceOperationException(
                errorCode: 'instance.logs_failed',
                message: "The application log of AppInstance [{$instance->name}] could not be read.",
                status: 502,
            );
        }

        return $result->stdout;
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
     * Keep directory traversal, candidate selection, and reading bound to open
     * descriptors. The caller names neither a file nor a link target.
     */
    private static function script(): string
    {
        return <<<'PYTHON'
            import fnmatch, os, stat, sys

            def open_logs(checkout):
                flags = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW
                descriptor = os.open('/', flags)
                try:
                    for component in [*checkout.split('/')[1:], 'storage', 'logs']:
                        following = os.open(component, flags, dir_fd=descriptor)
                        os.close(descriptor)
                        descriptor = following
                    return descriptor
                except FileNotFoundError:
                    os.close(descriptor)
                    return None
                except BaseException:
                    os.close(descriptor)
                    raise

            def regular_file(directory, name):
                try: info = os.stat(name, dir_fd=directory, follow_symlinks=False)
                except FileNotFoundError: return None
                return info if stat.S_ISREG(info.st_mode) else None

            def read_log(checkout, lines):
                directory = open_logs(checkout)
                if directory is None: return b''
                try:
                    name = 'laravel.log'
                    selected = regular_file(directory, name)
                    if selected is None:
                        candidates = []
                        for candidate in os.listdir(directory):
                            if not fnmatch.fnmatchcase(candidate, 'laravel-*.log'): continue
                            info = regular_file(directory, candidate)
                            if info is not None: candidates.append((info.st_mtime_ns, candidate, info))
                        if not candidates: return b''
                        _, name, selected = max(candidates, key=lambda entry: (entry[0], entry[1]))
                    descriptor = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=directory)
                    try:
                        opened = os.fstat(descriptor)
                        if not stat.S_ISREG(opened.st_mode) or (opened.st_dev, opened.st_ino) != (selected.st_dev, selected.st_ino):
                            raise SystemExit(42)
                        data = os.pread(descriptor, 65_536, max(0, opened.st_size - 65_536))
                    finally:
                        os.close(descriptor)
                finally:
                    os.close(directory)
                if not data: return b''
                terminated = data.endswith(b'\n')
                parts = data.split(b'\n')
                if terminated: parts.pop()
                return b'\n'.join(parts[-lines:]) + (b'\n' if terminated else b'')

            try:
                result = read_log(sys.argv[1], int(sys.argv[2]))
            except (OSError, ValueError):
                raise SystemExit(42)
            sys.stdout.buffer.write(result)
            PYTHON;
    }
}
