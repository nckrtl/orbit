<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppInstances\Sqlite\SqliteSeedPlacement;
use App\Domain\AppInstances\Sqlite\SqliteSnapshotTransfer;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use RuntimeException;
use Throwable;

final readonly class ProtectedSqliteSnapshotTransfer implements SqliteSnapshotTransfer
{
    public function __construct(
        private ProcessRunner $processes,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    public function transfer(
        SqliteSeedPlacement $source,
        string $sourcePath,
        SqliteSeedPlacement $target,
        string $targetPath,
        int $expectedBytes,
        string $expectedDigest,
    ): void {
        $temporary = tempnam(sys_get_temp_dir(), 'orbit-sqlite-');

        if (! is_string($temporary)) {
            $this->fail();
        }

        try {
            if (! chmod($temporary, 0600)) {
                $this->fail();
            }

            $download = $this->processes->run(new ProcessInvocation(
                $this->arguments($source, $this->remote($source, $sourcePath), $temporary),
                maxOutputBytes: 256,
            ));

            if (! $download->succeeded() || $download->truncated || $download->stdout !== '' || $download->stderr !== '') {
                $this->fail();
            }

            if (filesize($temporary) !== $expectedBytes || hash_file('sha256', $temporary) !== $expectedDigest) {
                $this->fail();
            }

            $upload = $this->processes->run(new ProcessInvocation(
                $this->arguments($target, $temporary, $this->remote($target, $targetPath)),
                maxOutputBytes: 256,
            ));

            if (! $upload->succeeded() || $upload->truncated || $upload->stdout !== '' || $upload->stderr !== '') {
                $this->fail();
            }
        } catch (Throwable) {
            $this->fail();
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @return non-empty-list<string> */
    private function arguments(SqliteSeedPlacement $placement, string $source, string $target): array
    {
        $host = $placement->node->wireguard_ip;
        $user = $placement->node->user;

        if (! is_string($host) || filter_var($host, FILTER_VALIDATE_IP) === false) {
            $this->fail();
        }

        if (preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $user) !== 1) {
            $this->fail();
        }

        return [
            'scp',
            '-q',
            '-i',
            $this->keys->privateKeyPath(),
            '-P',
            '22',
            '-o',
            'BatchMode=yes',
            '-o',
            'StrictHostKeyChecking=yes',
            '-o',
            "UserKnownHostsFile={$this->knownHosts->path()}",
            '-o',
            'ConnectTimeout=10',
            '--',
            $source,
            $target,
        ];
    }

    private function remote(SqliteSeedPlacement $placement, string $path): string
    {
        $host = $placement->node->wireguard_ip;
        $user = $placement->node->user;

        if (! is_string($host) || preg_match('/\A\/[A-Za-z0-9._\/-]+\z/D', $path) !== 1) {
            $this->fail();
        }

        $formattedHost = str_contains($host, ':') ? "[{$host}]" : $host;

        return "{$user}@{$formattedHost}:{$path}";
    }

    private function fail(): never
    {
        throw new RuntimeException('The protected SQLite snapshot transfer failed safely.');
    }
}
