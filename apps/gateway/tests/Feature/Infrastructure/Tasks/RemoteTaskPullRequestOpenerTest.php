<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\RemoteTaskPullRequestOpener;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\TaskGroup;
use Tests\Support\AppDevFakeSshExecutor;

function remote_pr_group(): TaskGroup
{
    $app = OrbitApp::query()->create([
        'name' => 'orbit',
        'slug' => 'orbit',
        'repository_url' => 'git@github.com:nckrtl/orbit.git',
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'gh-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.141',
        'wireguard_ip' => '10.44.0.141',
        'user' => 'orbit',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'task-11',
        'checkout_path' => '/srv/orbit/apps/orbit/task-11',
        'branch' => 'task-11',
        'status' => 'source_resolved',
    ]);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Workspace PR',
        'brief' => 'Open with gh.',
        'status' => TaskGroupStatus::Settling,
    ]);
    $group->taskable()->associate($instance);
    $group->save();

    return $group->fresh(['app', 'taskable']) ?? $group;
}

function remote_pr_ssh(AppDevFakeSshExecutor $transport): AppDevSshExecutor
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

it('opens the pull request with gh in the reviewer workspace', function (): void {
    $transport = new AppDevFakeSshExecutor([
        new CommandResult(0, "https://github.com/nckrtl/orbit/pull/543\n", '', 1, false),
    ]);
    $group = remote_pr_group();

    $url = new RemoteTaskPullRequestOpener(remote_pr_ssh($transport))->open($group);

    expect($url)->toBe('https://github.com/nckrtl/orbit/pull/543')
        ->and($transport->commands)->toHaveCount(1)
        ->and($transport->commands[0]->arguments)->toBe([
            'bash',
            '-seu',
            '--',
            '/srv/orbit/apps/orbit/task-11',
            'Workspace PR',
            'Open with gh.',
            'main',
            'task-11',
        ])
        ->and((string) $transport->commands[0]->input)->toContain('gh pr create')
        ->and((string) $transport->commands[0]->input)->toContain('git -C "$checkout" push');
});

it('returns null when remote gh cannot open the pull request', function (): void {
    $transport = new AppDevFakeSshExecutor([new CommandResult(1, '', 'denied', 1, false)]);

    expect(new RemoteTaskPullRequestOpener(remote_pr_ssh($transport))->open(remote_pr_group()))
        ->toBeNull();
});
