<?php

declare(strict_types=1);

use App\Support\Realtime\RealtimeEvent;
use App\Support\Tui\State;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;
use Orbit\Sdk\Responses\AppInstances\AppInstancesResponse;
use Orbit\Sdk\Responses\Apps\AppIdentityResponse;
use Orbit\Sdk\Responses\Apps\AppResponse;
use Orbit\Sdk\Responses\Apps\AppsResponse;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionsResponse;
use Orbit\Sdk\Responses\Firewall\FirewallRuleResponse;
use Orbit\Sdk\Responses\Firewall\FirewallRulesResponse;
use Orbit\Sdk\Responses\Nodes\NodeIdentityResponse;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Orbit\Sdk\Responses\Nodes\NodesResponse;
use Orbit\Sdk\Responses\Processes\ProcessesResponse;
use Orbit\Sdk\Responses\Processes\ProcessResponse;
use Orbit\Sdk\Responses\Schedules\SchedulesResponse;

describe(State::class, function (): void {
    it('maps every loaded family into the row shape Screen renders', function (): void {
        $state = tui_test_state();

        expect($state->nodes)->toHaveCount(1)
            ->and($state->nodes[0]['name'])->toBe('beast')
            ->and($state->apps[0]['slug'])->toBe('charlie-shop')
            ->and($state->instances[0]['app']['slug'])->toBe('charlie-shop')
            ->and($state->instances[0]['node']['name'])->toBe('beast')
            ->and($state->processes[0]['name'])->toBe('horizon')
            ->and($state->schedules[0]['name'])->toBe('backup')
            ->and($state->firewall[0]['name'])->toBe('ssh')
            ->and($state->databases[0]['slug'])->toBe('charlie-shop');
    });

    it('flips a process row runtime_status when a process.status event arrives', function (): void {
        $state = tui_test_state();

        expect($state->processes[0]['runtime_status'])->toBe('active');

        $event = RealtimeEvent::fromChannelPayload('event', [
            'type' => 'process.status',
            'id' => 1,
            'at' => '2026-09-18T10:00:00+00:00',
            'data' => ['id' => 1, 'runtime_status' => 'inactive'],
        ]);

        $state->applyEvent($event);

        expect($state->processes[0]['runtime_status'])->toBe('inactive')
            // Fields the event did not carry stay as they were.
            ->and($state->processes[0]['name'])->toBe('horizon');
    });

    it('removes a row on a *.deleted event', function (): void {
        $state = tui_test_state();

        $event = RealtimeEvent::fromChannelPayload('event', [
            'type' => 'firewall.deleted',
            'id' => 2,
            'at' => '2026-09-18T10:00:00+00:00',
            'data' => ['id' => 1],
        ]);

        $state->applyEvent($event);

        expect($state->firewall)->toBeEmpty();
    });

    it('adds a new row on a *.created event for an unknown id', function (): void {
        $state = tui_test_state();

        $event = RealtimeEvent::fromChannelPayload('event', [
            'type' => 'node.created',
            'id' => 3,
            'at' => '2026-09-18T10:00:00+00:00',
            'data' => ['id' => 2, 'name' => 'shark', 'status' => 'provisioning', 'roles' => []],
        ]);

        $state->applyEvent($event);

        expect($state->nodes)->toHaveCount(2)
            ->and($state->nodes[1]['name'])->toBe('shark');
    });

    it('keeps a node.sample event out of the node collection and into nodeMetrics()', function (): void {
        $state = tui_test_state();

        expect($state->nodeMetrics(1))->toBeNull();

        $event = RealtimeEvent::fromChannelPayload('event', [
            'type' => 'node.sample',
            'id' => 4,
            'at' => '2026-09-18T10:00:00+00:00',
            'data' => [
                'node_id' => 1,
                'cores' => [0.1, 0.2],
                'mem' => [1.0, 8.0],
                'swap' => [0.0, 2.0],
                'uptime' => '3 days',
                'disks' => [['/', 10.0, 80.0]],
            ],
        ]);

        $state->applyEvent($event);

        expect($state->nodes[0]['status'])->toBe('active') // The node row itself did not change.
            ->and($state->nodeMetrics(1))->toBe([
                'cores' => [0.1, 0.2],
                'mem' => [1.0, 8.0],
                'swap' => [0.0, 2.0],
                'uptime' => '3 days',
                'disks' => [['/', 10.0, 80.0]],
            ]);
    });

    it('marks a family off-count when a row stops matching its desired state', function (): void {
        $state = tui_test_state();

        expect($state->counts()['Processes'])->toBe([1, 0]);

        $state->applyEvent(RealtimeEvent::fromChannelPayload('event', [
            'type' => 'process.status',
            'id' => 5,
            'at' => '2026-09-18T10:00:00+00:00',
            'data' => ['id' => 1, 'runtime_status' => 'inactive'],
        ]));

        expect($state->counts()['Processes'])->toBe([1, 1])
            ->and($state->attentionRows())->toHaveCount(1)
            ->and($state->attentionRows()[0]['label'])->toBe('Process');
    });

    it('counts Databases alongside every other family, always off-count 0 since a connection has no health concept today', function (): void {
        $state = tui_test_state();

        expect($state->counts()['Databases'])->toBe([1, 0]);
    });

    it('lists the fleet-wide firewall rules, narrowed by the node filter and ignoring the app filter since rules are not app-scoped', function (): void {
        $state = tui_test_state();

        expect($state->listRows('firewall', null, null))->toHaveCount(1)
            ->and($state->listRows('firewall', 'beast', null))->toHaveCount(1)
            ->and($state->listRows('firewall', 'beast', 'charlie-shop'))->toHaveCount(1)
            ->and($state->listRows('firewall', 'shark', null))->toBeEmpty();
    });
});

describe('State health vocabulary', function (): void {
    it('treats a systemd process as healthy only when runtime_status matches desired_state in systemd\'s own vocabulary, not when the strings are equal', function (): void {
        expect(State::processHealthy(['runtime' => 'systemd', 'desired_state' => 'running', 'runtime_status' => 'active']))->toBeTrue()
            ->and(State::processHealthy(['runtime' => 'systemd', 'desired_state' => 'stopped', 'runtime_status' => 'inactive']))->toBeTrue()
            // The literal-comparison bug: runtime_status never equals desired_state's own
            // vocabulary, so a healthy stopped process must not be flagged.
            ->and(State::processHealthy(['runtime' => 'systemd', 'desired_state' => 'running', 'runtime_status' => 'running']))->toBeFalse()
            ->and(State::processHealthy(['runtime' => 'systemd', 'desired_state' => 'running', 'runtime_status' => 'inactive']))->toBeFalse()
            ->and(State::processHealthy(['runtime' => 'systemd', 'desired_state' => 'running', 'runtime_status' => 'activating']))->toBeFalse()
            ->and(State::processHealthy(['runtime' => 'systemd', 'desired_state' => 'stopped', 'runtime_status' => 'active']))->toBeFalse()
            ->and(State::processHealthy(['runtime' => 'systemd', 'desired_state' => 'running', 'runtime_status' => 'failed']))->toBeFalse();
    });

    it('treats a Docker process as healthy in Docker\'s own vocabulary ("running"/"exited"), confirmed against a live fleet where a healthy running container reports runtime_status "running", never "active"', function (): void {
        expect(State::processHealthy(['runtime' => 'docker', 'desired_state' => 'running', 'runtime_status' => 'running']))->toBeTrue()
            ->and(State::processHealthy(['runtime' => 'docker', 'desired_state' => 'stopped', 'runtime_status' => 'exited']))->toBeTrue()
            // Applying systemd's vocabulary to a Docker process would wrongly flag a healthy
            // running container, since Docker never reports "active".
            ->and(State::processHealthy(['runtime' => 'docker', 'desired_state' => 'running', 'runtime_status' => 'active']))->toBeFalse()
            ->and(State::processHealthy(['runtime' => 'docker', 'desired_state' => 'stopped', 'runtime_status' => 'running']))->toBeFalse()
            ->and(State::processHealthy(['runtime' => 'docker', 'desired_state' => 'running', 'runtime_status' => 'restarting']))->toBeFalse();
    });

    it('offers stop for a running process in its own runtime\'s vocabulary', function (): void {
        expect(State::processRuntimeIsActive(['runtime' => 'systemd', 'runtime_status' => 'active']))->toBeTrue()
            ->and(State::processRuntimeIsActive(['runtime' => 'systemd', 'runtime_status' => 'inactive']))->toBeFalse()
            ->and(State::processRuntimeIsActive(['runtime' => 'docker', 'runtime_status' => 'running']))->toBeTrue()
            ->and(State::processRuntimeIsActive(['runtime' => 'docker', 'runtime_status' => 'exited']))->toBeFalse();
    });

    it('treats a firewall rule as healthy at status active, never the nonexistent "applied"', function (): void {
        expect(State::firewallHealthy(['status' => 'active']))->toBeTrue()
            ->and(State::firewallHealthy(['status' => 'provisioning']))->toBeFalse()
            ->and(State::firewallHealthy(['status' => 'failed']))->toBeFalse()
            ->and(State::firewallHealthy(['status' => 'removing']))->toBeFalse();
    });

    it('treats a node as healthy only at status active', function (): void {
        expect(State::nodeHealthy(['status' => 'active']))->toBeTrue()
            ->and(State::nodeHealthy(['status' => 'provisioning']))->toBeFalse()
            ->and(State::nodeHealthy(['status' => 'failed']))->toBeFalse();
    });

    it('treats an App instance as healthy only at status active', function (): void {
        expect(State::instanceHealthy(['status' => 'active']))->toBeTrue()
            ->and(State::instanceHealthy(['status' => 'reserved']))->toBeFalse()
            ->and(State::instanceHealthy(['status' => 'removing']))->toBeFalse();
    });

    it('treats a schedule as healthy when its timer is enabled and provisioning did not fail', function (): void {
        expect(State::scheduleHealthy(['desired_timer_state' => 'enabled', 'status' => 'active']))->toBeTrue()
            ->and(State::scheduleHealthy(['desired_timer_state' => 'disabled', 'status' => 'active']))->toBeFalse()
            ->and(State::scheduleHealthy(['desired_timer_state' => 'enabled', 'status' => 'failed']))->toBeFalse();
    });

    it('treats a deployment as healthy only once it has succeeded', function (): void {
        expect(State::deploymentHealthy(['status' => 'succeeded']))->toBeTrue()
            ->and(State::deploymentHealthy(['status' => 'running']))->toBeFalse()
            ->and(State::deploymentHealthy(['status' => 'failed']))->toBeFalse();
    });
});

describe('State::load() concurrency', function (): void {
    it('batches the per-node and per-instance process lists, and the per-node firewall list, through $sendMany, in request order', function (): void {
        $nodeA = new NodeResponse(id: 1, name: 'beast', status: 'active', publicSshHost: '10.0.0.1', publicSshPort: 22, user: 'root', wireguardIp: '10.44.0.1', roles: [], requestId: 'r');
        $nodeB = new NodeResponse(id: 2, name: 'shark', status: 'active', publicSshHost: '10.0.0.2', publicSshPort: 22, user: 'root', wireguardIp: '10.44.0.2', roles: [], requestId: 'r');
        $app = new AppResponse(id: 1, name: 'Charlie Shop', slug: 'charlie-shop', repositoryUrl: 'https://example.test/charlie-shop.git', defaultBranch: 'main', root: null, defaults: null, requestId: 'r');
        $instance = new AppInstanceResponse(
            id: 10, appId: 1, nodeId: 1, app: new AppIdentityResponse(1, 'Charlie Shop', 'charlie-shop'), node: new NodeIdentityResponse(1, 'beast'),
            name: 'dev', environment: 'production', sourceLayout: 'flat', checkoutPath: '/srv/charlie-shop', productionUser: null, productionHome: null,
            root: null, effectiveRoot: null, selectedBranch: 'main', branchOverride: null, migrationRequired: false, startingCommit: null, detached: false,
            status: 'active', route: null, domain: 'charlie-shop.test', url: null, removal: null, transfer: null, deploySteps: [], requestId: 'r',
        );

        $batches = [];
        $sendMany = function (array $requests, string $responseClass) use (&$batches): array {
            $batches[] = [$responseClass, count($requests)];

            if ($responseClass === ProcessesResponse::class) {
                // Simulate the pool returning results in request order: node beast, node
                // shark, instance dev.
                return [
                    new ProcessesResponse([], 'r'),
                    new ProcessesResponse([], 'r'),
                    new ProcessesResponse([ProcessResponse::fromGatewayData(['id' => 1, 'target_type' => 'instance', 'target_id' => 10, 'name' => 'horizon', 'runtime' => 'systemd', 'working_directory' => '/srv', 'restart_policy' => 'always', 'keep_alive' => true, 'desired_state' => 'running', 'status' => 'active', 'runtime_status' => 'active', 'failed_step' => null, 'error_code' => null], 'r')], 'r'),
                ];
            }

            // FirewallRulesResponse: one rule on beast, none on shark.
            return [
                new FirewallRulesResponse([new FirewallRuleResponse(id: 1, nodeId: 1, node: 'beast', name: 'ssh', action: 'allow', source: '0.0.0.0/0', protocol: 'tcp', port: '22', status: 'active', backendStatus: null, failedStep: null, errorCode: null, requestId: 'r')], 'r'),
                new FirewallRulesResponse([], 'r'),
            ];
        };

        $send = function (object $request, string $responseClass) use ($nodeA, $nodeB, $app, $instance): object {
            return match ($responseClass) {
                NodesResponse::class => new NodesResponse([$nodeA, $nodeB], 'r'),
                AppsResponse::class => new AppsResponse([$app], 'r'),
                AppInstancesResponse::class => new AppInstancesResponse([$instance], 'r'),
                SchedulesResponse::class => SchedulesResponse::fromGatewayData([], 'r'),
                DatabaseConnectionsResponse::class => new DatabaseConnectionsResponse([], 'r'),
                default => throw new RuntimeException("Unexpected request for {$responseClass}."),
            };
        };

        $state = new State;
        $state->load($send, $sendMany);

        // Two batched calls: one process list request per node (2) plus one per instance (1),
        // and one firewall list request per node (2) — never one request at a time.
        expect($batches)->toBe([[ProcessesResponse::class, 3], [FirewallRulesResponse::class, 2]])
            ->and($state->processes)->toHaveCount(1)
            ->and($state->processes[0]['name'])->toBe('horizon')
            ->and($state->firewall)->toHaveCount(1)
            ->and($state->firewall[0]['name'])->toBe('ssh');
    });

    it('falls back to one request at a time when $sendMany is omitted, and still produces the same rows', function (): void {
        $state = tui_test_state();

        expect($state->processes[0]['name'])->toBe('horizon')
            ->and($state->firewall[0]['name'])->toBe('ssh');
    });
});
