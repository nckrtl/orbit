<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\RemoteTaskWorkspaceStateReader;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use Tests\Support\AppDevFakeSshExecutor;

function workspace_state_instance(): AppInstance
{
    $app = OrbitApp::query()->create([
        'name' => 'orbit',
        'slug' => 'orbit',
        'repository_url' => 'git@github.com:nckrtl/orbit.git',
        'default_branch' => 'main',
    ]);
    $node = Node::query()->create([
        'name' => 'state-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.143',
        'wireguard_ip' => '10.44.0.143',
        'user' => 'orbit',
    ]);

    return AppInstance::query()->create([
        'app_id' => $app->id,
        'node_id' => $node->id,
        'name' => 'task-13',
        'checkout_path' => '/srv/orbit/apps/orbit/task-13',
        'branch' => 'task-13',
        'status' => 'source_resolved',
    ]);
}

function workspace_state_reader(AppDevFakeSshExecutor $transport): RemoteTaskWorkspaceStateReader
{
    return new RemoteTaskWorkspaceStateReader(new AppDevSshExecutor(
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
    ));
}

it('reads the check script from composer.json at the workspace root', function (): void {
    $transport = new AppDevFakeSshExecutor([
        new CommandResult(0, '{"scripts": {"check": ["@lint", "@test"]}}', '', 1, false),
    ]);

    expect(workspace_state_reader($transport)->definesComposerCheckScript(workspace_state_instance()))->toBeTrue()
        ->and($transport->commands[0]->arguments)->toBe(['bash', '-seu', '--', '/srv/orbit/apps/orbit/task-13'])
        ->and((string) $transport->commands[0]->input)->toContain('cat "$checkout/composer.json"');
});

it('does not accept a composer.json without a runnable check script', function (CommandResult $result): void {
    $transport = new AppDevFakeSshExecutor([$result]);

    expect(workspace_state_reader($transport)->definesComposerCheckScript(workspace_state_instance()))->toBeFalse();
})->with([
    'no scripts' => [new CommandResult(0, '{"name": "acme/app"}', '', 1, false)],
    'only a longer check name' => [new CommandResult(0, '{"scripts": {"check:types": "phpstan"}}', '', 1, false)],
    'empty check script' => [new CommandResult(0, '{"scripts": {"check": []}}', '', 1, false)],
    'invalid json' => [new CommandResult(0, '{"scripts":', '', 1, false)],
    'missing composer.json' => [new CommandResult(1, '', 'cat: composer.json: No such file or directory', 1, false)],
]);
