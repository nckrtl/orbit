<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tools\SemverVersionNormalizer;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tools\RemoteToolCommandRunner;
use App\Infrastructure\Tools\VpToolManager;

final class ResolvedVp
{
    public static function manager(string $home = '/opt/orbit/vite-plus', ?SshExecutor $probe = null): VpToolManager
    {
        return new VpToolManager(
            new RemoteToolCommandRunner(
                $probe ?? new class($home) implements SshExecutor
                {
                    public function __construct(private string $home) {}

                    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
                    {
                        return new CommandResult(0, $this->home."/bin/vp\n", '', 1, false);
                    }
                },
                app(SshKeyProvider::class),
                app(KnownHostsStore::class),
            ),
            new SemverVersionNormalizer,
        );
    }
}
