<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeObservation;
use App\Domain\Nodes\NodeProvisioningIdentity;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use Orbit\Sdk\Requests\Nodes\AddNodeRequest;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\Nodes\ShowNodeRequest;
use Tests\Support\FakeToolManagerMaterializer;

/**
 * Records the node family responses that the CLI replays. Data is deterministic on purpose:
 * the operator Node is id 1, the app-dev Node is id 2, and the request id is fixed.
 */
describe('node response fixtures', function (): void {
    beforeEach(function (): void {
        app()->instance(ToolManagerMaterializer::class, new FakeToolManagerMaterializer);
        app()->instance(RoleBaselineConverger::class, new class implements RoleBaselineConverger
        {
            public function converge(Node $node, NodeRole $assignment): void {}

            public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}

            public function removeUnreachable(Node $node, NodeRole $assignment): void {}
        });
        app()->instance(NodeConverger::class, new class implements NodeConverger
        {
            public function converge(
                Node $node,
                NodeProvisioningIdentity $identity,
                ?string $expectedSshHostFingerprint = null,
                bool $rolelessOperator = false,
            ): NodeObservation {
                return new NodeObservation('x86_64', 'Ubuntu 26.04.1 LTS');
            }
        });

        $operator = Node::query()->create([
            'name' => 'gateway',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'os_version' => 'Ubuntu 26.04.1 LTS',
            'public_ssh_host' => '192.0.2.2',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.1',
            'ssh_host_fingerprint' => 'SHA256:'.str_repeat('G', 43),
        ]);
        $this->markAsGateway($operator);
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);
        $this->withHeader('X-Orbit-Request-Id', fixture_request_id());
    });

    it('records the node list and show responses', function (): void {
        $cluster = Cluster::query()->create(['name' => 'development']);
        $node = Node::query()->create([
            'name' => 'app-dev',
            'status' => LifecycleStatus::Active,
            'cluster_id' => $cluster->id,
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'os_version' => 'Ubuntu 26.04.1 LTS',
            'tld' => 'app-dev.orbit',
            'public_ssh_host' => '94.237.40.75',
            'public_ssh_port' => 22,
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.3',
            'lan_ip' => '10.0.0.3',
            'wireguard_public_key' => 'app-dev-public-key',
            'wireguard_endpoint_override' => '10.0.0.2:51820',
            'dns_server_override' => '10.0.0.2',
            'ssh_host_fingerprint' => 'SHA256:4dxvKOYfyTcqJHYoxamTSu9bYYI5KE3xYWQPCAmeUTo',
        ]);
        NodeRole::query()->create(['node_id' => $node->id, 'role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);

        record_fixture($this->getJson('/api/v1/nodes')->assertOk(), 'nodes/node-list/default', ListNodesRequest::class, 'GET /api/v1/nodes');
        record_fixture($this->getJson("/api/v1/nodes/{$node->id}")->assertOk(), 'nodes/node-show/default', ShowNodeRequest::class, 'GET /api/v1/nodes/{node}');
    });

    it('records a created node', function (): void {
        $cluster = Cluster::query()->create(['name' => 'development']);
        $response = $this->postJson('/api/v1/nodes', [
            'name' => 'app-dev',
            'public_ssh_host' => '94.237.40.75',
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'tld' => 'app-dev.orbit',
            'roles' => ['app-dev'],
            'cluster_id' => $cluster->id,
            'wireguard_ip' => '10.44.0.3',
            'lan_ip' => '10.0.0.3',
            'host_key_fingerprint' => 'SHA256:4dxvKOYfyTcqJHYoxamTSu9bYYI5KE3xYWQPCAmeUTo',
        ])->assertCreated();

        record_fixture($response, 'nodes/node-add/created', AddNodeRequest::class, 'POST /api/v1/nodes');
    });

    it('records the node:add refusals the CLI must explain', function (): void {
        $tldRequired = $this->postJson('/api/v1/nodes', [
            'name' => 'app-dev',
            'public_ssh_host' => '94.237.40.75',
            'architecture' => 'x86_64',
            'roles' => ['app-dev'],
            'host_key_fingerprint' => 'SHA256:4dxvKOYfyTcqJHYoxamTSu9bYYI5KE3xYWQPCAmeUTo',
        ])->assertUnprocessable()->assertJsonPath('error.code', 'node.tld_required');
        record_fixture($tldRequired, 'nodes/node-add/tld-required', AddNodeRequest::class, 'POST /api/v1/nodes');

        $fingerprintRequired = $this->postJson('/api/v1/nodes', [
            'name' => 'app-dev',
            'public_ssh_host' => '94.237.40.75',
            'architecture' => 'x86_64',
            'roles' => ['app-dev'],
            'tld' => 'app-dev.orbit',
        ])->assertUnprocessable()->assertJsonPath('error.code', 'node.ssh_host_fingerprint_required');
        record_fixture($fingerprintRequired, 'nodes/node-add/fingerprint-required', AddNodeRequest::class, 'POST /api/v1/nodes');
    });
});
