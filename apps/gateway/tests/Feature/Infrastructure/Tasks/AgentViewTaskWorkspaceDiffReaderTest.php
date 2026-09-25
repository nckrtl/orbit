<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskWorkspaceDiffReader;
use App\Infrastructure\AgentView\CacheAgentStateView;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\AgentViewTaskWorkspaceDiffReader;
use App\Infrastructure\Tasks\RemoteTaskWorkspaceDiffReader;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use Tests\Support\AppDevFakeSshExecutor;

function view_diff_instance(): AppInstance
{
    $app = OrbitApp::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'git@github.com:nckrtl/orbit.git', 'default_branch' => 'main']);
    $node = Node::query()->create([
        'name' => 'view-diff-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.143', 'wireguard_ip' => '10.44.0.143', 'user' => 'orbit',
    ]);

    return AppInstance::query()->create([
        'app_id' => $app->id, 'node_id' => $node->id, 'name' => 'task-12',
        'checkout_path' => '/srv/orbit/apps/orbit/task-12', 'branch' => 'task-12', 'status' => 'source_resolved',
    ]);
}

function view_diff_ssh(AppDevFakeSshExecutor $transport): AppDevSshExecutor
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

/** @param array<string, mixed> $overrides */
function view_workspace(AppInstance $instance, array $overrides = [], float $ageSeconds = 0.0): void
{
    app(CacheAgentStateView::class)->putNode($instance->node_id, [], 'available', 3, CacheAgentStateView::now() - $ageSeconds, null, [
        $instance->id => [
            'instance_id' => $instance->id, 'base' => 'main', 'start' => str_repeat('a', 40), 'branch' => 'task-12',
            'head' => str_repeat('b', 40), 'dirty' => false, 'commits' => 2, 'diff' => ['files' => 3, 'added' => 40, 'removed' => 5, 'truncated' => false],
            ...$overrides,
        ],
    ]);
}

function view_diff_reader(AppDevFakeSshExecutor $transport): AgentViewTaskWorkspaceDiffReader
{
    return new AgentViewTaskWorkspaceDiffReader(app(CacheAgentStateView::class), new RemoteTaskWorkspaceDiffReader(view_diff_ssh($transport)));
}

describe('task workspace reads from the agent view', function (): void {
    it('answers the diff and the commit check from a fresh workspace without SSH', function (): void {
        $instance = view_diff_instance();
        view_workspace($instance);
        $transport = new AppDevFakeSshExecutor([]);
        $reader = view_diff_reader($transport);

        expect($reader->lineChanges($instance, 'main'))->toBe(['additions' => 40, 'deletions' => 5])
            ->and($reader->lineDiff($instance, 'main'))->toBe(45)
            ->and($reader->hasCommitsSince($instance, str_repeat('a', 40)))->toBeTrue()
            ->and($transport->commands)->toBe([]);
    });

    it('reports no new commits when the agent counts none', function (): void {
        $instance = view_diff_instance();
        view_workspace($instance, ['commits' => 0]);
        $transport = new AppDevFakeSshExecutor([]);

        expect(view_diff_reader($transport)->hasCommitsSince($instance, str_repeat('a', 40)))->toBeFalse()
            ->and($transport->commands)->toBe([]);
    });

    it('falls back to SSH when the view is stale, the workspace is missing, or its base or start differ', function (string $case): void {
        $instance = view_diff_instance();
        match ($case) {
            'stale' => view_workspace($instance, ageSeconds: 16.0),
            'missing' => null,
            'other base' => view_workspace($instance, ['base' => 'develop']),
            'no diff' => view_workspace($instance, ['diff' => null, 'commits' => null]),
            'truncated' => view_workspace($instance, ['diff' => ['files' => 5500, 'added' => 0, 'removed' => 0, 'truncated' => true], 'commits' => null]),
            'other start' => view_workspace($instance, ['start' => str_repeat('c', 40)]),
        };
        $transport = new AppDevFakeSshExecutor([
            new CommandResult(0, $case === 'other start' ? "1\n" : " 1 file changed, 7 insertions(+), 1 deletion(-)\n", '', 1, false),
            new CommandResult(0, "1\n", '', 1, false),
        ]);
        $reader = view_diff_reader($transport);

        $diff = $reader->lineChanges($instance, 'main');
        $commits = $reader->hasCommitsSince($instance, str_repeat('a', 40));

        expect($diff)->toBe($case === 'other start' ? ['additions' => 40, 'deletions' => 5] : ['additions' => 7, 'deletions' => 1])
            ->and($commits)->toBeTrue()
            ->and(count($transport->commands))->toBe(in_array($case, ['other start', 'other base'], true) ? 1 : 2);
    })->with(['stale', 'missing', 'other base', 'no diff', 'truncated', 'other start']);

    it('uses SSH for a date marker, which the agent cannot count', function (): void {
        $instance = view_diff_instance();
        view_workspace($instance);
        $transport = new AppDevFakeSshExecutor([new CommandResult(0, '', '', 1, false)]);

        expect(view_diff_reader($transport)->hasCommitsSince($instance, '2026-09-21T00:00:00+00:00'))->toBeFalse()
            ->and($transport->commands)->toHaveCount(1);
    });

    it('is the reader the scheduler and the metrics refresher use', function (): void {
        expect(app(TaskWorkspaceDiffReader::class))->toBeInstanceOf(AgentViewTaskWorkspaceDiffReader::class);
    });
});
