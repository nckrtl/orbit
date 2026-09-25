<?php

declare(strict_types=1);

use App\Actions\Nodes\RelocateNodeRoleAction;
use App\Domain\AppDev\PrivateDnsAnswerExpiry;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Metrics\MetricsReconcileDeferral;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Infrastructure\Metrics\NativeMetricsCredentialManager;
use App\Infrastructure\WebSocket\WebSocketFootprint;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRole;
use App\Models\Setting;
use Carbon\CarbonInterval as Duration;
use Illuminate\Support\Sleep;

describe(RelocateNodeRoleAction::class, function (): void {
    beforeEach(function (): void {
        $this->firewall = new RelocateNodeRoleFirewallFake;
        $this->dns = new RelocateNodeRoleDnsFake;
        $this->baselines = new RelocateNodeRoleBaselineFake;
        app()->instance(NodeRoleFirewallManager::class, $this->firewall);
        app()->instance(PrivateDnsManager::class, $this->dns);
        app()->instance(RoleBaselineConverger::class, $this->baselines);
    });

    it('transfers the singleton gateway assignment and leaves vpn on the source', function (): void {
        $source = relocate_role_node('gateway', '10.44.0.1');
        $target = relocate_role_node('beast', '10.44.0.11');
        $assignment = $source->roles()->create([
            'role' => RoleName::Gateway,
            'status' => LifecycleStatus::Active,
        ]);
        $source->roles()->create([
            'role' => RoleName::Vpn,
            'status' => LifecycleStatus::Active,
        ]);
        app(GatewayServingHost::class)->remember($source);

        $result = app(RelocateNodeRoleAction::class)->execute($target, RoleName::Gateway, force: true);

        expect($result->is($assignment))
            ->toBeTrue()
            ->and($result->node_id)
            ->toBe($target->id)
            ->and($result->status)
            ->toBe(LifecycleStatus::Active)
            ->and(NodeRole::query()->where('role', RoleName::Gateway)->count())
            ->toBe(1)
            ->and($source->roles()->where('role', RoleName::Vpn)->exists())
            ->toBeTrue()
            ->and($source->roles()->where('role', RoleName::Gateway)->exists())
            ->toBeFalse()
            ->and($this->firewall->events)
            ->toBe([
                "converge:gateway:{$target->name}",
                "remove:gateway:{$source->name}",
            ])
            ->and($this->dns->calls)
            ->toBe(1)
            ->and($this->baselines->converged)
            ->toBeEmpty()
            ->and(app(GatewayServingHost::class)->nodeId())
            ->toBe($source->id);
    });

    it('requires force before transferring the assignment', function (): void {
        $source = relocate_role_node('gateway', '10.44.0.1');
        $target = relocate_role_node('beast', '10.44.0.11');
        $source->roles()->create([
            'role' => RoleName::Gateway,
            'status' => LifecycleStatus::Active,
        ]);

        expect(fn () => app(RelocateNodeRoleAction::class)->execute($target, RoleName::Gateway))
            ->toThrow(NodeRoleValidationException::class, 'Use --force to relocate this node role.');

        expect($source->roles()->where('role', RoleName::Gateway)->exists())
            ->toBeTrue()
            ->and($this->firewall->events)
            ->toBeEmpty()
            ->and($this->dns->calls)
            ->toBe(0);
    });

    it('refuses roles that are not relocatable', function (): void {
        $target = relocate_role_node('beast', '10.44.0.11');

        expect(fn () => app(RelocateNodeRoleAction::class)->execute(
            $target,
            RoleName::Vpn,
            force: true,
        ))->toThrow(NodeRoleValidationException::class, 'Role [vpn] cannot be relocated.');
    });

    it('refuses when the target already holds the role and from is omitted', function (): void {
        $target = relocate_role_node('beast', '10.44.0.11');
        $target->roles()->create([
            'role' => RoleName::Gateway,
            'status' => LifecycleStatus::Active,
        ]);

        expect(fn () => app(RelocateNodeRoleAction::class)->execute(
            $target,
            RoleName::Gateway,
            force: true,
        ))->toThrow(NodeRoleValidationException::class, 'Role [gateway] is already assigned to node [beast].');
    });

    it('grants the new gateway access to the vpn and metrics nodes', function (): void {
        $source = relocate_role_node('vpn', '10.44.0.1');
        $target = relocate_role_node('gateway', '10.44.0.2');
        $metrics = relocate_role_node('beast', '10.44.0.9');
        $source->roles()->create([
            'role' => RoleName::Gateway,
            'status' => LifecycleStatus::Active,
        ]);
        $source->roles()->create([
            'role' => RoleName::Vpn,
            'status' => LifecycleStatus::Active,
        ]);
        $metrics->roles()->create([
            'role' => RoleName::Metrics,
            'status' => LifecycleStatus::Active,
        ]);
        app(GatewayServingHost::class)->remember($source);

        app(RelocateNodeRoleAction::class)->execute($target, RoleName::Gateway, force: true);

        expect(NodeAccess::query()->where('consumer_node_id', $target->id)->pluck('serving_node_id')->all())
            ->toEqualCanonicalizing([$source->id, $metrics->id])
            ->and($this->dns->calls)
            ->toBe(1);
    });

    it('refuses a target that already owns a conflicting role', function (RoleName $held): void {
        $source = relocate_role_node('gateway', '10.44.0.1');
        $target = relocate_role_node('beast', '10.44.0.11');
        $source->roles()->create([
            'role' => RoleName::Gateway,
            'status' => LifecycleStatus::Active,
        ]);
        $cluster = Cluster::query()->create(['name' => "relocate-onto-{$held->value}"]);
        $target->update(['cluster_id' => $cluster->id]);
        $target->roles()->create([
            'role' => $held,
            'status' => LifecycleStatus::Active,
            'cluster_id' => $held === RoleName::Ingress ? $cluster->id : null,
        ]);

        expect(fn () => app(RelocateNodeRoleAction::class)->execute(
            $target,
            RoleName::Gateway,
            force: true,
        ))->toThrow(NodeRoleValidationException::class, "Role [gateway] conflicts with assigned role [{$held->value}].");

        expect($source->roles()->where('role', RoleName::Gateway)->exists())->toBeTrue()
            ->and($target->roles()->where('role', RoleName::Gateway)->exists())->toBeFalse();
    })->with([
        'app-dev' => [RoleName::AppDev],
        'Ingress' => [RoleName::Ingress],
    ]);

    it('transfers websocket, copies credentials, and retracts the source baseline', function (): void {
        $source = relocate_role_node('beast', '10.44.0.1');
        $target = relocate_role_node('services', '10.44.0.11');
        $assignment = $source->roles()->create([
            'role' => RoleName::WebSocket,
            'status' => LifecycleStatus::Active,
        ]);
        $credentials = app(WebSocketCredentialManager::class)->ensure($source);

        $result = app(RelocateNodeRoleAction::class)->execute($target, RoleName::WebSocket, force: true);

        expect($result->is($assignment))
            ->toBeTrue()
            ->and($result->node_id)
            ->toBe($target->id)
            ->and(NodeRole::query()->where('role', RoleName::WebSocket)->count())
            ->toBe(1)
            ->and($source->roles()->where('role', RoleName::WebSocket)->exists())
            ->toBeFalse()
            ->and($this->baselines->converged)
            ->toBe(["websocket:{$target->name}"])
            ->and($this->baselines->removed)
            ->toBe([['role' => 'websocket', 'node' => $source->name, 'purge_data' => false]])
            ->and($this->baselines->removedAssignmentIds)
            ->toBe([$assignment->id])
            ->and($this->firewall->events)
            ->toBeEmpty();

        $moved = app(WebSocketCredentialManager::class)->ensure($target);

        expect($moved->appId)
            ->toBe($credentials->appId)
            ->and($moved->appKey)
            ->toBe($credentials->appKey)
            ->and($moved->appSecret)
            ->toBe($credentials->appSecret)
            ->and($moved->laravelAppKey)
            ->toBe($credentials->laravelAppKey)
            ->and(Setting::query()->where('scope_type', 'node')->where('scope_id', $source->id)->count())
            ->toBe(0);
    });

    it('reconciles Metrics once, after the target converges and the source withdraws', function (): void {
        $source = relocate_role_node('beast', '10.44.0.1');
        $target = relocate_role_node('services', '10.44.0.11');
        $source->roles()->create(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Active]);
        app(WebSocketCredentialManager::class)->ensure($source);
        Sleep::fake();
        $metrics = new RelocateNodeRoleMetricsFake($this->baselines);
        app()->instance(MetricsFleetReconciler::class, $metrics);
        $this->baselines->metrics = $metrics;

        app(RelocateNodeRoleAction::class)->execute($target, RoleName::WebSocket, force: true);

        expect($metrics->reconciles)->toBe([[
            'converged' => ["websocket:{$target->name}"],
            'removed' => [['role' => 'websocket', 'node' => $source->name, 'purge_data' => false]],
        ]]);
    });

    it('leaves the source withdrawal ahead of a failing Metrics reconcile and names the command that retries it', function (): void {
        $source = relocate_role_node('beast', '10.44.0.1');
        $target = relocate_role_node('services', '10.44.0.11');
        $source->roles()->create(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Active]);
        app(WebSocketCredentialManager::class)->ensure($source);
        Sleep::fake();
        $metrics = new RelocateNodeRoleMetricsFake($this->baselines);
        $metrics->failure = new ResourceOperationException(
            'metrics.prometheus_configuration_check_timed_out',
            'A Metrics command on node [app-dev] did not finish within 60 seconds.',
            504,
        );
        app()->instance(MetricsFleetReconciler::class, $metrics);
        $this->baselines->metrics = $metrics;

        expect(fn () => app(RelocateNodeRoleAction::class)->execute($target, RoleName::WebSocket, force: true))
            ->toThrow(function (ResourceOperationException $exception) use ($source, $target): void {
                expect($exception->errorCode)->toBe('metrics.prometheus_configuration_check_timed_out')
                    ->and($exception->status)->toBe(504)
                    ->and($exception->getMessage())->toEndWith("Run `orbit node:role:relocate {$target->name} websocket --from {$source->name} --force` to finish it once node [{$source->name}] is reachable.");
            });

        expect($this->baselines->removed)->toBe([['role' => 'websocket', 'node' => $source->name, 'purge_data' => false]])
            ->and(app(MetricsReconcileDeferral::class)->defers())->toBeFalse();
    });

    it('withdraws websocket from the source only after the target serves and cached DNS answers expire', function (): void {
        $source = relocate_role_node('beast', '10.44.0.1');
        $target = relocate_role_node('services', '10.44.0.11');
        $source->roles()->create(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Active]);
        app(WebSocketCredentialManager::class)->ensure($source);
        $atSleep = [];
        Sleep::whenFakingSleep(function (Duration $duration) use (&$atSleep): void {
            $atSleep[] = [
                'seconds' => (int) $duration->totalSeconds,
                'converged' => $this->baselines->converged,
                'removed' => $this->baselines->removed,
            ];
        });

        app(RelocateNodeRoleAction::class)->execute($target, RoleName::WebSocket, force: true);

        expect($atSleep)->toBe([[
            'seconds' => PrivateDnsAnswerExpiry::WithdrawalGraceSeconds,
            'converged' => ["websocket:{$target->name}"],
            'removed' => [],
        ]])
            ->and($this->baselines->removed)->toBe([['role' => 'websocket', 'node' => $source->name, 'purge_data' => false]]);
    });

    it('reports a websocket move whose source withdrawal failed as incomplete, and finishes it on retry', function (): void {
        $source = relocate_role_node('beast', '10.44.0.1');
        $target = relocate_role_node('services', '10.44.0.11');
        $source->roles()->create(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Active]);
        app(WebSocketCredentialManager::class)->ensure($source);
        Sleep::fake();
        $this->baselines->removeFailure = new NodeRoleOperationException(
            'websocket-caddy',
            'node_role.convergence_failed',
            'websocket.caddy_publication_failed',
            'The Caddy build for Node [beast] failed at stage [gateway-lock]: Another Caddy build for this Node held the lock for 30 seconds.',
        );

        expect(fn () => app(RelocateNodeRoleAction::class)->execute($target, RoleName::WebSocket, force: true))
            ->toThrow(function (NodeRoleOperationException $exception) use ($source, $target): void {
                expect($exception->underlyingErrorCode)->toBe('websocket.caddy_publication_failed')
                    ->and($exception->getMessage())->toStartWith("Role [websocket] now runs on node [{$target->name}], but the move from node [{$source->name}] is incomplete:")
                    ->and($exception->getMessage())->toEndWith("Run `orbit node:role:relocate {$target->name} websocket --from {$source->name} --force` to finish it once node [{$source->name}] is reachable.");
            });

        expect($this->baselines->removed)->toBe([]);

        app(RelocateNodeRoleAction::class)->execute($target, RoleName::WebSocket, force: true, from: $source);

        expect($this->baselines->removed)->toBe([['role' => 'websocket', 'node' => $source->name, 'purge_data' => false]]);
    });

    it('names the command that finishes a move whose target converge fails', function (): void {
        $source = relocate_role_node('beast', '10.44.0.1');
        $target = relocate_role_node('services', '10.44.0.11');
        $source->roles()->create(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Active]);
        app(WebSocketCredentialManager::class)->ensure($source);
        $this->baselines->convergeFailure = new ResourceOperationException('metrics.service_rollback_failed', 'Service metrics rollback failed.', 502);

        expect(fn () => app(RelocateNodeRoleAction::class)->execute($target, RoleName::WebSocket, force: true))
            ->toThrow(function (ResourceOperationException $exception) use ($source, $target): void {
                expect($exception->errorCode)->toBe('metrics.service_rollback_failed')
                    ->and($exception->status)->toBe(502)
                    ->and($exception->getMessage())->toBe(
                        "Role [websocket] now runs on node [{$target->name}], but the move from node [{$source->name}] is incomplete: Service metrics rollback failed."
                        ." Run `orbit node:role:relocate {$target->name} websocket --from {$source->name} --force` to finish it once node [{$source->name}] is reachable.",
                    );
            });

        expect($this->baselines->removed)->toBe([]);
    });

    it('transfers metrics and copies missing grafana credentials', function (): void {
        $source = relocate_role_node('beast', '10.44.0.1');
        $target = relocate_role_node('services', '10.44.0.11');
        $assignment = $source->roles()->create([
            'role' => RoleName::Metrics,
            'status' => LifecycleStatus::Active,
        ]);
        $settings = app(SettingRepository::class);
        $settings->put(
            relocate_role_scope($source),
            NativeMetricsCredentialManager::ActivePasswordKey,
            'source-grafana-password',
            SettingValueProtection::Secret,
        );

        $result = app(RelocateNodeRoleAction::class)->execute($target, RoleName::Metrics, force: true);

        expect($result->is($assignment))
            ->toBeTrue()
            ->and($result->node_id)
            ->toBe($target->id)
            ->and($this->baselines->converged)
            ->toBe(["metrics:{$target->name}"])
            ->and($this->baselines->removed)
            ->toBe([['role' => 'metrics', 'node' => $source->name, 'purge_data' => false]])
            ->and($settings->get(relocate_role_scope($target), NativeMetricsCredentialManager::ActivePasswordKey))
            ->toBe('source-grafana-password')
            ->and($settings->get(relocate_role_scope($source), NativeMetricsCredentialManager::ActivePasswordKey))
            ->toBeNull();
    });

    it('honors optional from when it names the current holder', function (): void {
        $source = relocate_role_node('beast', '10.44.0.1');
        $target = relocate_role_node('services', '10.44.0.11');
        $source->roles()->create([
            'role' => RoleName::WebSocket,
            'status' => LifecycleStatus::Active,
        ]);

        $result = app(RelocateNodeRoleAction::class)->execute(
            $target,
            RoleName::WebSocket,
            force: true,
            from: $source,
        );

        expect($result->node_id)->toBe($target->id);
    });

    it('refuses from when it is not the current holder', function (): void {
        $source = relocate_role_node('beast', '10.44.0.1');
        $other = relocate_role_node('other', '10.44.0.8');
        $target = relocate_role_node('services', '10.44.0.11');
        $source->roles()->create([
            'role' => RoleName::WebSocket,
            'status' => LifecycleStatus::Active,
        ]);

        expect(fn () => app(RelocateNodeRoleAction::class)->execute(
            $target,
            RoleName::WebSocket,
            force: true,
            from: $other,
        ))->toThrow(
            NodeRoleValidationException::class,
            'Role [websocket] is assigned to node [beast], not [other].',
        );
    });

    it('moves leftover websocket resources when the destination already holds the role', function (): void {
        $leftover = relocate_role_node('beast', '10.44.0.1');
        $target = relocate_role_node('services', '10.44.0.11');
        $assignment = $target->roles()->create([
            'role' => RoleName::WebSocket,
            'status' => LifecycleStatus::Active,
        ]);
        $settings = app(SettingRepository::class);
        $settings->put(
            relocate_role_scope($leftover),
            WebSocketFootprint::SettingKeyAppId,
            'leftover-app-id',
            SettingValueProtection::Plain,
        );
        $settings->put(
            relocate_role_scope($leftover),
            WebSocketFootprint::SettingKeyAppKey,
            'leftover-app-key',
            SettingValueProtection::Plain,
        );
        $settings->put(
            relocate_role_scope($leftover),
            WebSocketFootprint::SettingKeyAppSecret,
            'leftover-app-secret',
            SettingValueProtection::Secret,
        );
        $settings->put(
            relocate_role_scope($leftover),
            WebSocketFootprint::SettingKeyAppKeyLaravel,
            'base64:leftover',
            SettingValueProtection::Secret,
        );

        $result = app(RelocateNodeRoleAction::class)->execute(
            $target,
            RoleName::WebSocket,
            force: true,
            from: $leftover,
        );

        expect($result->is($assignment))
            ->toBeTrue()
            ->and($result->node_id)
            ->toBe($target->id)
            ->and(NodeRole::query()->where('role', RoleName::WebSocket)->count())
            ->toBe(1)
            ->and($this->baselines->converged)
            ->toBe(["websocket:{$target->name}"])
            ->and($this->baselines->removed)
            ->toBe([['role' => 'websocket', 'node' => $leftover->name, 'purge_data' => false]])
            ->and($settings->get(relocate_role_scope($target), WebSocketFootprint::SettingKeyAppId))
            ->toBe('leftover-app-id')
            ->and($settings->get(relocate_role_scope($leftover), WebSocketFootprint::SettingKeyAppId))
            ->toBeNull();
    });

    it('does not overwrite destination websocket credentials when leftover source has different values', function (): void {
        $leftover = relocate_role_node('beast', '10.44.0.1');
        $target = relocate_role_node('services', '10.44.0.11');
        $target->roles()->create([
            'role' => RoleName::WebSocket,
            'status' => LifecycleStatus::Active,
        ]);
        $kept = app(WebSocketCredentialManager::class)->ensure($target);
        app(WebSocketCredentialManager::class)->ensure($leftover);

        app(RelocateNodeRoleAction::class)->execute(
            $target,
            RoleName::WebSocket,
            force: true,
            from: $leftover,
        );

        $current = app(WebSocketCredentialManager::class)->ensure($target);

        expect($current->appId)
            ->toBe($kept->appId)
            ->and($current->appSecret)
            ->toBe($kept->appSecret)
            ->and(Setting::query()->where('scope_type', 'node')->where('scope_id', $leftover->id)->count())
            ->toBe(0);
    });
});

function relocate_role_node(string $name, string $wireguardIp): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'x86_64',
        'public_ssh_host' => $name.'.example.test',
        'user' => 'orbit',
        'wireguard_ip' => $wireguardIp,
    ]);
}

function relocate_role_scope(Node $node): SettingScope
{
    return new SettingScope(SettingScopeType::Node, $node->id);
}

final class RelocateNodeRoleFirewallFake implements NodeRoleFirewallManager
{
    /** @var list<string> */
    public array $events = [];

    public function convergeBase(Node $node, string $managedUser): void {}

    public function converge(Node $node, RoleName $role, string $managedUser): void
    {
        $this->events[] = "converge:{$role->value}:{$node->name}";
    }

    public function remove(Node $node, RoleName $role, string $managedUser): void
    {
        $this->events[] = "remove:{$role->value}:{$node->name}";
    }

    public function restorePublicSsh(Node $node, string $managedUser): void {}

    public function trustWireGuardMembers(Node $node, string $managedUser): void {}
}

final class RelocateNodeRoleDnsFake implements PrivateDnsManager
{
    public int $calls = 0;

    public function converge(?Node $pendingNode = null): void
    {
        $this->calls++;
    }
}

final class RelocateNodeRoleBaselineFake implements RoleBaselineConverger
{
    /** @var list<string> */
    public array $converged = [];

    /** @var list<array{role: string, node: string, purge_data: bool}> */
    public array $removed = [];

    /** @var list<int|null> */
    public array $removedAssignmentIds = [];

    public ?ResourceOperationException $convergeFailure = null;

    /** Requests a fleet Metrics reconcile after each change, as the native converger does. */
    public ?MetricsFleetReconciler $metrics = null;

    public function converge(Node $node, NodeRole $assignment): void
    {
        if ($this->convergeFailure instanceof ResourceOperationException) {
            throw $this->convergeFailure;
        }

        $this->converged[] = "{$assignment->role->value}:{$node->name}";
        $this->reconcileMetrics();
    }

    private function reconcileMetrics(): void
    {
        if ($this->metrics instanceof MetricsFleetReconciler && ! app(MetricsReconcileDeferral::class)->defers()) {
            $this->metrics->reconcile();
        }
    }

    public ?NodeRoleOperationException $removeFailure = null;

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        if ($this->removeFailure instanceof NodeRoleOperationException) {
            $failure = $this->removeFailure;
            $this->removeFailure = null;

            throw $failure;
        }

        $this->removed[] = [
            'role' => $assignment->role->value,
            'node' => $node->name,
            'purge_data' => $purgeData,
        ];
        $this->removedAssignmentIds[] = $assignment->id;
        $this->reconcileMetrics();
    }

    public function removeUnreachable(Node $node, NodeRole $assignment): void {}
}

final class RelocateNodeRoleMetricsFake implements MetricsFleetReconciler
{
    /** @var list<array{converged: list<string>, removed: list<array{role: string, node: string, purge_data: bool}>}> */
    public array $reconciles = [];

    public ?ResourceOperationException $failure = null;

    public function __construct(private readonly RelocateNodeRoleBaselineFake $baselines) {}

    public function reconcile(): void
    {
        $this->reconciles[] = ['converged' => $this->baselines->converged, 'removed' => $this->baselines->removed];

        if ($this->failure instanceof ResourceOperationException) {
            throw $this->failure;
        }
    }

    public function retire(Node $node): void {}
}
