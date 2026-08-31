<?php

declare(strict_types=1);

use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\NodeStateInspector;
use App\Domain\Nodes\NodeRoleDependencySet;
use App\Domain\Nodes\NodeRoleDependentCleaner;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolRemovalPlan;
use App\Domain\Tools\ToolStatus;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Activity;
use App\Models\App as OrbitApp;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Illuminate\Support\Str;
use Tests\Support\FakeToolManager;
use Tests\Support\FakeToolManagerMaterializer;

it('records exactly one bounded doctor activity without report findings or diagnostics', function (): void {
    $requestId = (string) Str::uuid();
    $caller = command_activity_doctor_node('doctor-caller');
    $selected = command_activity_doctor_node('doctor-selected');
    $caller->accessibleNodes()->attach($selected->id);
    app()->instance(NodeStateInspector::class, new class implements NodeStateInspector {
        public function inspect(Node $node): NodeInspectionData
        {
            return new NodeInspectionData(true, 'linux', 'x86_64', true);
        }
    });

    $this
        ->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->postJson('/api/v1/doctor', [
            'node_id' => $selected->id,
            'families' => ['node'],
        ])
        ->assertOk();

    $activity = Activity::query()->sole();

    expect($activity->request_id)
        ->toBe($requestId)
        ->and($activity->command)
        ->toBe('doctor:run')
        ->and($activity->status)
        ->toBe('succeeded')
        ->and($activity->error_code)
        ->toBeNull()
        ->and($activity->subject_type)
        ->toBeNull()
        ->and($activity->target_node_id)
        ->toBeNull()
        ->and($activity->properties?->toArray())
        ->toBe([
            'method' => 'POST',
            'path' => 'api/v1/doctor',
            'input' => [
                'node_id' => $selected->id,
                'families' => ['node'],
            ],
        ])
        ->and(json_encode($activity->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain('issues', 'findings', 'summary', 'stdout', 'stderr', 'diagnostics');
});

it('does not persist rejected doctor request data in activity', function (string $body): void {
    $sentinel = 'DOCTOR_ACTIVITY_SECRET_SENTINEL';
    $requestId = (string) Str::uuid();
    $caller = command_activity_doctor_node('doctor-rejected-caller');
    $selected = command_activity_doctor_node('doctor-rejected-selected');
    $caller->accessibleNodes()->attach($selected->id);

    $response = $this->call(
        'POST',
        '/api/v1/doctor',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_ORBIT_REQUEST_ID' => $requestId,
            'REMOTE_ADDR' => $caller->wireguard_ip,
        ],
        str_replace('__SENTINEL__', $sentinel, $body),
    );

    $response->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
    $activity = Activity::query()->sole();

    expect($activity->command)
        ->toBe('doctor:run')
        ->and($activity->status)
        ->toBe('failed')
        ->and($activity->error_code)
        ->toBe('validation.failed')
        ->and($activity->properties?->get('input'))
        ->toBe([])
        ->and($response->getContent())
        ->not->toContain($sentinel)->and(json_encode($activity->toArray(), JSON_THROW_ON_ERROR))
        ->not->toContain($sentinel);
})->with([
    'malformed JSON' => ['{"families":["__SENTINEL__"'],
    'unknown key' => ['{"unknown":"__SENTINEL__"}'],
    'duplicate key' => ['{"families":["node"],"families":["__SENTINEL__"]}'],
    'object family list' => ['{"families":{"chosen":"node"}}'],
    'numeric-keyed object family list' => ['{"families":{"0":"node"}}'],
]);

it('records one completed activity for each API command', function (): void {
    $requestId = (string) Str::uuid();

    $this
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->getJson('/api/v1/gateway/status')
        ->assertOk();

    $activity = Activity::query()->sole();

    expect($activity->request_id)
        ->toBe($requestId)
        ->and($activity->command)
        ->toBe('gateway:status')
        ->and($activity->status)
        ->toBe('succeeded')
        ->and($activity->caller_ip)
        ->toBe('127.0.0.1')
        ->and($activity->duration_ms)
        ->toBeGreaterThanOrEqual(0);
});

it('recursively redacts sensitive input and URL userinfo before persistence', function (): void {
    $requestId = (string) Str::uuid();
    $repositoryPassword = (string) Str::uuid();
    $nestedToken = (string) Str::uuid();
    $nestedPassword = (string) Str::uuid();
    $operator = Node::query()->create([
        'name' => 'operator',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $this->markAsGateway($operator);

    $this
        ->withServerVariables(['REMOTE_ADDR' => '10.44.0.2'])
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->postJson('/api/v1/apps', [
            'slug' => 'secret-app',
            'repository_url' => "https://alice:{$repositoryPassword}@example.com/acme/site.git",
            'defaults' => [
                'services' => [
                    ['name' => 'github', 'token' => $nestedToken],
                ],
                'database' => ['password' => $nestedPassword],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    $input = Activity::query()->where('request_id', $requestId)->sole()->properties?->get('input');

    expect($input)
        ->toBeArray()
        ->and($input['repository_url'] ?? null)
        ->toBe('https://[REDACTED]@example.com/acme/site.git')
        ->and($input['defaults']['services'][0]['token'] ?? null)
        ->toBe('[REDACTED]')
        ->and($input['defaults']['database']['password'] ?? null)
        ->toBe('[REDACTED]')
        ->and(json_encode($input))
        ->not->toContain($repositoryPassword, $nestedToken, $nestedPassword);
});

it('redacts secret repository query parameters before persistence and activity serialization', function (): void {
    $requestId = (string) Str::uuid();
    $repositoryUrl = 'https://example.test/repo.git?token=sentinel&branch=main';
    $redactedRepositoryUrl = 'https://example.test/repo.git?token=[REDACTED]&branch=main';
    $operator = Node::query()->create([
        'name' => 'operator',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $this->markAsGateway($operator);

    $failure = $this
        ->withServerVariables(['REMOTE_ADDR' => '10.44.0.2'])
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->postJson('/api/v1/apps', [
            'slug' => '../invalid',
            'repository_url' => $repositoryUrl,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');
    $activity = Activity::query()->where('request_id', $requestId)->sole();
    $properties = $activity->properties?->toArray() ?? [];
    $debugOutput = print_r($properties, return: true);

    expect($properties['input']['repository_url'] ?? null)
        ->toBe($redactedRepositoryUrl)
        ->and($failure->getContent())
        ->not->toContain('sentinel')->and($debugOutput)
        ->not->toContain('sentinel');

    $serialized = $this
        ->withServerVariables(['REMOTE_ADDR' => '10.44.0.2'])
        ->getJson("/api/v1/activities/{$activity->id}")
        ->assertOk()
        ->assertJsonPath('data.properties.input.repository_url', $redactedRepositoryUrl);

    expect($serialized->getContent())->not->toContain('sentinel');
});

it('records route model binding failures as http 404', function (): void {
    $requestId = (string) Str::uuid();
    $operator = Node::query()->create([
        'name' => 'operator',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $this->markAsGateway($operator);

    $this
        ->withServerVariables(['REMOTE_ADDR' => '10.44.0.2'])
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->getJson('/api/v1/apps/999999')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'http.404');

    expect(Activity::query()->where('request_id', $requestId)->sole()->error_code)->toBe('http.404');
});

it('correlates unhandled failures without exposing exception text', function (): void {
    $requestId = (string) Str::uuid();
    $secret = (string) Str::uuid();
    $operator = Node::query()->create([
        'name' => 'operator',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $this->markAsGateway($operator);
    OrbitApp::creating(static function () use ($secret): never {
        throw new RuntimeException("Unexpected APP_KEY={$secret}");
    });

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => '10.44.0.2'])
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->postJson('/api/v1/apps', [
            'slug' => 'acme',
            'repository_url' => 'https://github.com/acme/site.git',
        ]);

    $response
        ->assertInternalServerError()
        ->assertHeader('X-Orbit-Request-Id', $requestId)
        ->assertJsonPath('error.code', 'gateway.unhandled')
        ->assertJsonPath('error.message', 'The gateway could not complete the request.');

    expect($response->getContent())
        ->not
        ->toContain($secret)
        ->and(Activity::query()->where('request_id', $requestId)->sole()->error_code)
        ->toBe('gateway.unhandled');
});

it('records node access add and remove commands against the serving node and preserves access failures', function (): void {
    $gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]));
    $serving = Node::query()->create([
        'name' => 'serving',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.3',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $consumer = Node::query()->create([
        'name' => 'consumer',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.4',
        'wireguard_ip' => '10.44.0.4',
    ]);
    $directOnly = Node::query()->create([
        'name' => 'direct-only',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.5',
        'wireguard_ip' => '10.44.0.5',
    ]);
    $directOnly->accessibleNodes()->attach($serving);
    $addRequestId = (string) Str::uuid();
    $removeRequestId = (string) Str::uuid();
    $forbiddenRequestId = (string) Str::uuid();

    $this
        ->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->withHeader('X-Orbit-Request-Id', $addRequestId)
        ->putJson("/api/v1/nodes/{$serving->id}/access/{$consumer->id}")
        ->assertOk();

    $this
        ->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->withHeader('X-Orbit-Request-Id', $removeRequestId)
        ->deleteJson("/api/v1/nodes/{$serving->id}/access/{$consumer->id}")
        ->assertOk();

    $this
        ->withServerVariables(['REMOTE_ADDR' => $directOnly->wireguard_ip])
        ->withHeader('X-Orbit-Request-Id', $forbiddenRequestId)
        ->putJson("/api/v1/nodes/{$serving->id}/access/{$consumer->id}")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'node_access.required');

    expect(Activity::query()->where('request_id', $addRequestId)->sole())
        ->command->toBe('node:access:add')
        ->subject_type->toBe(Node::class)
        ->subject_id->toBe($serving->id)
        ->target_node_id->toBe($serving->id);

    expect(Activity::query()->where('request_id', $removeRequestId)->sole())
        ->command->toBe('node:access:remove')
        ->subject_type->toBe(Node::class)
        ->subject_id->toBe($serving->id)
        ->target_node_id->toBe($serving->id);

    expect(Activity::query()->where('request_id', $forbiddenRequestId)->sole())
        ->command->toBe('node:access:add')
        ->status->toBe('failed')
        ->error_code->toBe('node_access.required')
        ->subject_type->toBe(Node::class)
        ->subject_id->toBe($serving->id)
        ->target_node_id->toBe($serving->id);
});

it('records node role commands against the node with bounded inputs and stable failures', function (): void {
    app()->instance(ToolManagerMaterializer::class, new FakeToolManagerMaterializer);
    $gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'role-activity-gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.20',
        'wireguard_ip' => '10.44.0.20',
    ]));
    $node = Node::query()->create([
        'name' => 'role-activity-target',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.21',
        'wireguard_ip' => '10.44.0.21',
    ]);
    $lifecycle = new CommandActivityNodeRoleLifecycleFake;
    app()->instance(RoleBaselineConverger::class, $lifecycle);
    app()->instance(NodeRoleDependentCleaner::class, $lifecycle);
    $listRequestId = (string) Str::uuid();
    $addRequestId = (string) Str::uuid();
    $removeRequestId = (string) Str::uuid();

    $this
        ->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->withHeader('X-Orbit-Request-Id', $listRequestId)
        ->getJson("/api/v1/nodes/{$node->id}/roles")
        ->assertOk();
    $this
        ->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->withHeader('X-Orbit-Request-Id', $addRequestId)
        ->postJson("/api/v1/nodes/{$node->id}/roles", [
            'role' => 'app-dev',
            'converge_existing' => false,
        ])
        ->assertCreated();
    $this
        ->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->withHeader('X-Orbit-Request-Id', $removeRequestId)
        ->deleteJson("/api/v1/nodes/{$node->id}/roles/app-dev", [
            'force' => false,
            'purge_data' => false,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed');

    $activities = Activity::query()
        ->whereIn('request_id', [$listRequestId, $addRequestId, $removeRequestId])
        ->get()
        ->keyBy('request_id');

    expect($activities[$listRequestId])
        ->command->toBe('node:role:list')
        ->subject_type->toBe(Node::class)
        ->subject_id->toBe($node->id)
        ->target_node_id->toBe($node->id)
        ->status->toBe('succeeded')->and($activities[$addRequestId])
        ->command->toBe('node:role:add')
        ->subject_type->toBe(Node::class)
        ->subject_id->toBe($node->id)
        ->target_node_id->toBe($node->id)
        ->status->toBe('succeeded')->and($activities[$addRequestId]->properties?->get('input'))->toBe([
            'role' => 'app-dev',
            'converge_existing' => false,
        ])->and($activities[$removeRequestId])
        ->command->toBe('node:role:remove')
        ->subject_type->toBe(Node::class)
        ->subject_id->toBe($node->id)
        ->target_node_id->toBe($node->id)
        ->status->toBe('failed')
        ->error_code->toBe('validation.failed')->and($activities[$removeRequestId]->properties?->get('input'))->toBe([
            'force' => false,
            'purge_data' => false,
            'role' => 'app-dev',
        ]);

    foreach ($activities as $activity) {
        expect($activity->properties?->toArray())->not->toHaveKeys(['stdout', 'stderr']);
    }
});

/** @mago-expect lint:file-name Test-local fake isolates node role activity from remote effects. */
final class CommandActivityNodeRoleLifecycleFake implements RoleBaselineConverger, NodeRoleDependentCleaner
{
    public function converge(Node $node, NodeRole $assignment): void {}

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}

    public function removeUnreachable(Node $node, NodeRole $assignment): void {}

    public function clean(NodeRoleDependencySet $dependencies): void {}
}

it('records tool manager and tool lists with the node target and no tool subject', function (): void {
    $node = Node::query()->create([
        'name' => 'tool-list-node',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.30',
        'wireguard_ip' => '10.44.0.30',
    ]);
    $this->markAsGateway($node);
    foreach ([
        ['tool:manager:list', '/api/v1/tool-managers?node_id='.$node->id],
        ['tool:list', '/api/v1/tools?node_id='.$node->id],
    ] as [$command, $url]) {
        $requestId = (string) Str::uuid();
        $this
            ->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
            ->withHeader('X-Orbit-Request-Id', $requestId)
            ->getJson($url);
        $activity = Activity::query()->where('request_id', $requestId)->sole();
        expect($activity->command)
            ->toBe($command)
            ->and($activity->target_node_id)
            ->toBe($node->id)
            ->and($activity->subject_type)
            ->toBeNull();
    }
});

it('records tool show with the tool subject and node target', function (): void {
    $node = Node::query()->create([
        'name' => 'tool-node',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.31',
        'wireguard_ip' => '10.44.0.31',
    ]);
    $this->markAsGateway($node);
    $manager = ToolManagerRecord::query()->create([
        'node_id' => $node->id,
        'name' => ToolManagerName::Apt,
        'status' => LifecycleStatus::Active,
    ]);
    $tool = Tool::query()->create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'jq',
        'status' => ToolStatus::Installed,
    ]);
    $requestId = (string) Str::uuid();

    $this
        ->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->getJson("/api/v1/tools/{$tool->id}");

    $activity = Activity::query()->where('request_id', $requestId)->sole();

    expect($activity->command)
        ->toBe('tool:show')
        ->and($activity->target_node_id)
        ->toBe($node->id)
        ->and($activity->subject_type)
        ->toBe(Tool::class)
        ->and($activity->subject_id)
        ->toBe($tool->id);
});

it('records successful install with the created tool and an exact safe projection', function (): void {
    $node = Node::query()->create([
        'name' => 'tool-install-node',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.33',
        'wireguard_ip' => '10.44.0.33',
    ]);
    $this->markAsGateway($node);
    $manager = ToolManagerRecord::query()->create([
        'node_id' => $node->id,
        'name' => ToolManagerName::Apt,
        'status' => LifecycleStatus::Active,
    ]);
    $fake = new Tests\Support\FakeToolManager;
    $fake->installedVersions = [null, '1.2.3'];
    $fake->candidateVersions = ['1.2.3'];
    app()->instance(ToolManagerRegistry::class, new ToolManagerRegistry([$fake]));
    $requestId = (string) Str::uuid();
    $this
        ->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->postJson('/api/v1/tools', [
            'node_id' => $node->id,
            'manager' => 'apt',
            'package' => 'jq',
            'version_constraint' => '^1.0',
        ])
        ->assertSuccessful();
    $activity = Activity::query()->where('request_id', $requestId)->sole();
    $tool = Tool::query()->where('node_id', $node->id)->sole();
    expect($activity->subject_type)
        ->toBe(Tool::class)
        ->and($activity->subject_id)
        ->toBe($tool->id)
        ->and($activity->target_node_id)
        ->toBe($node->id)
        ->and($activity->properties?->get('tool'))
        ->toBe([
            'node_id' => $node->id,
            'manager' => 'apt',
            'package' => 'jq',
            'operation' => 'install',
            'outcome' => 'applied',
            'version_constraint' => '^1.0',
        ])
        ->and($activity->properties?->get('input'))
        ->toBe([]);
});

it('records pre-row tool failures safely without substituting the node subject', function (): void {
    $node = Node::query()->create([
        'name' => 'tool-failure-node',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.34',
        'wireguard_ip' => '10.44.0.34',
    ]);
    $this->markAsGateway($node);
    app()->instance(ToolManagerRegistry::class, new ToolManagerRegistry([new Tests\Support\FakeToolManager]));
    $requestId = (string) Str::uuid();
    $this
        ->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->postJson('/api/v1/tools', ['node_id' => $node->id, 'manager' => 'unsupported', 'package' => 'jq'])
        ->assertStatus(422);
    $activity = Activity::query()->where('request_id', $requestId)->sole();
    expect($activity->status)
        ->toBe('failed')
        ->and($activity->error_code)
        ->toBe('tool.manager_unsupported')
        ->and($activity->subject_type)
        ->toBeNull()
        ->and($activity->subject_id)
        ->toBeNull()
        ->and($activity->target_node_id)
        ->toBe($node->id)
        ->and($activity->properties?->get('tool'))
        ->toBe([
            'node_id' => $node->id,
            'manager' => 'unsupported',
            'package' => 'jq',
            'operation' => 'install',
            'outcome' => 'manager_failed',
            'version_constraint' => null,
            'error_code' => 'tool.manager_unsupported',
        ]);
});

it('records retained failed tools as subjects with safe outcomes', function (): void {
    $node = Node::query()->create([
        'name' => 'tool-retained-node',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.35',
        'wireguard_ip' => '10.44.0.35',
    ]);
    $this->markAsGateway($node);
    ToolManagerRecord::query()->create([
        'node_id' => $node->id,
        'name' => ToolManagerName::Apt,
        'status' => LifecycleStatus::Active,
    ]);
    $otherNode = Node::query()->create([
        'name' => 'other-tool-retained-node',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.45',
        'wireguard_ip' => '10.44.0.45',
    ]);
    $otherManager = ToolManagerRecord::query()->create([
        'node_id' => $otherNode->id,
        'name' => ToolManagerName::Apt,
        'status' => LifecycleStatus::Active,
    ]);
    $otherTool = Tool::query()->create([
        'node_id' => $otherNode->id,
        'tool_manager_id' => $otherManager->id,
        'package' => 'jq',
        'status' => ToolStatus::Installed,
    ]);
    $fake = new Tests\Support\FakeToolManager;
    $fake->failures['install'] = [new ToolManagerException('install', 'RAW_EXCEPTION_SENTINEL')];
    app()->instance(ToolManagerRegistry::class, new ToolManagerRegistry([$fake]));
    $requestId = (string) Str::uuid();
    $this
        ->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->postJson('/api/v1/tools', ['node_id' => $node->id, 'manager' => 'apt', 'package' => 'jq'])
        ->assertStatus(502);
    $activity = Activity::query()->where('request_id', $requestId)->sole();
    $tool = Tool::query()->where('node_id', $node->id)->sole();
    expect($tool->status)
        ->toBe(ToolStatus::Failed)
        ->and($activity->subject_type)
        ->toBe(Tool::class)
        ->and($activity->subject_id)
        ->toBe($tool->id)
        ->not->toBe($otherTool->id)->and($activity->target_node_id)->toBe($node->id)->and($activity->properties?->get(
            'tool',
        ))->toBe([
            'node_id' => $node->id,
            'manager' => 'apt',
            'package' => 'jq',
            'operation' => 'install',
            'outcome' => 'manager_failed',
            'version_constraint' => null,
            'error_code' => 'tool.install_failed',
        ])->and(json_encode($activity->toArray()))
        ->not->toContain('RAW_EXCEPTION_SENTINEL');
});

it('does not persist command result data from manager failures', function (): void {
    $node = Node::query()->create([
        'name' => 'tool-redaction-node',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.36',
        'wireguard_ip' => '10.44.0.36',
    ]);
    $this->markAsGateway($node);
    ToolManagerRecord::query()->create([
        'node_id' => $node->id,
        'name' => ToolManagerName::Apt,
        'status' => LifecycleStatus::Active,
    ]);
    $fake = new Tests\Support\FakeToolManager;
    $fake->failures['install'] = [new ToolManagerException(
        'install',
        'EXCEPTION_SENTINEL',
        new CommandResult(7, 'STDOUT_SENTINEL', 'STDERR_SENTINEL', 1, true),
    )];
    app()->instance(ToolManagerRegistry::class, new ToolManagerRegistry([$fake]));
    $requestId = (string) Str::uuid();
    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->postJson('/api/v1/tools', ['node_id' => $node->id, 'manager' => 'apt', 'package' => 'jq'])
        ->assertStatus(502);
    $activity = Activity::query()->where('request_id', $requestId)->sole();
    $json = json_encode($activity->toArray()).$response->getContent();
    expect($json)
        ->not->toContain(
            'STDOUT_SENTINEL',
            'STDERR_SENTINEL',
            'EXCEPTION_SENTINEL',
        )->and($activity->exit_code)->toBeNull()->and($activity->properties?->toArray())
        ->not->toHaveKeys(['stdout', 'stderr', 'argv', 'path', 'url', 'command_result', 'output_truncated']);
});

it('records a successful remove against the deleted tool snapshot', function (): void {
    $node = Node::query()->create([
        'name' => 'tool-remove-node',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.37',
        'wireguard_ip' => '10.44.0.37',
    ]);
    $this->markAsGateway($node);
    $manager = ToolManagerRecord::query()->create([
        'node_id' => $node->id,
        'name' => ToolManagerName::Apt,
        'status' => LifecycleStatus::Active,
    ]);
    $tool = Tool::query()->create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'jq',
        'status' => ToolStatus::Installed,
    ]);
    $originalId = $tool->id;
    $fake = new FakeToolManager;
    $fake->removalPlan = new ToolRemovalPlan(['jq']);
    $fake->installedVersions = ['1.0.0', null];
    app()->instance(ToolManagerRegistry::class, new ToolManagerRegistry([$fake]));
    $requestId = (string) Str::uuid();

    $this
        ->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->deleteJson("/api/v1/tools/{$originalId}")
        ->assertOk();

    $activity = Activity::query()->where('request_id', $requestId)->sole();
    expect(Tool::query()->find($originalId))
        ->toBeNull()
        ->and($activity->command)
        ->toBe('tool:remove')
        ->and($activity->subject_type)
        ->toBe(Tool::class)
        ->and($activity->subject_id)
        ->toBe($originalId)
        ->and($activity->target_node_id)
        ->toBe($node->id)
        ->and($activity->properties?->get('tool'))
        ->toBe([
            'node_id' => $node->id,
            'manager' => 'apt',
            'package' => 'jq',
            'operation' => 'remove',
            'outcome' => 'applied',
            'version_constraint' => null,
        ])
        ->and($activity->properties?->get('tool'))
        ->not->toHaveKey('error_code');
});

it('redacts unsupported values from invalid tool request activity', function (): void {
    $node = Node::query()->create([
        'name' => 'tool-validation-node',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.38',
        'wireguard_ip' => '10.44.0.38',
    ]);
    $this->markAsGateway($node);
    $requestId = (string) Str::uuid();
    $sentinel = 'RAW_TOOL_VALIDATION_SENTINEL';

    $response = $this
        ->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
        ->call(
            'POST',
            '/api/v1/tools',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_ORBIT_REQUEST_ID' => $requestId,
            ],
            json_encode([
                'node_id' => $node->id,
                'manager' => 'apt',
                'package' => 'jq',
                'unsupported' => $sentinel,
            ]),
        );
    $response->assertUnprocessable();
    $activity = Activity::query()->where('request_id', $requestId)->sole();
    expect($response->status())
        ->toBe(422)
        ->and($activity->target_node_id)
        ->toBe($node->id)
        ->and($activity->subject_type)
        ->toBeNull()
        ->and($activity->properties?->get('input'))
        ->toBe([])
        ->and($activity->exit_code)
        ->toBeNull()
        ->and($activity->properties?->toArray())
        ->not->toHaveKeys(['stdout', 'stderr', 'output_truncated'])->and($response->getContent())
        ->not->toContain($sentinel)->and(json_encode($activity->toArray()))
        ->not->toContain($sentinel);
});

it('records tool update outcomes with an exact safe projection', function (
    string $outcome,
    string $candidateVersion,
    ?string $installedVersion,
): void {
    $node = Node::query()->create([
        'name' => 'tool-update-node-'.$outcome,
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.39',
        'wireguard_ip' => '10.44.0.39',
    ]);
    $this->markAsGateway($node);
    $manager = ToolManagerRecord::query()->create([
        'node_id' => $node->id,
        'name' => ToolManagerName::Apt,
        'status' => LifecycleStatus::Active,
    ]);
    $tool = Tool::query()->create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'jq',
        'version_constraint' => '^1.0',
        'status' => ToolStatus::Installed,
        'installed_version' => '1.0.0',
    ]);
    $fake = new FakeToolManager;
    $fake->installedVersions = $installedVersion === null
        ? ['1.0.0']
        : ['1.0.0', $installedVersion];
    $fake->candidateVersions = [$candidateVersion];
    app()->instance(ToolManagerRegistry::class, new ToolManagerRegistry([$fake]));
    $requestId = (string) Str::uuid();

    $this
        ->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->postJson("/api/v1/tools/{$tool->id}/update")
        ->assertOk()
        ->assertJsonPath('data.outcome', $outcome);

    $activity = Activity::query()->where('request_id', $requestId)->sole();

    expect($activity->subject_type)
        ->toBe(Tool::class)
        ->and($activity->subject_id)
        ->toBe($tool->id)
        ->and($activity->target_node_id)
        ->toBe($node->id)
        ->and($activity->properties?->get('input'))
        ->toBe([])
        ->and($activity->properties?->get('tool'))
        ->toBe([
            'node_id' => $node->id,
            'manager' => 'apt',
            'package' => 'jq',
            'operation' => 'update',
            'outcome' => $outcome,
            'version_constraint' => '^1.0',
        ]);
})->with([
    'applied' => ['applied', '1.1.0', '1.1.0'],
    'unchanged' => ['unchanged', '1.0.0', '1.0.0'],
    'blocked by constraint' => ['blocked_by_constraint', '2.0.0', null],
]);

it('keeps failed update tools retained and redacted', function (): void {
    $node = Node::query()->create([
        'name' => 'tool-update-failure-node',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.40',
        'wireguard_ip' => '10.44.0.40',
    ]);
    $this->markAsGateway($node);
    $manager = ToolManagerRecord::query()->create([
        'node_id' => $node->id,
        'name' => ToolManagerName::Apt,
        'status' => LifecycleStatus::Active,
    ]);
    $tool = Tool::query()->create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'jq',
        'status' => ToolStatus::Installed,
        'installed_version' => '1.0.0',
    ]);
    $fake = new FakeToolManager;
    $fake->installedVersions = ['1.0.0'];
    $fake->failures['update'] = [new ToolManagerException('update', 'UPDATE_EXCEPTION_SENTINEL')];
    app()->instance(ToolManagerRegistry::class, new ToolManagerRegistry([$fake]));
    $requestId = (string) Str::uuid();

    $this
        ->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->postJson("/api/v1/tools/{$tool->id}/update")
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'tool.update_failed');

    $activity = Activity::query()->where('request_id', $requestId)->sole();

    expect($activity->status)
        ->toBe('failed')
        ->and($activity->error_code)
        ->toBe('tool.update_failed')
        ->and($activity->subject_id)
        ->toBe($tool->id)
        ->and($activity->target_node_id)
        ->toBe($node->id)
        ->and($activity->properties?->get('tool'))
        ->toBe([
            'node_id' => $node->id,
            'manager' => 'apt',
            'package' => 'jq',
            'operation' => 'update',
            'outcome' => 'manager_failed',
            'version_constraint' => null,
            'error_code' => 'tool.update_failed',
        ])
        ->and($activity->properties?->toArray())
        ->not->toHaveKeys(['stdout', 'stderr', 'argv', 'path', 'url'])->and(json_encode($activity->toArray()))
        ->not->toContain('UPDATE_EXCEPTION_SENTINEL');
});

it('keeps failed remove tools retained and redacted', function (): void {
    $node = Node::query()->create([
        'name' => 'tool-remove-failure-node',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.41',
        'wireguard_ip' => '10.44.0.41',
    ]);
    $this->markAsGateway($node);
    $manager = ToolManagerRecord::query()->create([
        'node_id' => $node->id,
        'name' => ToolManagerName::Apt,
        'status' => LifecycleStatus::Active,
    ]);
    $tool = Tool::query()->create([
        'node_id' => $node->id,
        'tool_manager_id' => $manager->id,
        'package' => 'jq',
        'status' => ToolStatus::Installed,
        'installed_version' => '1.0.0',
    ]);
    $fake = new FakeToolManager;
    $fake->installedVersions = ['1.0.0'];
    $fake->removalPlan = new ToolRemovalPlan(['jq']);
    $fake->failures['remove'] = [new ToolManagerException('remove', 'REMOVE_EXCEPTION_SENTINEL')];
    app()->instance(ToolManagerRegistry::class, new ToolManagerRegistry([$fake]));
    $requestId = (string) Str::uuid();

    $this
        ->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
        ->withHeader('X-Orbit-Request-Id', $requestId)
        ->deleteJson("/api/v1/tools/{$tool->id}")
        ->assertStatus(502)
        ->assertJsonPath('error.code', 'tool.remove_failed');

    $activity = Activity::query()->where('request_id', $requestId)->sole();

    $this->assertModelExists($tool);
    expect($activity->status)
        ->toBe('failed')
        ->and($activity->error_code)
        ->toBe('tool.remove_failed')
        ->and($activity->subject_type)
        ->toBe(Tool::class)
        ->and($activity->subject_id)
        ->toBe($tool->id)
        ->and($activity->target_node_id)
        ->toBe($node->id)
        ->and($activity->properties?->get('tool'))
        ->toBe([
            'node_id' => $node->id,
            'manager' => 'apt',
            'package' => 'jq',
            'operation' => 'remove',
            'outcome' => 'manager_failed',
            'version_constraint' => null,
            'error_code' => 'tool.remove_failed',
        ])
        ->and(json_encode($activity->toArray()))
        ->not->toContain('REMOVE_EXCEPTION_SENTINEL');
});

function command_activity_doctor_node(string $name): Node
{
    static $number = 210;
    $number++;

    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'architecture' => 'amd64',
        'public_ssh_host' => "192.0.2.{$number}",
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => "10.44.0.{$number}",
    ]);
}
