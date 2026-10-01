<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolAdoptionFact;
use App\Domain\Tools\ToolInventoryPackage;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolStatus;
use App\Infrastructure\Nodes\NodeLocks;
use App\Models\Node;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeToolManager;

beforeEach(function (): void {
    $this->gateway = adopt_api_node('adopt-gateway', '10.44.0.2');
    $this->markAsGateway($this->gateway);
    $this->node = adopt_api_node('adopt-node', '10.44.0.3');
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $this->withHeader('X-Orbit-Request-Id', fixture_request_id());
    $this->toolManager = new FakeToolManager(ToolManagerName::Apt);
    $this->toolManager->adoption = new ToolAdoptionFact('1.2.3', null);
    app()->instance(ToolManagerRegistry::class, new ToolManagerRegistry([$this->toolManager]));
});

describe('tool adoption api', function (): void {
    it('adopts an installed package without bootstrapping', function (): void {
        $response = $this->postJson('/api/v1/tools/adopt', adopt_api_payload($this->node))
            ->assertCreated()
            ->assertJsonPath('data.status', 'installed')
            ->assertJsonPath('data.installed_version', '1.2.3')
            ->assertJsonPath('data.outcome', 'applied')
            ->assertJsonPath('data.failed_operation', null)
            ->assertJsonPath('data.node_id', $this->node->id)
            ->assertJsonPath('data.package', 'jq');

        expect(Tool::query()->count())->toBe(1)
            ->and($this->toolManager->calls)->toBe(['validatePackage', 'inspectForAdoption'])
            ->and($this->toolManager->calls)->not->toContain('install', 'update', 'remove', 'materialize');
        record_adopt_fixture($response, 'adopted');
    });

    it('keeps the recorded version when the same intent is already installed', function (): void {
        $record = adopt_api_manager($this->node);
        $tool = $this->node->tools()->create([
            'tool_manager_id' => $record->id,
            'package' => 'jq',
            'version_constraint' => '^1.0',
            'status' => ToolStatus::Installed,
            'installed_version' => '1.0.0',
        ]);
        $this->toolManager->adoption = new ToolAdoptionFact('1.2.3', null);

        $response = $this->postJson('/api/v1/tools/adopt', adopt_api_payload($this->node, '^1.0'))
            ->assertOk()
            ->assertJsonPath('data.id', $tool->id)
            ->assertJsonPath('data.outcome', 'unchanged')
            ->assertJsonPath('data.installed_version', '1.0.0');

        expect($tool->refresh()->installed_version)->toBe('1.0.0');
        record_adopt_fixture($response, 'unchanged');
    });

    it('refuses drift on an installed tool and leaves the recorded version', function (): void {
        $record = adopt_api_manager($this->node);
        $tool = $this->node->tools()->create([
            'tool_manager_id' => $record->id,
            'package' => 'jq',
            'version_constraint' => '^1.0',
            'status' => ToolStatus::Installed,
            'installed_version' => '1.0.0',
        ]);
        $updatedAt = $tool->updated_at?->toJSON();
        $this->toolManager->adoption = new ToolAdoptionFact('2.0.0', null);

        $response = $this->postJson('/api/v1/tools/adopt', adopt_api_payload($this->node, '^1.0'));
        assert_adopt_error($response, 409, 'tool.installed_version_constraint_violated', $tool->id);
        $tool->refresh();

        expect($tool->installed_version)->toBe('1.0.0')
            ->and($tool->status)->toBe(ToolStatus::Installed)
            ->and($tool->updated_at?->toJSON())->toBe($updatedAt);
        record_adopt_fixture($response, 'constraint-drift');
    });

    it('repairs a failed tool without changing the host', function (): void {
        $record = adopt_api_manager($this->node);
        $this->node->tools()->create([
            'tool_manager_id' => $record->id,
            'package' => 'jq',
            'version_constraint' => '^1.0',
            'status' => ToolStatus::Failed,
            'installed_version' => '1.0.0',
            'failed_operation' => ToolOperation::Remove,
            'error_code' => 'tool.remove_failed',
        ]);
        $this->toolManager->adoption = new ToolAdoptionFact('1.4.2', null);

        $response = $this->postJson('/api/v1/tools/adopt', adopt_api_payload($this->node, '^1.0'))
            ->assertOk()
            ->assertJsonPath('data.status', 'installed')
            ->assertJsonPath('data.outcome', 'applied')
            ->assertJsonPath('data.installed_version', '1.4.2')
            ->assertJsonPath('data.failed_operation', null)
            ->assertJsonPath('data.error_code', null);

        record_adopt_fixture($response, 'repaired');
    });

    it('returns state invalid for a tool left installing', function (): void {
        $record = adopt_api_manager($this->node);
        $tool = $this->node->tools()->create([
            'tool_manager_id' => $record->id,
            'package' => 'jq',
            'status' => ToolStatus::Installing,
            'installed_version' => null,
        ]);

        $response = $this->postJson('/api/v1/tools/adopt', adopt_api_payload($this->node));
        assert_adopt_error($response, 409, 'tool.state_invalid', $tool->id);
        expect($tool->refresh()->status)->toBe(ToolStatus::Installing);
        record_adopt_fixture($response, 'state-invalid');
    });

    it('conflicts on a different constraint', function (): void {
        $record = adopt_api_manager($this->node);
        $tool = $this->node->tools()->create([
            'tool_manager_id' => $record->id,
            'package' => 'jq',
            'version_constraint' => '^1.0',
            'status' => ToolStatus::Installed,
            'installed_version' => '1.2.3',
        ]);

        $response = $this->postJson('/api/v1/tools/adopt', adopt_api_payload($this->node, '^2.0'));
        assert_adopt_error($response, 409, 'tool.constraint_conflict', $tool->id);
        record_adopt_fixture($response, 'constraint-conflict');
    });

    it('publishes unsupported, absent, and unverifiable failures with no tool', function (
        ?ToolAdoptionFact $fact,
        ?Throwable $failure,
        string $code,
        ?string $block,
        string $fixture,
    ): void {
        $this->toolManager->adoption = $fact;
        $this->toolManager->adoptionFailure = $failure;

        $response = $this->postJson('/api/v1/tools/adopt', adopt_api_payload($this->node));
        assert_adopt_error($response, 409, $code, null, $block);
        expect(Tool::query()->count())->toBe(0);
        record_adopt_fixture($response, $fixture);
    })->with([
        'protected' => [new ToolAdoptionFact('1.0.0', ToolInventoryPackage::BLOCK_PROTECTED), null, 'tool.adoption_unsupported', 'protected', 'protected'],
        'dependency' => [new ToolAdoptionFact('1.0.0', ToolInventoryPackage::BLOCK_DEPENDENCY), null, 'tool.adoption_unsupported', 'dependency', 'dependency'],
        'absent' => [new ToolAdoptionFact(null, null), null, 'tool.package_absent', null, 'absent'],
        'scope conflict' => [null, new ToolManagerException('manager-conflict', 'conflict'), 'tool.manager_unavailable', null, 'manager-unavailable'],
        'probe' => [null, new ToolManagerException('installed-version', 'unreadable'), 'tool.version_probe_failed', null, 'version-probe-failed'],
    ]);

    it('rejects a live version that does not satisfy the constraint', function (
        string $version,
        string $code,
        string $fixture,
    ): void {
        $this->toolManager->adoption = new ToolAdoptionFact($version, null);

        $response = $this->postJson('/api/v1/tools/adopt', adopt_api_payload($this->node, '^1.0'));
        assert_adopt_error($response, 409, $code);
        expect(Tool::query()->count())->toBe(0);
        record_adopt_fixture($response, $fixture);
    })->with([
        'outside range' => ['2.0.0', 'tool.installed_version_constraint_violated', 'constraint-violated'],
        'unparseable' => ['release-2.4', 'tool.installed_version_unparseable', 'version-unparseable'],
    ]);

    it('rejects an unsupported manager and an invalid package or constraint before a tool exists', function (
        string $field,
        string $value,
        string $code,
        string $fixture,
    ): void {
        $payload = adopt_api_payload($this->node);
        $payload[$field] = $value;

        if ($field === 'package') {
            $this->toolManager->validPackage = false;
        }

        $response = $this->postJson('/api/v1/tools/adopt', $payload);
        assert_adopt_error($response, 422, $code);
        expect(Tool::query()->count())->toBe(0);
        record_adopt_fixture($response, $fixture);
    })->with([
        'manager' => ['manager', 'npm', 'tool.manager_unsupported', 'manager-unsupported'],
        'package' => ['package', '--option', 'tool.package_invalid', 'package-invalid'],
        'constraint' => ['version_constraint', 'not a constraint', 'tool.constraint_invalid', 'constraint-invalid'],
    ]);

    it('rejects an unknown field before lookup', function (): void {
        $payload = adopt_api_payload($this->node);
        $payload['sudo'] = true;
        $response = $this->postJson('/api/v1/tools/adopt', $payload)->assertUnprocessable();
        expect($response->json('error.code'))->toBe('validation.failed')
            ->and(Tool::query()->count())->toBe(0);
        record_adopt_fixture($response, 'validation-failed');
    });

    it('rejects an inactive or unmanaged node', function (string $field, string $code, string $fixture): void {
        $this->node->update([$field => $field === 'status' ? LifecycleStatus::Failed : null]);

        $response = $this->postJson('/api/v1/tools/adopt', adopt_api_payload($this->node));
        assert_adopt_error($response, 409, $code);
        expect(Tool::query()->count())->toBe(0);
        record_adopt_fixture($response, $fixture);
    })->with([
        'inactive' => ['status', 'tool.node_inactive', 'node-inactive'],
        'unmanaged' => ['ssh_host_fingerprint', 'tool.node_unmanaged', 'node-unmanaged'],
    ]);

    it('denies a peer without access', function (): void {
        $consumer = adopt_api_node('adopt-consumer', '10.44.0.9');

        $response = $this->withServerVariables(['REMOTE_ADDR' => $consumer->wireguard_ip])
            ->postJson('/api/v1/tools/adopt', adopt_api_payload($this->node))
            ->assertForbidden();

        expect(Tool::query()->count())->toBe(0);
        record_adopt_fixture($response, 'access-required');
    });

    it('returns operation locked while the tool identity is busy', function (): void {
        $held = app(NodeLocks::class)->lock(
            'tool:'.$this->node->id.':apt:'.hash('sha256', 'jq'),
            30,
        );
        expect($held->get())->toBeTrue();

        try {
            $response = $this->postJson('/api/v1/tools/adopt', adopt_api_payload($this->node));
            assert_adopt_error($response, 409, 'tool.operation_locked');
            expect(Tool::query()->count())->toBe(0);
            record_adopt_fixture($response, 'operation-locked');
        } finally {
            $held->release();
        }
    });

    it('still refuses install of an unmanaged package and points the caller at adoption', function (): void {
        adopt_api_manager($this->node);
        $this->toolManager->installedVersions = ['1.7.1'];

        $response = $this->postJson('/api/v1/tools', [
            'node_id' => $this->node->id,
            'manager' => 'apt',
            'package' => 'jq',
            'version_constraint' => null,
        ]);

        $response
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'tool.already_installed_unmanaged')
            ->assertJsonPath('error.details.step', 'install')
            ->assertJsonPath('error.details.outcome', 'manager_failed');
        expect($response->json('error.message'))->toContain('Orbit management')
            ->and(Tool::query()->count())->toBe(0);

        $this->postJson('/api/v1/tools/adopt', adopt_api_payload($this->node))
            ->assertCreated()
            ->assertJsonPath('data.package', 'jq');
        expect(Tool::query()->count())->toBe(1);
    });
});

/** @return array{node_id: int, manager: string, package: string, version_constraint: ?string} */
function adopt_api_node(string $name, string $address): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.30',
        'wireguard_ip' => $address,
        'ssh_host_fingerprint' => 'SHA256:'.str_repeat('A', times: 43),
    ]);
}

function adopt_api_payload(Node $node, ?string $constraint = null): array
{
    return [
        'node_id' => $node->id,
        'manager' => 'apt',
        'package' => 'jq',
        'version_constraint' => $constraint,
    ];
}

function adopt_api_manager(Node $node): ToolManagerRecord
{
    return $node->toolManagers()->create([
        'name' => ToolManagerName::Apt,
        'status' => LifecycleStatus::Active,
    ]);
}

function record_adopt_fixture(TestResponse $response, string $name): void
{
    record_fixture(
        $response,
        'tools/tool-adopt/'.$name,
        'Orbit\\Sdk\\Requests\\Tools\\AdoptToolRequest',
        'POST /api/v1/tools/adopt',
    );
}

function assert_adopt_error(
    TestResponse $response,
    int $status,
    string $code,
    ?int $toolId = null,
    ?string $adoptionBlock = null,
): void {
    $details = ['step', 'outcome'];

    $response
        ->assertStatus($status)
        ->assertJsonPath('error.code', $code)
        ->assertJsonPath('error.details.step', 'adopt')
        ->assertJsonPath('error.details.outcome', $code === 'tool.constraint_invalid' ? 'constraint_invalid' : 'manager_failed');

    if ($toolId !== null) {
        $details[] = 'id';
        $response->assertJsonPath('error.details.id', $toolId);
    }

    if ($adoptionBlock !== null) {
        $details[] = 'adoption_block';
        $response->assertJsonPath('error.details.adoption_block', $adoptionBlock);
    }

    expect(array_keys($response->json('error.details')))->toBe($details);
}
