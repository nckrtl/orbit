<?php

declare(strict_types=1);

use App\Actions\Firewall\RemoveFirewallRuleAction;
use App\Actions\Firewall\StoreFirewallRuleAction;
use App\Data\Firewall\StoreFirewallRuleData;
use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Firewall\FirewallAction;
use App\Domain\Firewall\FirewallBackendStatus;
use App\Domain\Firewall\FirewallManager;
use App\Domain\Shared\LifecycleStatus;
use App\Models\FirewallRule;
use App\Models\Node;
use Illuminate\Support\Facades\Event;

final class FirewallRecordEventsFakeManager implements FirewallManager
{
    public function converge(FirewallRule $rule): FirewallBackendStatus
    {
        return FirewallBackendStatus::Active;
    }

    public function remove(FirewallRule $rule): FirewallBackendStatus
    {
        return FirewallBackendStatus::Absent;
    }
}

beforeEach(function (): void {
    app()->instance(FirewallManager::class, new FirewallRecordEventsFakeManager);
    $this->node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'public_ssh_port' => 22,
        'wireguard_ip' => '10.44.0.3',
    ]);
});

describe('FirewallRule record events', function (): void {
    it('broadcasts firewall.created when a new rule is stored', function (): void {
        Event::fake([RecordBroadcast::class]);

        $data = new StoreFirewallRuleData(
            name: 'private-web',
            action: FirewallAction::Allow,
            source: '192.0.2.0/24',
            protocol: 'tcp',
            port: '8443',
        );

        $result = new StoreFirewallRuleAction(app(FirewallManager::class))->execute($this->node, $data);

        expect($result['created'])->toBeTrue();
        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::FirewallCreated
                && $event->id === $result['rule']->id
                && $event->data['name'] === 'private-web',
        );
    });

    it('does not broadcast when re-asserting an existing rule with identical configuration', function (): void {
        $data = new StoreFirewallRuleData(
            name: 'private-web',
            action: FirewallAction::Allow,
            source: '192.0.2.0/24',
            protocol: 'tcp',
            port: '8443',
        );
        new StoreFirewallRuleAction(app(FirewallManager::class))->execute($this->node, $data);

        Event::fake([RecordBroadcast::class]);

        $result = new StoreFirewallRuleAction(app(FirewallManager::class))->execute($this->node, $data);

        expect($result['created'])->toBeFalse();
        Event::assertNotDispatched(RecordBroadcast::class);
    });

    it('broadcasts firewall.deleted with a minimal snapshot when a rule is removed', function (): void {
        $rule = FirewallRule::query()->create([
            'node_id' => $this->node->id,
            'name' => 'private-web',
            'action' => FirewallAction::Allow,
            'source' => '192.0.2.0/24',
            'protocol' => 'tcp',
            'port' => '8443',
            'status' => LifecycleStatus::Active,
        ]);

        Event::fake([RecordBroadcast::class]);

        new RemoveFirewallRuleAction(app(FirewallManager::class))->execute($rule);

        Event::assertDispatched(
            RecordBroadcast::class,
            fn (RecordBroadcast $event): bool => $event->type === RecordEventType::FirewallDeleted
                && $event->id === $rule->id
                && $event->data === ['id' => $rule->id, 'name' => 'private-web'],
        );
    });
});
