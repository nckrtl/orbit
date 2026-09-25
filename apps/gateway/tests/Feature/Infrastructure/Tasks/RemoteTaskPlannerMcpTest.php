<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\RemoteTaskPlannerMcp;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use Symfony\Component\Process\Process;
use Tests\Support\LocalShellSshExecutor;
use Tests\Support\TestOrbitHome;

function planner_mcp_checkout(): string
{
    $checkout = TestOrbitHome::scratch('orbit-planner-mcp');
    (new Process(['git', 'init', '--quiet', $checkout]))->mustRun();

    return $checkout;
}

function planner_mcp_instance(string $checkout): AppInstance
{
    $app = OrbitApp::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'git@github.com:nckrtl/orbit.git', 'default_branch' => 'main']);
    $node = Node::query()->create(['name' => 'planner-mcp-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '10.44.0.144', 'wireguard_ip' => '10.44.0.144', 'user' => 'orbit']);

    return AppInstance::query()->create(['app_id' => $app->id, 'node_id' => $node->id, 'name' => 'task-14', 'checkout_path' => $checkout, 'branch' => 'task-14', 'status' => 'source_resolved']);
}

function planner_mcp(): RemoteTaskPlannerMcp
{
    return new RemoteTaskPlannerMcp(new AppDevSshExecutor(
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
    ));
}

afterEach(function (): void {
    TestOrbitHome::clearScratch();
});

it('writes an untracked .mcp.json for this Gateway that Git ignores', function (): void {
    config()->set('app.url', 'https://gateway.orbit/');
    $checkout = planner_mcp_checkout();
    $instance = planner_mcp_instance($checkout);

    expect(planner_mcp()->install($instance))->toBeTrue()
        ->and(planner_mcp()->install($instance))->toBeTrue();

    $status = (new Process(['git', 'status', '--porcelain', '--untracked-files=all'], $checkout))->mustRun()->getOutput();
    $exclude = (string) file_get_contents($checkout.'/.git/info/exclude');

    expect(json_decode((string) file_get_contents($checkout.'/.mcp.json'), true))
        ->toBe(['mcpServers' => ['orbit' => ['type' => 'http', 'url' => 'https://gateway.orbit/mcp']]])
        ->and($status)->toBe('')
        ->and(substr_count($exclude, "/.mcp.json\n"))->toBe(1);
});

it('leaves a tracked .mcp.json unchanged', function (): void {
    $checkout = planner_mcp_checkout();
    file_put_contents($checkout.'/.mcp.json', "{\"mcpServers\":{}}\n");
    (new Process(['git', 'add', '.mcp.json'], $checkout))->mustRun();
    $instance = planner_mcp_instance($checkout);

    expect(planner_mcp()->install($instance))->toBeTrue()
        ->and((string) file_get_contents($checkout.'/.mcp.json'))->toBe("{\"mcpServers\":{}}\n");
});

it('reports failure for a workspace without a checkout', function (): void {
    $instance = planner_mcp_instance(planner_mcp_checkout());
    $instance->checkout_path = '';

    expect(planner_mcp()->install($instance))->toBeFalse();
});
