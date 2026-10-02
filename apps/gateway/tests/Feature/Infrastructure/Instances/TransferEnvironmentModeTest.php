<?php

declare(strict_types=1);

use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Instances\RemoteInstanceTransferSource;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Support\LocalShellSshExecutor;

/**
 * Copies files for `scp` invocations on the local file system, so the transfer's real capture and
 * materialize scripts run end to end in a local shell.
 */
final class LocalScpProcessRunner implements ProcessRunner
{
    public function run(ProcessInvocation $invocation): CommandResult
    {
        $arguments = $invocation->arguments;

        if (($arguments[0] ?? null) !== 'scp') {
            throw new LogicException('Only scp runs here.');
        }

        $local = static fn (string $path): string => preg_replace('/\A[^@\/]+@[^:]+:/', '', $path) ?? $path;
        $target = $local((string) array_pop($arguments));
        $source = $local((string) array_pop($arguments));

        return copy($source, $target)
            ? new CommandResult(0, '', '', 1, false)
            : new CommandResult(1, '', 'copy failed', 1, false);
    }
}

function transfer_env_source(): RemoteInstanceTransferSource
{
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/home/orbit/.orbit/ssh/id_ed25519';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 AAAA';
        }
    };
    $knownHosts = new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/home/orbit/.orbit/ssh/known_hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };

    return new RemoteInstanceTransferSource(new DevelopmentSshExecutor(new LocalShellSshExecutor, $keys, $knownHosts), new LocalScpProcessRunner, $keys, $knownHosts);
}

describe('TaskCheckWorkerUser', function (): void {
    it('reconstructs a transferred worktree with worker Git and managed directory ownership', function (): void {
        config()->set('orbit.tasks.worker_user', 'nobody');
        $root = sys_get_temp_dir().'/orbit-worker-transfer-'.bin2hex(random_bytes(6));
        $checkout = $root.'/source';
        $primary = $root.'/primary';
        $files = new Filesystem;
        (new Process(['git', 'init', '-q', '-b', 'main', $primary]))->mustRun();
        (new Process(['git', '-C', $primary, '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '--allow-empty', '-m', 'start']))->mustRun();
        (new Process(['git', '-C', $primary, 'worktree', 'add', '-q', '-b', 'transfer', $checkout]))->mustRun();
        $node = Node::query()->create(['name' => 'worker-transfer', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '10.44.0.53', 'wireguard_ip' => '10.44.0.53']);
        $project = Project::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'git@example.test:shop.git', 'default_branch' => 'main']);
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'dev', 'checkout_path' => $checkout, 'source_layout' => 'worktree', 'status' => 'source_resolved']);
        $source = transfer_env_source();
        $capture = null;

        try {
            $capture = $source->capture($instance);
            $source->materialize($capture, $node, StoragePath::parse($root.'/destination'));
            clearstatcache();

            expect(fileowner($root.'/destination'))->toBe(posix_geteuid())
                ->and(fileowner($root.'/destination/.git'))->toBe(posix_geteuid())
                ->and(fileowner($root.'/destination/.git/index'))->toBe(65534);
        } finally {
            $files->deleteDirectory($root);
            if ($capture !== null && is_file($capture->archiveIdentity)) {
                unlink($capture->archiveIdentity);
            }
        }
    });
});

it('materializes a transferred checkout with an environment that other local users cannot read', function (): void {
    $root = sys_get_temp_dir().'/orbit-transfer-env-'.bin2hex(random_bytes(4));
    $name = 'source-'.bin2hex(random_bytes(4));
    $files = new Filesystem;
    $files->ensureDirectoryExists($root.'/'.$name);

    try {
        foreach ([
            ['git', 'init', '--quiet', '--initial-branch=main'],
            ['git', '-c', 'user.name=Orbit', '-c', 'user.email=orbit@example.test', 'commit', '--quiet', '--allow-empty', '-m', 'Start'],
        ] as $command) {
            new Process($command, $root.'/'.$name)->mustRun();
        }
        file_put_contents($root.'/'.$name.'/.env', "APP_KEY=secret\n");
        chmod($root.'/'.$name.'/.env', 0o664);
        file_put_contents($root.'/'.$name.'/README.md', "Readme\n");
        chmod($root.'/'.$name.'/README.md', 0o664);

        $node = static fn (string $name, string $address): Node => Node::query()->create([
            'name' => $name, 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'user' => 'orbit',
            'public_ssh_host' => $address, 'wireguard_ip' => $address,
        ]);
        $project = Project::query()->create(['name' => 'Shop', 'slug' => 'shop', 'repository_url' => 'git@example.test:shop.git', 'default_branch' => 'main']);
        $instance = Instance::query()->create([
            'project_id' => $project->id, 'node_id' => $node('transfer-from', '10.44.0.51')->id, 'name' => 'dev',
            'checkout_path' => $root.'/'.$name, 'source_layout' => 'checkout', 'status' => 'source_resolved',
        ]);
        $source = transfer_env_source();

        $capture = $source->capture($instance);
        $source->materialize($capture, $node('transfer-to', '10.44.0.52'), StoragePath::parse($root.'/destination'));
        clearstatcache();

        expect(file_get_contents($root.'/destination/.env'))->toBe("APP_KEY=secret\n")
            ->and(fileperms($root.'/destination/.env') & 0o007)->toBe(0)
            ->and(fileperms($root.'/destination/.env') & 0o600)->toBe(0o600)
            ->and(fileperms($root.'/destination/README.md') & 0o004)->toBe(0o004);
    } finally {
        $files->deleteDirectory($root);
        // The capture script writes its archive to /tmp, named after the checkout directory.
        foreach (glob("/tmp/orbit-transfer-{$name}-*.tar") ?: [] as $archive) {
            @unlink($archive);
        }
    }
});
