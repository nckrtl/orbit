<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Compute\SandboxFleetIdentity;
use App\Infrastructure\Compute\TaskSandboxDrivers;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\IncusSandboxHost;
use App\Infrastructure\Tasks\TaskWorkspaceExecutor;
use Symfony\Component\Process\Process;

/** Real Git repositories and workspace transports for the deliverable path reader. */
final class DeliverablePathWorkspace
{
    /** Runs workspace commands in a local shell, so the real tree listing reads a local checkout. */
    public static function localExecutor(): TaskWorkspaceExecutor
    {
        return self::executor(new LocalShellSshExecutor);
    }

    /** Every workspace command fails as an unreachable Node would. */
    public static function unreachableExecutor(): TaskWorkspaceExecutor
    {
        return self::executor(new class implements SshExecutor
        {
            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                return new CommandResult(255, '', 'ssh: connect to host: No route to host', 0, false);
            }
        });
    }

    /**
     * An origin with one pushed commit and a workspace clone with one more local commit.
     *
     * @return array{origin: string, checkout: string, pushed: string, local: string}
     */
    public static function repositories(): array
    {
        $origin = TestOrbitHome::scratch('deliverable-origin');
        $checkout = TestOrbitHome::scratch('deliverable-workspace');
        self::git(['init', '--quiet', '--bare', '--initial-branch=main', $origin]);
        self::git(['init', '--quiet', '--initial-branch=main', $checkout]);
        mkdir($checkout.'/app', 0777, true);
        file_put_contents($checkout.'/app/Pushed.php', "<?php\n");
        self::git(['-C', $checkout, 'add', '.']);
        self::git(['-C', $checkout, 'commit', '--quiet', '-m', 'Pushed']);
        self::git(['-C', $checkout, 'push', '--quiet', $origin, 'HEAD:refs/heads/main']);
        $pushed = self::git(['-C', $checkout, 'rev-parse', 'HEAD']);
        file_put_contents($checkout.'/app/LocalOnly.php', "<?php\n");
        self::git(['-C', $checkout, 'add', '.']);
        self::git(['-C', $checkout, 'commit', '--quiet', '-m', 'Approved but not pushed']);
        $local = self::git(['-C', $checkout, 'rev-parse', 'HEAD']);

        return ['origin' => $origin, 'checkout' => $checkout, 'pushed' => $pushed, 'local' => $local];
    }

    private static function executor(SshExecutor $transport): TaskWorkspaceExecutor
    {
        return new TaskWorkspaceExecutor(new DevelopmentSshExecutor(
            $transport,
            new class implements SshKeyProvider
            {
                public function privateKeyPath(): string
                {
                    return '/unused-local-fixture-key';
                }

                public function publicKey(): string
                {
                    return 'ssh-ed25519 AAAA';
                }
            },
            new class implements KnownHostsStore
            {
                public function path(): string
                {
                    return '/unused-local-fixture-known-hosts';
                }

                public function put(string $host, int $port, HostKey $key): void {}
            },
        ), app(IncusSandboxHost::class), app(TaskSandboxDrivers::class), app(SandboxFleetIdentity::class));
    }

    /** @param list<string> $arguments */
    private static function git(array $arguments): string
    {
        $process = new Process(['git', '-c', 'user.name=Orbit Test', '-c', 'user.email=test@orbit.invalid', '-c', 'commit.gpgsign=false', ...$arguments]);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
