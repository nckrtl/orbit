<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\WireGuardPeerDnsRepairer;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;

it('refuses ineligible targets before remote DNS mutation', function (
    ?LifecycleStatus $status,
    ?RoleName $role,
    string $errorCode,
): void {
    $repairer = new class implements WireGuardPeerDnsRepairer
    {
        /** @var list<int> */
        public array $nodeIds = [];

        public function repair(Node $node): void
        {
            $this->nodeIds[] = $node->id;
        }
    };
    app()->instance(WireGuardPeerDnsRepairer::class, $repairer);

    if ($status instanceof LifecycleStatus) {
        $node = Node::query()->create([
            'name' => 'target',
            'status' => $status,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.7',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.7',
            'wireguard_public_key' => str_repeat(string: 'A', times: 43).'=',
            'ssh_host_fingerprint' => 'SHA256:pinned',
        ]);
        if ($role instanceof RoleName) {
            $node->roles()->create(['role' => $role, 'status' => LifecycleStatus::Active]);
        }
    }

    $step = $status === LifecycleStatus::Active ? 'validation' : 'lookup';

    $this
        ->artisan('orbit:node-dns-repair', ['name' => 'target'])
        ->expectsOutput("Node DNS repair failed at step [{$step}] with error [{$errorCode}].")
        ->assertExitCode(1);

    expect($repairer->nodeIds)->toBe([]);
})->with([
    'missing Node' => [null, null, 'node.dns_repair_missing'],
    'inactive Node' => [LifecycleStatus::Failed, RoleName::AppDev, 'node.dns_repair_inactive'],
    'operator-owned Node' => [LifecycleStatus::Active, null, 'node.dns_repair_operator_owned'],
    'VPN server Node' => [LifecycleStatus::Active, RoleName::Vpn, 'node.dns_repair_vpn_server'],
]);

it('repairs one eligible peer without changing its record', function (): void {
    $repairer = new class implements WireGuardPeerDnsRepairer
    {
        /** @var list<Node> */
        public array $nodes = [];

        public function repair(Node $node): void
        {
            $this->nodes[] = $node;
        }
    };
    app()->instance(WireGuardPeerDnsRepairer::class, $repairer);
    $node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.7',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.7',
        'wireguard_public_key' => str_repeat(string: 'A', times: 43).'=',
        'dns_server_override' => '10.44.0.53',
        'ssh_host_fingerprint' => 'SHA256:pinned',
    ]);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $before = $node->fresh()->getAttributes();

    $this
        ->artisan('orbit:node-dns-repair', ['name' => 'app-dev'])
        ->expectsOutput('Node [app-dev] DNS is aligned with its managed resolver policy.')
        ->assertExitCode(0);

    expect($repairer->nodes)
        ->toHaveCount(1)
        ->and($repairer->nodes[0]->is($node))
        ->toBeTrue()
        ->and($repairer->nodes[0]->dns_server_override)
        ->toBe('10.44.0.53')
        ->and($node->fresh()->getAttributes())
        ->toBe($before);
});

it('refuses peers without a supported managed identity before remote mutation', function (
    string $platform,
    ?string $publicKey,
    ?string $fingerprint,
    string $errorCode,
): void {
    $repairer = new class implements WireGuardPeerDnsRepairer
    {
        public int $calls = 0;

        public function repair(Node $node): void
        {
            $this->calls++;
        }
    };
    app()->instance(WireGuardPeerDnsRepairer::class, $repairer);
    $node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => $platform,
        'public_ssh_host' => '192.0.2.7',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.7',
        'wireguard_public_key' => $publicKey,
        'ssh_host_fingerprint' => $fingerprint,
    ]);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);

    $this
        ->artisan('orbit:node-dns-repair', ['name' => 'app-dev'])
        ->expectsOutput("Node DNS repair failed at step [validation] with error [{$errorCode}].")
        ->assertExitCode(1);

    expect($repairer->calls)->toBe(0);
})->with([
    'unsupported platform' => [
        'darwin',
        str_repeat(string: 'A', times: 43).'=',
        'SHA256:pinned',
        'node.dns_repair_platform_unsupported',
    ],
    'missing managed identity' => ['linux', null, null, 'node.dns_repair_identity_missing'],
]);

it('reports bounded repair failures without remote output', function (): void {
    app()->instance(WireGuardPeerDnsRepairer::class, new class implements WireGuardPeerDnsRepairer
    {
        public function repair(Node $node): void
        {
            throw new NodeProvisioningException(
                step: 'wireguard-peer-dns-apply',
                errorCode: 'vpn.peer_dns_apply_failed',
                message: 'sensitive remote failure',
                result: new CommandResult(1, 'sensitive stdout', 'sensitive stderr', 1, false),
            );
        }
    });
    $node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.7',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.7',
        'wireguard_public_key' => str_repeat(string: 'A', times: 43).'=',
        'ssh_host_fingerprint' => 'SHA256:pinned',
    ]);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);

    $this
        ->artisan('orbit:node-dns-repair', ['name' => 'app-dev'])
        ->expectsOutput(
            'Node DNS repair failed at step [wireguard-peer-dns-apply] with error [vpn.peer_dns_apply_failed].',
        )
        ->doesntExpectOutputToContain('sensitive remote failure')
        ->doesntExpectOutputToContain('sensitive stdout')
        ->doesntExpectOutputToContain('sensitive stderr')
        ->assertExitCode(1);
});
