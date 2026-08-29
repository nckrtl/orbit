<?php

declare(strict_types=1);

use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\NodeStateInspector;
use App\Domain\Shared\LifecycleStatus;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireNodeAccess;
use App\Models\Node;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

describe('Doctor API', function (): void {
    it('returns all families for accessible nodes in the standard correlated envelope', function (): void {
        $requestId = '9ddf48b5-a819-4a66-92bf-a529725649d7';
        $caller = doctor_api_node('family-message-caller');
        $selected = doctor_api_node('selected');
        $caller->accessibleNodes()->attach($selected->id);
        doctor_api_node('inaccessible');
        bind_doctor_api_inspector(new NodeInspectionData(true, 'linux', 'x86_64', true));

        $response = doctor_api_raw($this, $caller, '{}', $requestId)->assertOk();
        $payload = $response->json();

        $response->assertHeader('X-Orbit-Request-Id', $requestId);
        expect(array_keys($payload))
            ->toBe(['data', 'meta'])
            ->and(array_keys($payload['data']))
            ->toBe(['healthy', 'nodes', 'summary'])
            ->and($payload['meta'])
            ->toBe(['request_id' => $requestId])
            ->and(array_column($payload['data']['nodes'], 'node_id'))
            ->toBe([$selected->id])
            ->and(array_column($payload['data']['nodes'][0]['families'], 'family'))
            ->toBe(['node', 'role', 'app', 'instance', 'workspace', 'tool', 'process', 'firewall'])
            ->and($payload['data']['summary'])
            ->toBe([
                'nodes' => 1,
                'families' => 8,
                'checks' => 1,
                'drift' => 0,
                'unverifiable' => 0,
            ]);
    });

    it('filters by node and keeps requested families in canonical order', function (): void {
        $caller = doctor_api_node('caller');
        $selected = doctor_api_node('selected');
        $other = doctor_api_node('other');
        $caller->accessibleNodes()->attach([$selected->id, $other->id]);
        bind_doctor_api_inspector(new NodeInspectionData(true, 'linux', 'x86_64', true));

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
            ->postJson('/api/v1/doctor', [
                'node_id' => $selected->id,
                'families' => ['firewall', 'role', 'node'],
            ])
            ->assertOk();

        expect($response->json('data.nodes'))
            ->toHaveCount(1)
            ->and($response->json('data.nodes.0.node_id'))
            ->toBe($selected->id)
            ->and(array_column($response->json('data.nodes.0.families'), 'family'))
            ->toBe(['node', 'role', 'firewall']);
    });

    it('returns 422 for an invalid family filter', function (mixed $families): void {
        $caller = doctor_api_node('caller');
        $selected = doctor_api_node('selected');
        $caller->accessibleNodes()->attach($selected->id);

        $this
            ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
            ->postJson('/api/v1/doctor', ['families' => $families])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');
    })->with([
        'empty array' => [[]],
        'duplicate family' => [['node', 'node']],
        'unknown family' => [['unknown']],
        'non-array family list' => ['node'],
        'non-string family' => [[1]],
    ]);

    it('exposes the family validation message', function (): void {
        $caller = doctor_api_node('caller');
        $selected = doctor_api_node('family-message-selected');
        $caller->accessibleNodes()->attach($selected->id);

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
            ->postJson('/api/v1/doctor', ['families' => ['unknown']])
            ->assertUnprocessable();

        expect($response->json('error.details')['families.0'][0] ?? null)
            ->toBe('The selected families.0 is invalid.');
    });

    it('rejects object-shaped family lists', function (string $families): void {
        $caller = doctor_api_node('strict-message-caller');
        $selected = doctor_api_node('selected');
        $caller->accessibleNodes()->attach($selected->id);

        doctor_api_raw($this, $caller, '{"families":'.$families.'}')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');
    })->with([
        'named key' => ['{"chosen":"node"}'],
        'numeric key' => ['{"0":"node"}'],
    ]);

    it('returns 422 for a strict JSON body violation without reflecting rejected data', function (
        string $body,
    ): void {
        $sentinel = 'DOCTOR_REJECTED_SENTINEL';
        $caller = doctor_api_node('node-message-caller');
        $selected = doctor_api_node('selected');
        $caller->accessibleNodes()->attach($selected->id);

        $response = doctor_api_raw($this, $caller, str_replace('__SENTINEL__', $sentinel, $body))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');

        expect($response->getContent())->not->toContain($sentinel);
    })->with([
        'malformed JSON' => ['{"families":['],
        'scalar JSON' => ['"node"'],
        'unknown key' => ['{"unknown":"__SENTINEL__"}'],
        'duplicate key' => ['{"families":["node"],"families":["role"]}'],
        'escaped duplicate key' => ['{"families":["node"],"familie\\u0073":["role"]}'],
    ]);

    it('exposes the strict JSON body validation message', function (): void {
        $caller = doctor_api_node('caller');
        $selected = doctor_api_node('strict-message-selected');
        $caller->accessibleNodes()->attach($selected->id);

        doctor_api_raw($this, $caller, '{"families":[not-json]')
            ->assertUnprocessable()
            ->assertJsonPath('error.details.body.0', 'The request body must be a valid JSON object.');
    });

    it('returns 422 for an invalid node ID', function (mixed $nodeId): void {
        $caller = doctor_api_node('caller');
        $selected = doctor_api_node('selected');
        $caller->accessibleNodes()->attach($selected->id);

        $this
            ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
            ->postJson('/api/v1/doctor', ['node_id' => $nodeId, 'families' => ['node']])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');
    })->with([
        'numeric string' => ['1'],
        'zero' => [0],
        'negative integer' => [-1],
        'null' => [null],
    ]);

    it('exposes the node ID validation message', function (): void {
        $caller = doctor_api_node('caller');
        $selected = doctor_api_node('node-message-selected');
        $caller->accessibleNodes()->attach($selected->id);

        $this
            ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
            ->postJson('/api/v1/doctor', ['node_id' => '1', 'families' => ['node']])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.node_id.0', 'The node_id field must be an integer.');
    });

    it('returns 404 for a valid positive missing node ID', function (): void {
        $caller = doctor_api_node('caller');
        $allowed = doctor_api_node('allowed');
        $caller->accessibleNodes()->attach($allowed->id);
        $inspector = bind_doctor_api_inspector(new NodeInspectionData(true, 'linux', 'x86_64', true));

        $this
            ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
            ->postJson('/api/v1/doctor', ['node_id' => 999_999, 'families' => ['node']])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'http.404');

        expect($inspector->nodeIds)->toBe([]);
    });

    it('returns 403 for an inaccessible filtered node before inspection', function (): void {
        $caller = doctor_api_node('caller');
        $allowed = doctor_api_node('allowed');
        $inaccessible = doctor_api_node('inaccessible');
        $caller->accessibleNodes()->attach($allowed->id);
        $inspector = bind_doctor_api_inspector(new NodeInspectionData(true, 'linux', 'x86_64', true));

        $this
            ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
            ->postJson('/api/v1/doctor', ['node_id' => $inaccessible->id, 'families' => ['node']])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'node_access.required')
            ->assertJsonPath('error.details.serving_node.id', $inaccessible->id);

        expect($inspector->nodeIds)->toBe([]);
    });

    it('returns 403 when the caller has no node access', function (): void {
        $caller = doctor_api_node('caller');

        $this
            ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
            ->postJson('/api/v1/doctor', ['families' => ['node']])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'node_access.required');
    });

    it('returns 403 for an unknown or inactive WireGuard peer', function (?Node $caller): void {
        $remoteAddress = $caller?->wireguard_address ?? '10.44.0.254';

        $this
            ->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
            ->postJson('/api/v1/doctor', ['families' => ['node']])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'peer.identity_unknown');
    })->with([
        'unknown peer' => [null],
        'inactive peer' => [fn (): Node => doctor_api_node('inactive', LifecycleStatus::Failed)],
    ]);

    it('returns completed healthy, drift, and unverifiable reports with HTTP 200', function (
        NodeInspectionData $inspection,
        bool $healthy,
        string $status,
    ): void {
        $caller = doctor_api_node('caller');
        $selected = doctor_api_node('selected');
        $caller->accessibleNodes()->attach($selected->id);
        bind_doctor_api_inspector($inspection);

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_address])
            ->postJson('/api/v1/doctor', ['node_id' => $selected->id, 'families' => ['node']])
            ->assertOk();

        expect($response->json('data.healthy'))
            ->toBe($healthy)
            ->and($response->json('data.nodes.0.families.0.status'))
            ->toBe($status);
    })->with([
        'healthy' => [new NodeInspectionData(true, 'linux', 'x86_64', true), true, 'healthy'],
        'drift' => [new NodeInspectionData(true, 'darwin', 'aarch64', false), false, 'drift'],
        'unverifiable' => [new NodeInspectionData(false, null, null, null), false, 'unverifiable'],
    ]);

    it('registers the exact protected collection route contract', function (): void {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(static fn (IlluminateRoute $route): bool => $route->getName() === 'doctor:run');

        expect($route)
            ->not
            ->toBeNull()
            ->and($route?->methods()[0])
            ->toBe('POST')
            ->and($route?->uri())
            ->toBe('api/v1/doctor')
            ->and($route?->gatherMiddleware())
            ->toContain(RequireActiveWireGuardPeer::class, RequireNodeAccess::class);

        $method = new ReflectionMethod($route->getControllerClass(), $route->getActionMethod());
        $attributes = $method->getAttributes(RequiresNodeAccess::class);

        expect($attributes)
            ->toHaveCount(1)
            ->and($attributes[0]->newInstance()->servingNode)
            ->toBe(ServingNode::Collection);
    });
});

function doctor_api_node(
    string $name,
    LifecycleStatus $status = LifecycleStatus::Active,
): Node {
    static $number = 100;
    $number++;

    return Node::query()->create([
        'name' => $name,
        'status' => $status,
        'platform' => 'linux',
        'architecture' => 'amd64',
        'public_ssh_host' => "192.0.2.{$number}",
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_address' => "10.44.0.{$number}",
    ]);
}

function bind_doctor_api_inspector(NodeInspectionData $inspection): DoctorApiNodeStateInspector
{
    $inspector = new DoctorApiNodeStateInspector($inspection);
    app()->instance(NodeStateInspector::class, $inspector);

    return $inspector;
}

function doctor_api_raw(
    TestCase $test,
    Node $caller,
    string $body,
    ?string $requestId = null,
): TestResponse {
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'REMOTE_ADDR' => $caller->wireguard_address,
    ];

    if ($requestId !== null) {
        $server['HTTP_X_ORBIT_REQUEST_ID'] = $requestId;
    }

    return $test->call('POST', '/api/v1/doctor', [], [], [], $server, $body);
}

final class DoctorApiNodeStateInspector implements NodeStateInspector
{
    /** @var list<int> */
    public array $nodeIds = [];

    public function __construct(
        private readonly NodeInspectionData $inspection,
    ) {}

    public function inspect(Node $node): NodeInspectionData
    {
        $this->nodeIds[] = $node->id;

        return $this->inspection;
    }
}
