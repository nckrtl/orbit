<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\RemoteTaskWorkspaceSigner;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\LocalShellSshExecutor;
use Tests\Support\TaskWorkerSshExecutor;

pest()->group('privileged');

function task_signer_instance(string $checkout = '/srv/orbit/apps/orbit/task-9'): Instance
{
    $project = Project::query()->create([
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

    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'task-9',
        'checkout_path' => $checkout,
        'status' => 'source_resolved',
    ]);
}

function task_signer_ssh(SshExecutor $transport): DevelopmentSshExecutor
{
    return new DevelopmentSshExecutor(
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

describe('TaskGitHardening', function (): void {
    it('ignores planted hooks and fsmonitor on an Orbit commit', function (?string $worker): void {
        config()->set('orbit.tasks.worker_user', $worker);
        $checkout = sys_get_temp_dir().'/orbit-task-git-hardening-'.bin2hex(random_bytes(6));
        (new Process(['git', 'init', '--quiet', $checkout]))->mustRun();
        task_signer_git($checkout, ['-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '--allow-empty', '-m', 'start']);
        $hooks = $checkout.'/.git/planted-hooks';
        File::ensureDirectoryExists($hooks);
        $hook = "#!/bin/sh\nprintf ran >> '$checkout/.git/hook-ran'\n";
        file_put_contents($hooks.'/pre-commit', $hook);
        chmod($hooks.'/pre-commit', 0755);
        file_put_contents($checkout.'/.git/planted-monitor', "#!/bin/sh\nprintf ran >> '$checkout/.git/fsmonitor-ran'\n");
        chmod($checkout.'/.git/planted-monitor', 0755);
        task_signer_git($checkout, ['config', 'core.hooksPath', $hooks]);
        task_signer_git($checkout, ['config', 'core.fsmonitor', $checkout.'/.git/planted-monitor']);
        file_put_contents($checkout.'/change', 'agent change');
        $transport = $worker === null ? new LocalShellSshExecutor : TaskWorkerSshExecutor::forCheckout($checkout);
        if ($worker !== null) {
            (new Process(['setfacl', '-R', '-m', 'u:nobody:rwX,d:u:nobody:rwX,d:u:'.posix_geteuid().':rwX', $checkout]))->mustRun();
        }

        try {
            $sha = new RemoteTaskWorkspaceSigner(task_signer_ssh($transport))->commit(task_signer_instance($checkout), 'Orbit commit');

            expect($sha)->not->toBeNull()
                ->and(file_exists($checkout.'/.git/hook-ran'))->toBeFalse()
                ->and(file_exists($checkout.'/.git/fsmonitor-ran'))->toBeFalse();
        } finally {
            File::deleteDirectory($checkout);
        }
    })->with([null, 'nobody']);
});

describe('TaskCheckWorkerUser', function (): void {
    it('refuses to run checkout programs when the worker resolves to the managed UID', function (): void {
        config()->set('orbit.tasks.worker_user', posix_getpwuid(posix_geteuid())['name']);
        $checkout = sys_get_temp_dir().'/orbit-signer-same-uid-'.bin2hex(random_bytes(6));
        (new Process(['git', 'init', '-q', $checkout]))->mustRun();
        file_put_contents($checkout.'/.gitattributes', "change filter=uid\n");
        file_put_contents($checkout.'/change', 'change');
        task_signer_git($checkout, ['config', 'filter.uid.clean', 'id -u > .git/filter-user; cat']);

        try {
            expect(new RemoteTaskWorkspaceSigner(task_signer_ssh(new LocalShellSshExecutor))->commit(task_signer_instance($checkout), 'commit'))->toBeNull()
                ->and(file_exists($checkout.'/.git/filter-user'))->toBeFalse();
        } finally {
            File::deleteDirectory($checkout);
        }
    });

    it('runs start-commit Git and its checkout filter as the worker, not the Node user', function (): void {
        config()->set('orbit.tasks.worker_user', 'nobody');
        $checkout = sys_get_temp_dir().'/orbit-task-worker-commit-'.bin2hex(random_bytes(6));
        (new Process(['git', 'init', '--quiet', $checkout]))->mustRun();
        task_signer_git($checkout, ['-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '--allow-empty', '-m', 'start']);
        file_put_contents($checkout.'/.gitattributes', "change filter=worker\n");
        task_signer_git($checkout, ['config', 'filter.worker.clean', 'id -un > .git/filter-user; cat']);
        file_put_contents($checkout.'/change', 'agent change');
        $transport = TaskWorkerSshExecutor::forCheckout($checkout);
        (new Process(['setfacl', '-R', '-m', 'u:nobody:rwX,d:u:nobody:rwX,d:u:'.posix_geteuid().':rwX', $checkout]))->mustRun();

        try {
            $sha = new RemoteTaskWorkspaceSigner(task_signer_ssh($transport))->commit(task_signer_instance($checkout), 'Start commit');

            expect($sha)->not->toBeNull()
                ->and(trim((string) file_get_contents($checkout.'/.git/filter-user')))->toBe('nobody')
                ->and(fileowner($checkout.'/.git/index'))->toBe(65534)
                ->and(task_signer_git($checkout, ['log', '-1', '--format=%an <%ae>']))->toBe('orbit <tasks@orbit>');
        } finally {
            File::deleteDirectory($checkout);
        }
    });
});

it('returns null when the remote git commit fails', function (): void {
    $transport = new AppDevFakeSshExecutor([new CommandResult(1, '', 'failed', 1, false)]);

    expect(new RemoteTaskWorkspaceSigner(task_signer_ssh($transport))->commit(
        task_signer_instance(),
        'Models',
    ))->toBeNull();
});
