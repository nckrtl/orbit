<?php

declare(strict_types=1);

use App\Domain\Firewall\FirewallBackendStatus;
use App\Domain\Firewall\FirewallManager;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Activity;
use App\Models\FirewallRule;
use App\Models\Node;

beforeEach(function (): void {
    $this->firewall = new FirewallApiFakeManager;
    app()->instance(FirewallManager::class, $this->firewall);
    $this->node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $this->node->accessibleNodes()->attach($this->node);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
});

it('returns a stable unavailable error and retains failed intent when UFW is inactive', function (): void {
    $this->firewall->convergence = [FirewallBackendStatus::Inactive];

    $response = $this->postJson("/api/v1/nodes/{$this->node->id}/firewall-rules/allow", [
        'name' => 'private-web',
        'source' => '192.0.2.129/24',
        'protocol' => 'tcp',
        'port' => '0443',
    ]);

    $response
        ->assertServiceUnavailable()
        ->assertHeader('X-Orbit-Request-Id')
        ->assertJsonPath('error.code', 'firewall.backend_inactive')
        ->assertJsonPath('error.details.step', 'status');
    $rule = FirewallRule::query()->sole();

    expect($rule->status)
        ->toBe(LifecycleStatus::Failed)
        ->and($rule->failed_step)
        ->toBe('status')
        ->and($rule->error_code)
        ->toBe('firewall.backend_inactive');

    $this->assertDatabaseHas('activity_log', [
        'command' => 'firewall:allow',
        'subject_type' => FirewallRule::class,
        'subject_id' => $rule->id,
        'target_node_id' => $this->node->id,
        'caller_node_id' => $this->node->id,
        'status' => 'failed',
        'error_code' => 'firewall.backend_inactive',
    ]);
});

it('returns 422 for invalid names sources protocols and ports without persistence', function (
    array $payload,
    string $field,
): void {
    $response = $this->postJson("/api/v1/nodes/{$this->node->id}/firewall-rules/allow", [
        'name' => 'private-web',
        'source' => 'any',
        'protocol' => 'tcp',
        'port' => '443',
        ...$payload,
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    expect($response->json('error.details'))
        ->toHaveKey($field)
        ->and(FirewallRule::query()->count())
        ->toBe(0)
        ->and($this->firewall->converged)
        ->toBeEmpty();
})->with([
    'unsafe name' => [['name' => 'Private Web'], 'name'],
    'hostname source' => [['source' => 'example.test'], 'source'],
    'invalid CIDR' => [['source' => '192.0.2.1/33'], 'source'],
    'protocol outside tcp and udp' => [['protocol' => 'sctp'], 'protocol'],
    'zero port' => [['port' => '0'], 'port'],
    'reversed range' => [['port' => '9000:8000'], 'port'],
]);

it('returns 422 before persistence when a deny intersects public recovery SSH', function (): void {
    $this
        ->postJson("/api/v1/nodes/{$this->node->id}/firewall-rules/deny", [
            'name' => 'block-ssh',
            'source' => 'any',
            'protocol' => 'tcp',
            'port' => '1:1024',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'firewall.public_ssh_deny_forbidden');

    expect(FirewallRule::query()->count())->toBe(0);

    $activity = Activity::query()->where('command', 'firewall:deny')->sole();

    expect($activity)
        ->status->toBe('failed')
        ->error_code->toBe('firewall.public_ssh_deny_forbidden')
        ->subject_type->toBe(Node::class)
        ->subject_id->toBe($this->node->id)
        ->target_node_id->toBe($this->node->id);
});

it('returns 409 before UFW when allow and deny share a node source protocol and port', function (): void {
    $this
        ->postJson("/api/v1/nodes/{$this->node->id}/firewall-rules/allow", [
            'name' => 'private-web',
            'source' => 'any',
            'protocol' => 'tcp',
            'port' => '443',
        ])
        ->assertCreated();

    $this
        ->postJson("/api/v1/nodes/{$this->node->id}/firewall-rules/deny", [
            'name' => 'block-web',
            'source' => 'any',
            'protocol' => 'tcp',
            'port' => '443',
        ])
        ->assertConflict()
        ->assertJsonPath('error.code', 'firewall.action_conflict');

    $this
        ->postJson("/api/v1/nodes/{$this->node->id}/firewall-rules/allow", [
            'name' => 'private-web',
            'source' => 'any',
            'protocol' => 'tcp',
            'port' => '443',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.error_code', null);

    $this->assertDatabaseHas('firewall_rules', [
        'node_id' => $this->node->id,
        'name' => 'private-web',
        'action' => 'allow',
        'status' => 'active',
        'failed_step' => null,
        'error_code' => null,
    ]);
    $this->assertDatabaseMissing('firewall_rules', [
        'node_id' => $this->node->id,
        'name' => 'block-web',
    ]);

    $conflict = Activity::query()
        ->where('command', 'firewall:deny')
        ->where('status', 'failed')
        ->sole();

    expect($this->firewall->converged)->toBe(['private-web', 'private-web']);
    expect($conflict)
        ->error_code->toBe('firewall.action_conflict')
        ->subject_type->toBe(Node::class)
        ->subject_id->toBe($this->node->id)
        ->target_node_id->toBe($this->node->id)
        ->and($conflict->properties?->get('path'))
        ->toBe("api/v1/nodes/{$this->node->id}/firewall-rules/deny");
});

it('lists stable named intent and removes only the selected node rule', function (): void {
    $this->firewall->convergence = [FirewallBackendStatus::Active];
    $this
        ->postJson("/api/v1/nodes/{$this->node->id}/firewall-rules/allow", [
            'name' => 'private-web',
            'source' => 'any',
            'protocol' => 'tcp',
            'port' => '443',
        ])
        ->assertCreated();

    $this
        ->getJson("/api/v1/nodes/{$this->node->id}/firewall-rules")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'private-web')
        ->assertJsonMissingPath('data.0.backend_status');

    $this->firewall->removals = [FirewallBackendStatus::Inactive, FirewallBackendStatus::Absent];
    $this
        ->deleteJson("/api/v1/nodes/{$this->node->id}/firewall-rules/private-web")
        ->assertServiceUnavailable()
        ->assertJsonPath('error.code', 'firewall.backend_inactive')
        ->assertJsonPath('error.details.step', 'status');
    $this->assertDatabaseHas('firewall_rules', [
        'node_id' => $this->node->id,
        'name' => 'private-web',
        'status' => 'failed',
        'error_code' => 'firewall.backend_inactive',
    ]);

    $this
        ->deleteJson("/api/v1/nodes/{$this->node->id}/firewall-rules/private-web")
        ->assertOk()
        ->assertJsonPath('data.backend_status', 'absent');
    $this->assertDatabaseMissing('firewall_rules', ['node_id' => $this->node->id, 'name' => 'private-web']);

    expect(Activity::query()->where('command', 'firewall:remove')->count())->toBe(2);
});

it('returns 404 without exposing a rule that belongs to another node', function (): void {
    $other = Node::query()->create([
        'name' => 'other',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.21',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.4',
    ]);
    $other
        ->firewallRules()
        ->create([
            'name' => 'private-web',
            'action' => 'allow',
            'source' => 'any',
            'protocol' => 'tcp',
            'port' => '443',
            'status' => LifecycleStatus::Active,
        ]);

    $this
        ->deleteJson("/api/v1/nodes/{$this->node->id}/firewall-rules/private-web")
        ->assertNotFound();

    expect($this->firewall->removed)->toBeEmpty();
});

it('binds identical firewall rule names through the requested node', function (): void {
    $other = Node::query()->create([
        'name' => 'other-identical-name',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.22',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.5',
    ]);
    $otherRule = $other
        ->firewallRules()
        ->create([
            'name' => 'private-web',
            'action' => 'allow',
            'source' => 'any',
            'protocol' => 'tcp',
            'port' => '443',
            'status' => LifecycleStatus::Active,
        ]);
    $requestedRule = $this->node
        ->firewallRules()
        ->create([
            'name' => 'private-web',
            'action' => 'allow',
            'source' => 'any',
            'protocol' => 'tcp',
            'port' => '443',
            'status' => LifecycleStatus::Active,
        ]);

    $this
        ->deleteJson("/api/v1/nodes/{$this->node->id}/firewall-rules/private-web")
        ->assertOk();

    $this->assertDatabaseHas('firewall_rules', ['id' => $otherRule->id]);
    $this->assertDatabaseMissing('firewall_rules', ['id' => $requestedRule->id]);

    expect($this->firewall->removed)->toBe(['private-web']);
});

final class FirewallApiFakeManager implements FirewallManager
{
    /** @var list<FirewallBackendStatus> */
    public array $convergence = [];

    /** @var list<FirewallBackendStatus> */
    public array $removals = [];

    /** @var list<string> */
    public array $converged = [];

    /** @var list<string> */
    public array $removed = [];

    public function converge(FirewallRule $rule): FirewallBackendStatus
    {
        $this->converged[] = $rule->name;

        return array_shift($this->convergence) ?? FirewallBackendStatus::Active;
    }

    public function remove(FirewallRule $rule): FirewallBackendStatus
    {
        $this->removed[] = $rule->name;

        return array_shift($this->removals) ?? FirewallBackendStatus::Absent;
    }
}

describe('fleet firewall list', function (): void {
    beforeEach(function (): void {
        $this->other = Node::query()->create([
            'name' => 'app-prod',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.23',
            'public_ssh_port' => 22,
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.6',
        ]);

        foreach ([$this->node, $this->other] as $node) {
            $node->firewallRules()->create([
                'name' => 'private-web',
                'action' => 'allow',
                'source' => 'any',
                'protocol' => 'tcp',
                'port' => '443',
                'status' => LifecycleStatus::Active,
            ]);
        }
    });

    it('returns the rules of every Node the caller can reach in one request', function (): void {
        $this->node->accessibleNodes()->attach($this->other);

        $this
            ->getJson('/api/v1/firewall-rules')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.node_id', $this->node->id)
            ->assertJsonPath('data.0.node', 'app-dev')
            ->assertJsonPath('data.1.node_id', $this->other->id)
            ->assertJsonPath('data.1.node', 'app-prod')
            ->assertJsonMissingPath('data.0.backend_status')
            ->assertJsonStructure(['meta' => ['request_id']]);
    });

    it('leaves out the rules of Nodes the caller cannot reach', function (): void {
        $this
            ->getJson('/api/v1/firewall-rules')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.node_id', $this->node->id);
    });

    it('refuses a caller without any Node access', function (): void {
        $this->withServerVariables(['REMOTE_ADDR' => $this->other->wireguard_ip]);

        $this
            ->getJson('/api/v1/firewall-rules')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'node_access.required');
    });
});
