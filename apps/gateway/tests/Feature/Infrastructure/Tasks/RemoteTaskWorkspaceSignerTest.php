<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\RemoteTaskWorkspaceSigner;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\LocalShellSshExecutor;

function task_signer_instance(string $checkout = '/srv/orbit/apps/orbit/task-9'): AppInstance
{
    $app = OrbitApp::query()->create([
        'name' => 'orbit',
        'slug' => 'orbit',
        'repository_url' => 'git@example.test:orbit.git',
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'signer-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.130',
        'wireguard_ip' => '10.44.0.130',
        'user' => 'orbit',
    ]);

    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'task-9',
        'checkout_path' => $checkout,
        'status' => 'source_resolved',
    ]);
}

function task_signer_ssh(SshExecutor $transport): AppDevSshExecutor
{
    return new AppDevSshExecutor(
        $transport,
        new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/home/orbit/.orbit/ssh/id_ed25519';
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
                return '/home/orbit/.orbit/ssh/known_hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    );
}

/** @param list<string> $arguments */
function task_signer_git(string $checkout, array $arguments): string
{
    return trim((new Process(['git', '-C', $checkout, ...$arguments]))->mustRun()->getOutput());
}

it('commits every workspace change with the message from stdin and returns the new HEAD', function (): void {
    $checkout = sys_get_temp_dir().'/orbit-task-signer-'.bin2hex(random_bytes(6));
    (new Process(['git', 'init', '--quiet', $checkout]))->mustRun();
    task_signer_git($checkout, ['-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '--allow-empty', '-m', 'start']);
    file_put_contents($checkout.'/export.php', "<?php\n");
    $message = "Models\n\nChecked the export and its test. It's \$ready; `no` shell expansion.";

    try {
        $sha = new RemoteTaskWorkspaceSigner(task_signer_ssh(new LocalShellSshExecutor))->commit(task_signer_instance($checkout), $message);

        expect($sha)->toBe(task_signer_git($checkout, ['rev-parse', 'HEAD']))
            ->and(task_signer_git($checkout, ['log', '-1', '--format=%B']))->toBe($message)
            ->and(task_signer_git($checkout, ['log', '-1', '--format=%an <%ae>']))->toBe('orbit <tasks@orbit>')
            ->and(task_signer_git($checkout, ['status', '--porcelain']))->toBe('');
    } finally {
        File::deleteDirectory($checkout);
    }
});

it('returns HEAD without a new commit when the workspace has no changes', function (): void {
    $checkout = sys_get_temp_dir().'/orbit-task-signer-'.bin2hex(random_bytes(6));
    (new Process(['git', 'init', '--quiet', $checkout]))->mustRun();
    task_signer_git($checkout, ['-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '--allow-empty', '-m', 'start']);
    $head = task_signer_git($checkout, ['rev-parse', 'HEAD']);

    try {
        expect(new RemoteTaskWorkspaceSigner(task_signer_ssh(new LocalShellSshExecutor))->commit(task_signer_instance($checkout), 'Models'))->toBe($head);
    } finally {
        File::deleteDirectory($checkout);
    }
});

it('returns null when the remote git commit fails', function (): void {
    $transport = new AppDevFakeSshExecutor([new CommandResult(1, '', 'failed', 1, false)]);

    expect(new RemoteTaskWorkspaceSigner(task_signer_ssh($transport))->commit(
        task_signer_instance(),
        'Models',
    ))->toBeNull();
});
