<?php

declare(strict_types=1);

use App\Actions\Firewall\ListFirewallRulesAction;
use App\Actions\Firewall\RemoveFirewallRuleAction;
use App\Actions\Firewall\StoreFirewallRuleAction;
use App\Data\Firewall\StoreFirewallRuleData;
use App\Domain\Firewall\FirewallAction;
use App\Domain\Firewall\FirewallBackendStatus;
use App\Domain\Firewall\FirewallManager;
use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\FirewallRule;
use App\Models\Node;

it('stores inactive desired intent and retries convergence without duplicating the name', function (): void {
    $manager = new FirewallFakeManager([
        FirewallBackendStatus::Inactive,
        FirewallBackendStatus::Active,
    ]);
    $action = new StoreFirewallRuleAction($manager);
    $node = firewall_action_node();
    $data = firewall_store_data();

    expect(fn (): array => $action->execute($node, $data))
        ->toThrow(function (FirewallOperationException $exception): void {
            expect($exception->errorCode)
                ->toBe('firewall.backend_inactive')
                ->and($exception->step)
                ->toBe('status')
                ->and($exception->status)
                ->toBe(503);
        });

    $this->assertDatabaseHas('firewall_rules', [
        'node_id' => $node->id,
        'name' => 'web',
        'status' => 'failed',
        'failed_step' => 'status',
        'error_code' => 'firewall.backend_inactive',
    ]);

    $second = $action->execute($node, $data);

    expect($second['created'])
        ->toBeFalse()
        ->and($second['backend_status'])
        ->toBe(FirewallBackendStatus::Active)
        ->and(FirewallRule::query()->count())
        ->toBe(1)
        ->and($manager->converged)
        ->toBe(['web', 'web']);

    $this->assertDatabaseHas('firewall_rules', [
        'node_id' => $node->id,
        'name' => 'web',
        'action' => 'allow',
        'source' => '192.0.2.0/24',
        'protocol' => 'tcp',
        'port' => '443',
        'status' => 'active',
        'failed_step' => null,
        'error_code' => null,
    ]);
});

it('rejects same-name desired drift without changing the stored rule or UFW', function (): void {
    $manager = new FirewallFakeManager([FirewallBackendStatus::Active]);
    $action = new StoreFirewallRuleAction($manager);
    $node = firewall_action_node();
    $action->execute($node, firewall_store_data());

    expect(fn (): array => $action->execute($node, firewall_store_data(port: '8443')))
        ->toThrow(ResourceOperationException::class, 'different configuration');

    expect($manager->converged)
        ->toBe(['web'])
        ->and(FirewallRule::query()->sole()->port)
        ->toBe('443');
});

it('rejects public recovery SSH denies before persistence or UFW', function (): void {
    $manager = new FirewallFakeManager([]);
    $action = new StoreFirewallRuleAction($manager);
    $node = firewall_action_node();

    expect(fn (): array => $action->execute(
        $node,
        firewall_store_data(action: FirewallAction::Deny, port: '1:1024'),
    ))
        ->toThrow(ResourceOperationException::class, 'public recovery SSH port');

    expect(FirewallRule::query()->count())
        ->toBe(0)
        ->and($manager->converged)
        ->toBeEmpty();
});

it('rejects an opposite action on the same node source protocol and port before UFW', function (
    FirewallAction $existing,
    FirewallAction $conflicting,
): void {
    $manager = new FirewallFakeManager([FirewallBackendStatus::Active]);
    $action = new StoreFirewallRuleAction($manager);
    $node = firewall_action_node();
    $action->execute($node, firewall_store_data(action: $existing));

    expect(fn (): array => $action->execute(
        $node,
        firewall_store_data(action: $conflicting, name: 'block-web'),
    ))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)
                ->toBe('firewall.action_conflict')
                ->and($exception->status)
                ->toBe(409);
        });

    expect($manager->converged)
        ->toBe(['web'])
        ->and(FirewallRule::query()->count())
        ->toBe(1);

    $this->assertDatabaseHas('firewall_rules', [
        'node_id' => $node->id,
        'name' => 'web',
        'action' => $existing->value,
        'status' => 'active',
        'failed_step' => null,
        'error_code' => null,
    ]);
})->with([
    'deny after allow' => [FirewallAction::Allow, FirewallAction::Deny],
    'allow after deny' => [FirewallAction::Deny, FirewallAction::Allow],
]);

it('retries the original allow after a rejected deny without flipping it to failed', function (): void {
    $manager = new FirewallFakeManager([
        FirewallBackendStatus::Active,
        FirewallBackendStatus::Active,
    ]);
    $action = new StoreFirewallRuleAction($manager);
    $node = firewall_action_node();
    $action->execute($node, firewall_store_data());

    expect(fn (): array => $action->execute(
        $node,
        firewall_store_data(action: FirewallAction::Deny, name: 'block-web'),
    ))
        ->toThrow(ResourceOperationException::class, 'conflicts with [web]');

    $retry = $action->execute($node, firewall_store_data());

    expect($retry['created'])
        ->toBeFalse()
        ->and($retry['backend_status'])
        ->toBe(FirewallBackendStatus::Active)
        ->and($retry['rule']->status)
        ->toBe(LifecycleStatus::Active)
        ->and($retry['rule']->error_code)
        ->toBeNull()
        ->and($manager->converged)
        ->toBe(['web', 'web'])
        ->and(FirewallRule::query()->count())
        ->toBe(1);
});

it('accepts another rule when the source protocol port node or action identity differs', function (
    string $name,
    FirewallAction $candidateAction,
    string $source,
    string $protocol,
    string $port,
    bool $otherNode,
): void {
    $manager = new FirewallFakeManager([
        FirewallBackendStatus::Active,
        FirewallBackendStatus::Active,
    ]);
    $store = new StoreFirewallRuleAction($manager);
    $node = firewall_action_node();
    $target = $otherNode
        ? firewall_action_node(
            name: 'other',
            publicHost: '192.0.2.21',
            wireguardIp: '10.44.0.4',
        )
        : $node;

    $store->execute($node, firewall_store_data());
    $result = $store->execute($target, firewall_store_data(
        action: $candidateAction,
        port: $port,
        name: $name,
        source: $source,
        protocol: $protocol,
    ));

    expect($result['created'])
        ->toBeTrue()
        ->and($manager->converged)
        ->toHaveCount(2)
        ->and(FirewallRule::query()->count())
        ->toBe(2);
})->with([
    'different source' => ['block-web', FirewallAction::Deny, '198.51.100.0/24', 'tcp', '443', false],
    'different protocol' => ['block-web', FirewallAction::Deny, '192.0.2.0/24', 'udp', '443', false],
    'different port' => ['block-web', FirewallAction::Deny, '192.0.2.0/24', 'tcp', '8443', false],
    'different node' => ['block-web', FirewallAction::Deny, '192.0.2.0/24', 'tcp', '443', true],
    'same action different name' => ['edge-web', FirewallAction::Allow, '192.0.2.0/24', 'tcp', '443', false],
]);

it('keeps the record when removal cannot run while UFW is inactive and removes it on retry', function (): void {
    $manager = new FirewallFakeManager([], [
        FirewallBackendStatus::Inactive,
        FirewallBackendStatus::Absent,
    ]);
    $node = firewall_action_node();
    $rule = firewall_action_rule($node);
    $action = new RemoveFirewallRuleAction($manager);

    expect(fn (): FirewallBackendStatus => $action->execute($rule))
        ->toThrow(function (FirewallOperationException $exception): void {
            expect($exception->errorCode)
                ->toBe('firewall.backend_inactive')
                ->and($exception->status)
                ->toBe(503);
        });

    expect($rule->fresh())
        ->not
        ->toBeNull()
        ->and($rule->fresh()?->status)
        ->toBe(LifecycleStatus::Failed)
        ->and($rule->fresh()?->error_code)
        ->toBe('firewall.backend_inactive');

    $absent = $action->execute($rule->fresh());

    expect($absent)
        ->toBe(FirewallBackendStatus::Absent)
        ->and(FirewallRule::query()->count())
        ->toBe(0)
        ->and($manager->removed)
        ->toBe(['web', 'web']);
});

it('lists only one node rules in stable name order', function (): void {
    $manager = new FirewallFakeManager([]);
    $node = firewall_action_node();
    $other = firewall_action_node(
        name: 'other',
        publicHost: '192.0.2.21',
        wireguardIp: '10.44.0.4',
    );
    firewall_action_rule(node: $node, name: 'z-last');
    firewall_action_rule(node: $node, name: 'a-first');
    firewall_action_rule(node: $other, name: 'other');

    $rules = new ListFirewallRulesAction()->execute($node);

    expect($rules->pluck('name')->all())->toBe(['a-first', 'z-last']);
});

function firewall_action_node(
    string $name = 'app-dev',
    string $publicHost = '192.0.2.20',
    string $wireguardIp = '10.44.0.3',
): Node {
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => $publicHost,
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => $wireguardIp,
    ]);
}

function firewall_store_data(
    FirewallAction $action = FirewallAction::Allow,
    string $port = '443',
    string $name = 'web',
    string $source = '192.0.2.0/24',
    string $protocol = 'tcp',
): StoreFirewallRuleData {
    return new StoreFirewallRuleData(
        name: $name,
        action: $action,
        source: $source,
        protocol: $protocol,
        port: $port,
    );
}

function firewall_action_rule(Node $node, string $name = 'web'): FirewallRule
{
    return FirewallRule::query()->create([
        'node_id' => $node->id,
        'name' => $name,
        'action' => 'allow',
        'source' => 'any',
        'protocol' => 'tcp',
        'port' => '443',
        'status' => LifecycleStatus::Active,
    ]);
}

final class FirewallFakeManager implements FirewallManager
{
    /** @var list<string> */
    public array $converged = [];

    /** @var list<string> */
    public array $removed = [];

    /**
     * @param  list<FirewallBackendStatus>  $convergence
     * @param  list<FirewallBackendStatus>  $removals
     */
    public function __construct(
        private array $convergence,
        private array $removals = [],
    ) {}

    public function converge(FirewallRule $rule): FirewallBackendStatus
    {
        $this->converged[] = $rule->name;

        return array_shift($this->convergence) ?? throw new RuntimeException('No convergence result remains.');
    }

    public function remove(FirewallRule $rule): FirewallBackendStatus
    {
        $this->removed[] = $rule->name;

        return array_shift($this->removals) ?? throw new RuntimeException('No removal result remains.');
    }
}
