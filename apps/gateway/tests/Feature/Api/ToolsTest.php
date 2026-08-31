<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolRemovalPlan;
use App\Domain\Tools\ToolStatus;
use App\Models\Node;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeToolManager;

beforeEach(function (): void {
    $this->gateway = tools_api_node('tools-gateway', '10.44.0.2');
    $this->markAsGateway($this->gateway);
    $this->node = tools_api_node('tools-node', '10.44.0.3');
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->toolManager = new FakeToolManager(ToolManagerName::Apt);
    app()->instance(ToolManagerRegistry::class, new ToolManagerRegistry([$this->toolManager]));
    $this->managerRecord = $this->node
        ->toolManagers()
        ->create([
            'name' => ToolManagerName::Apt,
            'status' => LifecycleStatus::Active,
        ]);
});

describe('tool reads', function (): void {
    it('rejects each strict JSON field type without leaking sentinels', function (string $json): void {
        $response = $this->call('POST', '/api/v1/tools', [], [], [], ['CONTENT_TYPE' => 'application/json'], $json);
        $response->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        expect($response->getContent())->not->toContain('sentinel');
    })->with([
        'node string' => ['{"node_id":"1","manager":"apt","package":"jq","version_constraint":null}'],
        'node float' => ['{"node_id":1.5,"manager":"apt","package":"jq","version_constraint":null}'],
        'node bool' => ['{"node_id":true,"manager":"apt","package":"jq","version_constraint":null}'],
        'manager null' => ['{"node_id":1,"manager":null,"package":"jq","version_constraint":null}'],
        'manager array' => ['{"node_id":1,"manager":[],"package":"jq","version_constraint":null}'],
        'manager object' => ['{"node_id":1,"manager":{},"package":"jq","version_constraint":null}'],
        'package null' => ['{"node_id":1,"manager":"apt","package":null,"version_constraint":null}'],
        'package integer' => ['{"node_id":1,"manager":"apt","package":42,"version_constraint":null}'],
        'package object' => ['{"node_id":1,"manager":"apt","package":{},"version_constraint":null}'],
        'constraint array' => ['{"node_id":1,"manager":"apt","package":"jq","version_constraint":[]}'],
        'constraint bool' => ['{"node_id":1,"manager":"apt","package":"jq","version_constraint":true}'],
    ]);

    it('lists managers for the requested node', function (): void {
        $this->node->toolManagers()->create(['name' => ToolManagerName::Vp, 'status' => LifecycleStatus::Active]);

        $response = $this
            ->getJson('/api/v1/tool-managers?node_id='.$this->node->id)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'apt')
            ->assertJsonPath('data.1.name', 'vp')
            ->assertJsonStructure(['data', 'meta' => ['request_id']]);

        expect(array_keys($response->json('data.0')))->toBe([
            'id',
            'node_id',
            'name',
            'status',
            'installed_version',
            'failed_step',
            'error_code',
        ]);
    });

    it('serializes manager fields exactly and filters by node', function (): void {
        $this->managerRecord->update([
            'installed_version' => '2.0.0',
            'failed_step' => 'probe',
            'error_code' => 'tool.failed',
        ]);
        $other = tools_api_node('other-manager-node', '10.44.0.8');
        $other->toolManagers()->create(['name' => ToolManagerName::Vp, 'status' => LifecycleStatus::Failed]);
        $response = $this->getJson('/api/v1/tool-managers?node_id='.$this->node->id)->assertOk();

        expect($response->json('data'))->toBe([[
            'id' => $this->managerRecord->id,
            'node_id' => $this->node->id,
            'name' => 'apt',
            'status' => 'active',
            'installed_version' => '2.0.0',
            'failed_step' => 'probe',
            'error_code' => 'tool.failed',
        ]]);
    });

    it('lists and shows only tools belonging to the requested node', function (): void {
        $manager = $this->managerRecord;
        $tool = $this->node
            ->tools()
            ->create([
                'tool_manager_id' => $manager->id,
                'package' => 'jq',
                'version_constraint' => '^1.0',
                'status' => ToolStatus::Installed,
                'installed_version' => '1.7.1',
            ]);
        $otherNode = tools_api_node('other-tool-node', '10.44.0.7');
        $otherManager = tools_api_manager($otherNode);
        $otherNode
            ->tools()
            ->create([
                'tool_manager_id' => $otherManager->id,
                'package' => 'curl',
                'status' => ToolStatus::Installed,
                'installed_version' => '8.0.0',
            ]);

        $list = $this
            ->getJson('/api/v1/tools?node_id='.$this->node->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $tool->id);
        $show = $this
            ->getJson('/api/v1/tools/'.$tool->id)
            ->assertOk()
            ->assertJsonPath('data.id', $tool->id)
            ->assertJsonPath('data.package', 'jq');

        $keys = [
            'id',
            'node_id',
            'manager',
            'package',
            'version_constraint',
            'protected',
            'status',
            'installed_version',
            'failed_operation',
            'error_code',
            'outcome',
        ];

        expect(array_keys($list->json('data.0')))
            ->toBe($keys)
            ->and(array_keys($show->json('data')))
            ->toBe($keys);
    });
});

describe('tool writes', function (): void {
    it('installs a tool with a stable applied envelope and persists it', function (): void {
        $this->toolManager->installedVersions = [null, '1.7.1'];
        $this->toolManager->candidateVersions = ['1.7.1'];
        $response = $this->postJson('/api/v1/tools', [
            'node_id' => $this->node->id,
            'manager' => 'apt',
            'package' => 'jq',
            'version_constraint' => '^1.0',
        ]);

        $response
            ->assertCreated()
            ->assertHeader('X-Orbit-Request-Id')
            ->assertJsonPath('data.package', 'jq')
            ->assertJsonPath('data.outcome', 'applied')
            ->assertJsonStructure(['data']);
        expect(array_keys($response->json('data')))->toBe([
            'id',
            'node_id',
            'manager',
            'package',
            'version_constraint',
            'protected',
            'status',
            'installed_version',
            'failed_operation',
            'error_code',
            'outcome',
        ]);
        $tool = Tool::query()->sole();
        expect($tool->node_id)->toBe($this->node->id)->and($tool->package)->toBe('jq');
    });

    it('returns unchanged for an existing install', function (): void {
        $tool = $this->node
            ->tools()
            ->create([
                'tool_manager_id' => $this->managerRecord->id,
                'package' => 'jq',
                'version_constraint' => '^1.0',
                'status' => ToolStatus::Installed,
                'installed_version' => '1.7.1',
            ]);
        $this->toolManager->installedVersions = ['1.7.1'];
        $this
            ->postJson('/api/v1/tools', tools_api_payload($this->node))
            ->assertOk()
            ->assertJsonPath('data.outcome', 'unchanged')
            ->assertJsonPath('data.id', $tool->id);
        expect(Tool::query()->count())->toBe(1);
    });

    it('updates a tool and returns unchanged when already current', function (
        string $before,
        string $candidate,
        string $after,
        string $outcome,
    ): void {
        $tool = $this->node
            ->tools()
            ->create([
                'tool_manager_id' => $this->managerRecord->id,
                'package' => 'jq',
                'version_constraint' => '^1.0',
                'status' => ToolStatus::Installed,
                'installed_version' => '1.0.0',
            ]);
        $this->toolManager->installedVersions = [$before, $after];
        $this->toolManager->candidateVersions = [$candidate];
        $this
            ->postJson('/api/v1/tools/'.$tool->id.'/update')
            ->assertOk()
            ->assertJsonPath('data.outcome', $outcome);
        expect($tool->refresh()->installed_version)->toBe($after);
    })->with([
        'applied' => ['1.0.0', '1.1.0', '1.1.0', 'applied'],
        'unchanged' => ['1.0.0', '1.0.0', '1.0.0', 'unchanged'],
    ]);

    it('blocks an update without changing installed state', function (): void {
        $tool = $this->node
            ->tools()
            ->create([
                'tool_manager_id' => $this->managerRecord->id,
                'package' => 'jq',
                'version_constraint' => '^1.0',
                'status' => ToolStatus::Installed,
                'installed_version' => '1.0.0',
            ]);
        $this->toolManager->installedVersions = ['1.0.0'];
        $this->toolManager->candidateVersions = ['2.0.0'];
        $this
            ->postJson('/api/v1/tools/'.$tool->id.'/update')
            ->assertOk()
            ->assertJsonPath('data.outcome', 'blocked_by_constraint');
        expect($tool->refresh()->installed_version)->toBe('1.0.0')->and($tool->status)->toBe(ToolStatus::Installed);
    });

    it('removes an installed tool and persists the removal', function (): void {
        $tool = $this->node
            ->tools()
            ->create([
                'tool_manager_id' => $this->managerRecord->id,
                'package' => 'jq',
                'status' => ToolStatus::Installed,
                'installed_version' => '1.0.0',
            ]);
        $this->toolManager->removalPlan = new ToolRemovalPlan(['jq']);
        $this->toolManager->installedVersions = ['1.0.0', null];
        $this
            ->deleteJson('/api/v1/tools/'.$tool->id)
            ->assertOk()
            ->assertJsonPath('data.outcome', 'applied');
        expect(Tool::query()->find($tool->id))->toBeNull();
    });
});

describe('tool request validation and isolation', function (): void {
    it('denies all tool endpoints for an active peer without a node access edge', function (): void {
        $consumer = tools_api_node('unauthorized-consumer', '10.44.0.9');
        $tool = $this->node
            ->tools()
            ->create([
                'tool_manager_id' => $this->managerRecord->id,
                'package' => 'jq',
                'status' => ToolStatus::Installed,
                'installed_version' => '1.7.1',
            ]);
        $this->withServerVariables(['REMOTE_ADDR' => $consumer->wireguard_ip]);
        $payload = tools_api_payload($this->node);
        $responses = [
            $this->getJson('/api/v1/tool-managers?node_id='.$this->node->id),
            $this->getJson('/api/v1/tools?node_id='.$this->node->id),
            $this->getJson('/api/v1/tools/'.$tool->id),
            $this->postJson('/api/v1/tools', $payload),
            $this->postJson('/api/v1/tools/'.$tool->id.'/update'),
            $this->deleteJson('/api/v1/tools/'.$tool->id),
        ];
        foreach ($responses as $response) {
            $response->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
        }
        expect($tool->refresh()->installed_version)->toBe('1.7.1')->and($this->toolManager->calls)->toBeEmpty();
    });
    it('returns 422 for invalid strict install JSON', function (string $json, ?string $sentinel = null): void {
        $response = $this
            ->call(
                'POST',
                '/api/v1/tools',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                $json,
            )
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['code', 'message', 'details']])
            ->assertHeader('X-Orbit-Request-Id');

        if ($sentinel !== null) {
            expect($response->getContent())->not->toContain($sentinel);
        }
    })->with([
        'absent body' => [''],
        'malformed' => ['{"node_id":'],
        'scalar' => ['null'],
        'unknown key' => [
            '{"node_id":1,"manager":"apt","package":"jq","version_constraint":null,"sentinel":"raw-caller-sentinel"}',
            'raw-caller-sentinel',
        ],
        'duplicate key' => ['{"node_id":1,"manager":"apt","package":"jq","package":"curl","version_constraint":null}'],
        'escaped duplicate' => [
            '{"node_id":1,"manager":"apt","package":"jq","pack\\u0061ge":"curl","version_constraint":null}',
        ],
        'wrong types' => ['{"node_id":"1","manager":["apt"],"package":42,"version_constraint":false}'],
    ]);

    it('returns 422 for absent or malformed list node identifiers', function (string $path): void {
        $this
            ->getJson($path)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation.failed');
    })->with([
        'tools absent' => ['/api/v1/tools'],
        'tools zero' => ['/api/v1/tools?node_id=0'],
        'tools negative' => ['/api/v1/tools?node_id=-1'],
        'tools malformed' => ['/api/v1/tools?node_id=invalid'],
        'managers absent' => ['/api/v1/tool-managers'],
        'managers zero' => ['/api/v1/tool-managers?node_id=0'],
        'managers negative' => ['/api/v1/tool-managers?node_id=-1'],
        'managers malformed' => ['/api/v1/tool-managers?node_id=invalid'],
    ]);

    it('returns 404 for syntactically valid missing identifiers', function (): void {
        $this->getJson('/api/v1/tools?node_id=999999')->assertNotFound();
        $this->getJson('/api/v1/tool-managers?node_id=999999')->assertNotFound();
        $this->getJson('/api/v1/tools/999999')->assertNotFound();
        $this->postJson('/api/v1/tools', [
            ...tools_api_payload($this->node),
            'node_id' => 999_999,
        ])->assertNotFound();
    });

    it('rejects corrupted cross-node manager ownership before mutation', function (): void {
        $other = tools_api_node('other-node', '10.44.0.4');
        $manager = tools_api_manager($other);
        $tool = $this->node
            ->tools()
            ->create([
                'tool_manager_id' => $manager->id,
                'package' => 'jq',
                'status' => ToolStatus::Installed,
            ]);

        $this->getJson('/api/v1/tools/'.$tool->id)->assertOk();
        assert_tools_api_error(
            $this->postJson('/api/v1/tools/'.$tool->id.'/update'),
            409,
            'tool.state_invalid',
            'manager_failed',
            'update',
        );
        assert_tools_api_error(
            $this->deleteJson('/api/v1/tools/'.$tool->id),
            409,
            'tool.state_invalid',
            'manager_failed',
            'remove',
        );
    });
});

describe('tool error contracts', function (): void {
    it('redacts operational diagnostics from stable errors', function (): void {
        $sentinel = 'caller-sentinel-'.Str::uuid();
        $response = $this
            ->postJson('/api/v1/tools', [
                'node_id' => $this->node->id,
                'manager' => 'unknown',
                'package' => $sentinel,
                'version_constraint' => 'not-a-constraint',
            ])
            ->assertUnprocessable()
            ->assertHeader('X-Orbit-Request-Id');

        expect($response->json('error'))
            ->toHaveKeys(['code', 'message', 'details'])
            ->and($response->getContent())
            ->not->toContain($sentinel)
            ->not->toContain('stdout')
            ->not->toContain('stderr')
            ->not->toContain('argv')
            ->not->toContain('http://');
    });
});

describe('tool lifecycle failure contracts', function (): void {
    it('maps pre-row install failures to stable errors', function (string $case): void {
        $payload = tools_api_payload($this->node);

        match ($case) {
            'unsupported manager' => $payload['manager'] = 'npm',
            'invalid package' => $this->toolManager->validPackage = false,
            'invalid constraint' => $payload['version_constraint'] = 'not-a-constraint',
            'inactive node' => $this->node->update(['status' => LifecycleStatus::Failed]),
            'unsupported node' => $this->toolManager->supports = false,
            'missing manager record' => $this->managerRecord->delete(),
            'inactive manager record' => $this->managerRecord->update(['status' => LifecycleStatus::Failed]),
        };

        [$status, $code, $outcome] = match ($case) {
            'unsupported manager' => [422, 'tool.manager_unsupported', 'manager_failed'],
            'invalid package' => [422, 'tool.package_invalid', 'manager_failed'],
            'invalid constraint' => [422, 'tool.constraint_invalid', 'constraint_invalid'],
            'inactive node' => [409, 'tool.node_inactive', 'manager_failed'],
            default => [409, 'tool.manager_unavailable', 'manager_failed'],
        };

        assert_tools_api_error(
            $this->postJson('/api/v1/tools', $payload),
            $status,
            $code,
            $outcome,
            'install',
        );

        expect(Tool::query()->count())->toBe(0);
    })->with([
        'unsupported manager',
        'invalid package',
        'invalid constraint',
        'inactive node',
        'unsupported node',
        'missing manager record',
        'inactive manager record',
    ]);

    it('maps candidate failures and retains a failed row', function (
        string|ToolManagerException|null $candidate,
        int $status,
        string $code,
        string $outcome,
    ): void {
        $this->toolManager->installedVersions = [null];
        $this->toolManager->candidateVersions = [$candidate];

        assert_tools_api_error(
            $this->postJson('/api/v1/tools', tools_api_payload($this->node)),
            $status,
            $code,
            $outcome,
            'install',
        );

        $tool = Tool::query()->sole();

        expect($tool->status)
            ->toBe(ToolStatus::Failed)
            ->and($tool->failed_operation)
            ->toBe(ToolOperation::Install)
            ->and($tool->error_code)
            ->toBe($code);
    })->with([
        'probe failure' => [
            new ToolManagerException('candidate', 'candidate failed'),
            502,
            'tool.candidate_version_probe_failed',
            'candidate_version_unavailable',
        ],
        'unavailable' => [null, 422, 'tool.candidate_version_unavailable', 'candidate_version_unavailable'],
        'unparseable' => ['release-2.4', 422, 'tool.candidate_version_unparseable', 'candidate_version_unparseable'],
        'blocked' => ['3.0.0', 422, 'tool.version_constraint_blocked', 'blocked_by_constraint'],
    ]);

    it('maps install and post-install failures and retains the failed row', function (
        mixed $after,
        ?ToolManagerException $installFailure,
        int $status,
        string $code,
    ): void {
        $this->toolManager->candidateVersions = ['1.1.0'];
        $this->toolManager->installedVersions = $installFailure instanceof ToolManagerException
            ? [null]
            : [null, $after];

        if ($installFailure instanceof ToolManagerException) {
            $this->toolManager->failures['install'] = [$installFailure];
        }

        assert_tools_api_error(
            $this->postJson('/api/v1/tools', tools_api_payload($this->node)),
            $status,
            $code,
            'manager_failed',
            'install',
        );

        $tool = Tool::query()->sole();

        expect($tool->status)
            ->toBe(ToolStatus::Failed)
            ->and($tool->failed_operation)
            ->toBe(ToolOperation::Install)
            ->and($tool->error_code)
            ->toBe($code);
    })->with([
        'manager failure' => [null, new ToolManagerException('install', 'install failed'), 502, 'tool.install_failed'],
        'version probe failure' => [
            new ToolManagerException('installed', 'probe failed'),
            null,
            502,
            'tool.version_probe_failed',
        ],
        'installed version unparseable' => ['release-2.4', null, 409, 'tool.installed_version_unparseable'],
        'installed version violates constraint' => ['2.0.0', null, 409, 'tool.installed_version_constraint_violated'],
    ]);

    it('maps update failures and retains the failed row', function (
        mixed $after,
        ?ToolManagerException $updateFailure,
        int $status,
        string $code,
    ): void {
        $tool = $this->node
            ->tools()
            ->create([
                'tool_manager_id' => $this->managerRecord->id,
                'package' => 'jq',
                'version_constraint' => '^1.0',
                'status' => ToolStatus::Installed,
                'installed_version' => '1.0.0',
            ]);
        $this->toolManager->candidateVersions = ['1.1.0'];
        $this->toolManager->installedVersions = $updateFailure instanceof ToolManagerException
            ? ['1.0.0']
            : ['1.0.0', $after];

        if ($updateFailure instanceof ToolManagerException) {
            $this->toolManager->failures['update'] = [$updateFailure];
        }

        assert_tools_api_error(
            $this->postJson('/api/v1/tools/'.$tool->id.'/update'),
            $status,
            $code,
            'manager_failed',
            'update',
        );

        $tool->refresh();

        expect($tool->status)
            ->toBe(ToolStatus::Failed)
            ->and($tool->failed_operation)
            ->toBe(ToolOperation::Update)
            ->and($tool->error_code)
            ->toBe($code);
    })->with([
        'manager failure' => [null, new ToolManagerException('update', 'update failed'), 502, 'tool.update_failed'],
        'installed version unparseable' => ['release-2.4', null, 409, 'tool.installed_version_unparseable'],
        'installed version violates constraint' => ['2.0.0', null, 409, 'tool.installed_version_constraint_violated'],
    ]);

    it('rejects protected removal without deleting the tool', function (): void {
        $tool = $this->node
            ->tools()
            ->create([
                'tool_manager_id' => $this->managerRecord->id,
                'package' => 'jq',
                'status' => ToolStatus::Installed,
                'protected' => true,
            ]);

        assert_tools_api_error(
            $this->deleteJson('/api/v1/tools/'.$tool->id),
            409,
            'tool.protected',
            'manager_failed',
            'remove',
        );

        $this->assertModelExists($tool);
    });

    it('rejects an installed unmanaged package without adopting it', function (): void {
        $this->toolManager->installedVersions = ['1.7.1'];
        $payload = tools_api_payload($this->node);
        $payload['package'] = 'curl';

        assert_tools_api_error(
            $this->postJson('/api/v1/tools', $payload),
            409,
            'tool.already_installed_unmanaged',
            'manager_failed',
            'install',
        );

        expect(Tool::query()->where('package', 'curl')->exists())->toBeFalse();
    });
});

function tools_api_node(string $name, string $address): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.30',
        'wireguard_ip' => $address,
    ]);
}

function tools_api_manager(Node $node): ToolManagerRecord
{
    return $node->toolManagers()->create(['name' => ToolManagerName::Apt, 'status' => LifecycleStatus::Active]);
}

/** @return array{node_id:int, manager:string, package:string, version_constraint:string} */
function tools_api_payload(Node $node): array
{
    return ['node_id' => $node->id, 'manager' => 'apt', 'package' => 'jq', 'version_constraint' => '^1.0'];
}

function assert_tools_api_error(
    TestResponse $response,
    int $status,
    string $code,
    string $outcome,
    string $step,
): void {
    $response
        ->assertStatus($status)
        ->assertHeader('X-Orbit-Request-Id')
        ->assertJsonPath('error.code', $code)
        ->assertJsonPath('error.details.step', $step)
        ->assertJsonPath('error.details.outcome', $outcome);

    expect(array_keys($response->json()))
        ->toBe(['error'])
        ->and(array_keys($response->json('error')))
        ->toBe(['code', 'message', 'details'])
        ->and(array_keys($response->json('error.details')))
        ->toBe(['step', 'outcome']);
}
