<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Compute\SandboxFleetIdentity;
use App\Infrastructure\Compute\TaskSandboxDrivers;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\IncusSandboxHost;
use App\Infrastructure\Tasks\RemoteTaskWorkspaceMcp;
use App\Infrastructure\Tasks\TaskWorkspaceExecutor;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Symfony\Component\Process\Process;
use Tests\Support\LocalShellSshExecutor;
use Tests\Support\TestOrbitHome;

function workspace_mcp_checkout(): string
{
    $checkout = TestOrbitHome::scratch('orbit-workspace-mcp');
    (new Process(['git', 'init', '--quiet', $checkout]))->mustRun();

    return $checkout;
}

function workspace_mcp_instance(string $checkout): Instance
{
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'git@github.com:nckrtl/orbit.git', 'default_branch' => 'main']);
    $node = Node::query()->create(['name' => 'workspace-mcp-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '10.44.0.144', 'wireguard_ip' => '10.44.0.144', 'user' => 'orbit']);

    return Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'task-14', 'checkout_path' => $checkout, 'branch' => 'task-14', 'status' => 'source_resolved']);
}

function workspace_mcp(): RemoteTaskWorkspaceMcp
{
    return new RemoteTaskWorkspaceMcp(new TaskWorkspaceExecutor(new DevelopmentSshExecutor(
        new LocalShellSshExecutor,
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
    ), app(IncusSandboxHost::class), app(TaskSandboxDrivers::class), app(SandboxFleetIdentity::class)));
}

afterEach(function (): void {
    TestOrbitHome::clearScratch();
});

describe('TaskGitHardening', function (): void {
    it('does not corrupt a private file through MCP temporary or exclude links', function (string $name): void {
        $checkout = workspace_mcp_checkout();
        $target = TestOrbitHome::scratch('private-mcp-target');
        file_put_contents($target, 'private Node file');
        chmod($target, 0600);
        if ($name === '.git/info/exclude') {
            unlink($checkout.'/'.$name);
        }
        symlink($target, $checkout.'/'.$name);

        $installed = workspace_mcp()->installWhenMissing(workspace_mcp_instance($checkout));

        expect(file_get_contents($target))->toBe('private Node file')
            ->and($installed)->toBe($name === '.mcp.json.orbit-new');
    })->with(['.mcp.json.orbit-new', '.git/info/exclude']);
});

it('writes an untracked .mcp.json that points at the Gateway search endpoint and that Git ignores', function (): void {
    config()->set('app.url', 'https://gateway.orbit/');
    $checkout = workspace_mcp_checkout();
    $instance = workspace_mcp_instance($checkout);

    expect(workspace_mcp()->installWhenMissing($instance))->toBeTrue()
        ->and(workspace_mcp()->installWhenMissing($instance))->toBeTrue();

    $status = (new Process(['git', 'status', '--porcelain', '--untracked-files=all'], $checkout))->mustRun()->getOutput();
    $exclude = (string) file_get_contents($checkout.'/.git/info/exclude');

    expect(json_decode((string) file_get_contents($checkout.'/.mcp.json'), true))
        ->toBe(['mcpServers' => ['orbit' => ['type' => 'http', 'url' => 'https://gateway.orbit/mcp/search']]])
        ->and($status)->toBe('')
        ->and(substr_count($exclude, "/.mcp.json\n"))->toBe(1);
});

it('writes the search endpoint for a reviewer when the workspace has no .mcp.json', function (): void {
    config()->set('app.url', 'https://gateway.orbit/');
    $checkout = workspace_mcp_checkout();
    $instance = workspace_mcp_instance($checkout);

    expect(workspace_mcp()->installWhenMissing($instance))->toBeTrue()
        ->and(workspace_mcp()->installWhenMissing($instance))->toBeTrue();

    expect(json_decode((string) file_get_contents($checkout.'/.mcp.json'), true))
        ->toBe(['mcpServers' => ['orbit' => ['type' => 'http', 'url' => 'https://gateway.orbit/mcp/search']]]);
});

it('leaves an existing .mcp.json unchanged when a reviewer starts', function (): void {
    config()->set('app.url', 'https://gateway.orbit/');
    $checkout = workspace_mcp_checkout();
    file_put_contents($checkout.'/.mcp.json', "{\"mcpServers\":{\"kept\":true}}\n");
    $instance = workspace_mcp_instance($checkout);

    expect(workspace_mcp()->installWhenMissing($instance))->toBeTrue()
        ->and((string) file_get_contents($checkout.'/.mcp.json'))->toBe("{\"mcpServers\":{\"kept\":true}}\n");
});

it('leaves a tracked .mcp.json unchanged', function (): void {
    $checkout = workspace_mcp_checkout();
    file_put_contents($checkout.'/.mcp.json', "{\"mcpServers\":{}}\n");
    (new Process(['git', 'add', '.mcp.json'], $checkout))->mustRun();
    $instance = workspace_mcp_instance($checkout);

    expect(workspace_mcp()->installWhenMissing($instance))->toBeTrue()
        ->and((string) file_get_contents($checkout.'/.mcp.json'))->toBe("{\"mcpServers\":{}}\n");
});

it('reports failure for a workspace without a checkout', function (): void {
    $instance = workspace_mcp_instance(workspace_mcp_checkout());
    $instance->checkout_path = '';

    expect(workspace_mcp()->installWhenMissing($instance))->toBeFalse();
});
