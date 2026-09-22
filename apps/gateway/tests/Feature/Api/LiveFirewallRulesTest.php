<?php

declare(strict_types=1);

use App\Domain\Firewall\FirewallAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeRole;

beforeEach(function (): void {
    $this->node = Node::query()->create([
        'name' => 'edge',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $this->node->accessibleNodes()->attach($this->node);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->url = "/api/v1/nodes/{$this->node->id}/live-firewall-rules";
});

describe('firewall:live:list', function (): void {
    it('lists live UFW, paints leftover public SSH as unmanaged, and flags a missing desired rule', function (): void {
        NodeRole::query()->create([
            'node_id' => $this->node->id,
            'role' => RoleName::Gateway,
            'status' => LifecycleStatus::Active,
        ]);
        FirewallRule::query()->create([
            'node_id' => $this->node->id,
            'name' => 'office-web',
            'action' => FirewallAction::Allow,
            'source' => '10.6.0.0/24',
            'protocol' => 'tcp',
            'port' => '443',
            'status' => LifecycleStatus::Active,
        ]);
        live_firewall_ssh(<<<'OUTPUT'
            Status: active

                 To                         Action      From
            [ 1] 22/tcp                     ALLOW IN    Anywhere                   # orbit:public-ssh-recovery
            [ 2] 22/tcp (v6)                ALLOW IN    Anywhere (v6)              # orbit:public-ssh-recovery
            [ 3] 10.44.0.3 on orbit         ALLOW IN    Anywhere                   # orbit:wireguard-members
            [ 4] 443/tcp                    ALLOW IN    10.6.0.0/24                # orbit:node:NODE:firewall:office-web
            OUTPUT);

        $data = $this->getJson($this->url)->assertOk()->json('data');

        expect($data['backend_status'])
            ->toBe('active')
            ->and(array_map(static fn (array $row): array => [$row['name'], $row['match']], $data['live']))
            ->toBe([
                ['orbit:public-ssh-recovery', 'unmanaged'],
                ['orbit:wireguard-members', 'exact'],
                ['office-web', 'exact'],
            ])
            ->and($data['missing'])
            ->toHaveCount(1)
            ->and($data['missing'][0])
            ->toMatchArray(['name' => 'orbit:gateway-https', 'match' => 'missing']);
    });

    it('returns only bounded backend status and empty rows when UFW is unavailable', function (
        string $stdout,
        bool $throws,
        int $exitCode,
        bool $truncated,
        string $backend,
    ): void {
        live_firewall_ssh($stdout, $throws, $exitCode, $truncated);

        $this->getJson($this->url)->assertOk()->assertJsonPath('data', [
            'backend_status' => $backend,
            'live' => [],
            'missing' => [],
        ]);
    })->with([
        'transport exception' => ['', true, 0, false, 'unreachable'],
        'nonzero exit' => ['secret-output', false, 1, false, 'unreachable'],
        'truncated active output' => ["Status: active\nsecret-output", false, 0, true, 'unreachable'],
        'malformed output' => ['secret-output', false, 0, false, 'unreachable'],
        'inactive backend' => ["Status: inactive\nsecret-output", false, 0, false, 'inactive'],
        'absent backend' => ["Status: absent\nsecret-output", false, 0, false, 'absent'],
    ]);

    it('offers no route that changes a live rule', function (): void {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => str_contains($route->uri(), 'live-firewall-rules'));

        expect($routes)->toHaveCount(1)
            ->and($routes->first()->methods())->toBe(['GET', 'HEAD']);
    });
});

function live_firewall_ssh(string $stdout = '', bool $throws = false, int $exitCode = 0, bool $truncated = false): void
{
    $nodeId = test()->node->id;
    $stdout = str_replace('NODE', (string) $nodeId, $stdout);

    app()->instance(SshExecutor::class, new class($stdout, $throws, $exitCode, $truncated) implements SshExecutor
    {
        public function __construct(
            private string $stdout,
            private bool $throws,
            private int $exitCode,
            private bool $truncated,
        ) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            if ($this->throws) {
                throw new RuntimeException('node unreachable');
            }

            return new CommandResult($this->exitCode, $this->stdout, 'secret-stderr', 1, $this->truncated);
        }
    });
}
