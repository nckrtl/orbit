<?php

declare(strict_types=1);

use App\Actions\Tools\UpdateToolAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolOperationException;
use App\Domain\Tools\ToolOutcome;
use App\Domain\Tools\ToolStatus;
use App\Domain\Tools\VersionConstraint;
use App\Models\Node;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Tests\Support\FakeToolManager;
use Tests\Support\ImmediateToolOperationLock;

/** @mago-expect lint:halstead The update matrix keeps every required state transition and failure contract visible. */
describe(UpdateToolAction::class, function (): void {
    it('returns unchanged and applied for equal and different raw versions', function (
        string $before,
        string $after,
        ToolOutcome $outcome,
    ): void {
        [$action, $manager, $lock, $tool] = update_action_fixture();
        $manager->installedVersions = [$before, $after];

        $result = $action->execute($tool);
        $stored = $tool->refresh();

        expect($result->outcome)
            ->toBe($outcome)
            ->and($stored->status)
            ->toBe(ToolStatus::Installed)
            ->and($stored->installed_version)
            ->toBe($after)
            ->and($stored->failed_operation)
            ->toBeNull()
            ->and($stored->error_code)
            ->toBeNull()
            ->and($manager->calls)
            ->toBe(['installedVersion', 'update', 'installedVersion'])
            ->and($lock->runs)
            ->toBe(1);
    })->with([
        'raw versions equal' => ['2.4.1-1ubuntu1', '2.4.1-1ubuntu1', ToolOutcome::Unchanged],
        'raw versions differ' => ['2.4.1-1ubuntu1', '2.4.2-1ubuntu1', ToolOutcome::Applied],
    ]);

    it('retries a failed update after the first mutation failed', function (): void {
        [$action, $manager, $lock, $tool] = update_action_fixture(
            status: ToolStatus::Failed,
            failedOperation: ToolOperation::Update,
            errorCode: 'tool.update_failed',
        );
        $manager->installedVersions = ['2.4.0'];
        $manager->failures['update'] = [new ToolManagerException('update', 'Update failed.')];

        $first = update_action_exception(fn (): mixed => $action->execute($tool));

        $manager->installedVersions = ['2.4.1', '2.4.2'];
        $second = $action->execute($tool->refresh());
        $stored = $tool->refresh();

        expect($first->errorCode)
            ->toBe('tool.update_failed')
            ->and($stored->status)
            ->toBe(ToolStatus::Installed)
            ->and($stored->installed_version)
            ->toBe('2.4.2')
            ->and($stored->failed_operation)
            ->toBeNull()
            ->and($stored->error_code)
            ->toBeNull()
            ->and($second->outcome)
            ->toBe(ToolOutcome::Applied)
            ->and($lock->runs)
            ->toBe(2);
    });

    it('rejects invalid persisted update states before manager probes', function (
        ToolStatus $status,
        ?ToolOperation $failedOperation,
    ): void {
        [$action, $manager, $lock, $tool] = update_action_fixture(
            status: $status,
            failedOperation: $failedOperation,
            errorCode: $failedOperation === null ? null : 'tool.'.$failedOperation->value.'_failed',
        );

        $exception = update_action_exception(fn (): mixed => $action->execute($tool));

        expect($exception->errorCode)
            ->toBe('tool.state_invalid')
            ->and($exception->status)
            ->toBe(409)
            ->and($exception->outcome)
            ->toBe(ToolOutcome::ManagerFailed)
            ->and($manager->calls)
            ->toBeEmpty()
            ->and($lock->runs)
            ->toBe(0)
            ->and($tool->refresh()->status)
            ->toBe($status);
    })->with([
        'installing' => [ToolStatus::Installing, null],
        'updating' => [ToolStatus::Updating, null],
        'removing' => [ToolStatus::Removing, null],
        'failed install' => [ToolStatus::Failed, ToolOperation::Install],
        'failed remove' => [ToolStatus::Failed, ToolOperation::Remove],
    ]);

    it('rejects a tool whose manager belongs to another node', function (): void {
        $node = update_action_node();
        $otherNode = update_action_node();
        $record = update_action_manager($otherNode);
        $tool = $node->tools()->create([
            'tool_manager_id' => $record->id,
            'package' => 'jq',
            'status' => ToolStatus::Installed,
            'installed_version' => '2.4.0',
        ]);
        [$action, $manager, $lock] = update_action_fixture_for_node($node);

        $exception = update_action_exception(fn (): mixed => $action->execute($tool));

        expect($exception->errorCode)
            ->toBe('tool.state_invalid')
            ->and($exception->status)
            ->toBe(409)
            ->and($manager->calls)
            ->toBeEmpty()
            ->and($lock->runs)
            ->toBe(0);
    });

    it('rejects inactive nodes before manager probes', function (): void {
        $node = update_action_node(status: LifecycleStatus::Failed);
        $record = update_action_manager($node);
        $tool = update_action_tool($node, $record);
        [$action, $manager, $lock] = update_action_fixture_for_node($node);

        $exception = update_action_exception(fn (): mixed => $action->execute($tool));

        expect($exception->errorCode)
            ->toBe('tool.node_inactive')
            ->and($exception->status)
            ->toBe(409)
            ->and($manager->calls)
            ->toBeEmpty()
            ->and($lock->runs)
            ->toBe(0);
    });

    it('rejects inactive managers before manager probes', function (): void {
        $node = update_action_node();
        $record = update_action_manager($node, status: LifecycleStatus::Failed);
        $tool = update_action_tool($node, $record);
        [$action, $manager, $lock] = update_action_fixture_for_node($node);

        $exception = update_action_exception(fn (): mixed => $action->execute($tool));

        expect($exception->errorCode)
            ->toBe('tool.manager_unavailable')
            ->and($exception->status)
            ->toBe(409)
            ->and($manager->calls)
            ->toBeEmpty()
            ->and($lock->runs)
            ->toBe(0);
    });

    it('requires an active app role before VP probes', function (ToolManagerName $managerName, string $package): void {
        $node = update_action_node();
        $record = update_action_manager($node, name: $managerName);
        $tool = update_action_tool($node, $record, package: $package);
        [$action, $manager, $lock] = update_action_fixture_for_node($node, $managerName);

        $exception = update_action_exception(fn (): mixed => $action->execute($tool));

        expect($exception->errorCode)
            ->toBe('tool.app_role_required')
            ->and($exception->status)
            ->toBe(409)
            ->and($exception->outcome)
            ->toBe(ToolOutcome::ManagerFailed)
            ->and($manager->calls)
            ->toBeEmpty()
            ->and($lock->runs)
            ->toBe(0);
    })->with([
        'VP' => [ToolManagerName::Vp, '@openai/codex'],
        'Composer' => [ToolManagerName::Composer, 'laravel/installer'],
    ]);

    it('rejects provisioning app roles before VP or Composer probes', function (
        ToolManagerName $managerName,
        RoleName $role,
        string $package,
    ): void {
        $node = update_action_node(role: $role, roleStatus: LifecycleStatus::Provisioning);
        $record = update_action_manager($node, name: $managerName);
        $tool = update_action_tool($node, $record, package: $package);
        [$action, $manager, $lock] = update_action_fixture_for_node($node, $managerName);

        $exception = update_action_exception(fn (): mixed => $action->execute($tool));

        expect($exception->errorCode)
            ->toBe('tool.app_role_required')
            ->and($exception->status)
            ->toBe(409)
            ->and($manager->calls)
            ->toBeEmpty()
            ->and($lock->runs)
            ->toBe(0);
    })->with([
        'VP with provisioning app-dev' => [ToolManagerName::Vp, RoleName::AppDev, '@openai/codex'],
        'Composer with provisioning app-prod' => [ToolManagerName::Composer, RoleName::AppProd, 'laravel/installer'],
    ]);

    it('reports the missing app role before unavailable app-scoped manager state', function (): void {
        $node = update_action_node();
        $record = update_action_manager(
            $node,
            name: ToolManagerName::Vp,
            status: LifecycleStatus::Failed,
        );
        $tool = update_action_tool($node, $record, package: '@openai/codex');
        [$action, $manager, $lock] = update_action_fixture_for_node($node, ToolManagerName::Vp);

        $exception = update_action_exception(fn (): mixed => $action->execute($tool));

        expect($exception->errorCode)
            ->toBe('tool.app_role_required')
            ->and($manager->calls)
            ->toBeEmpty()
            ->and($lock->runs)
            ->toBe(0);
    });

    it('rejects a missing live package and retains a bounded update failure', function (): void {
        [$action, $manager, $lock, $tool] = update_action_fixture();
        $manager->installedVersions = [null];

        $exception = update_action_exception(fn (): mixed => $action->execute($tool));
        $stored = $tool->refresh();

        expect($exception->errorCode)
            ->toBe('tool.version_probe_failed')
            ->and($exception->status)
            ->toBe(502)
            ->and($stored->status)
            ->toBe(ToolStatus::Failed)
            ->and($stored->failed_operation)
            ->toBe(ToolOperation::Update)
            ->and($stored->error_code)
            ->toBe('tool.version_probe_failed')
            ->and($manager->calls)
            ->toBe(['installedVersion'])
            ->and($lock->runs)
            ->toBe(1);
    });

    it('retains an installed probe failure before mutation', function (): void {
        [$action, $manager, $lock, $tool] = update_action_fixture();
        $manager->installedVersions = [new ToolManagerException('installed-version', 'Probe failed.')];

        $exception = update_action_exception(fn (): mixed => $action->execute($tool));

        expect($exception->errorCode)
            ->toBe('tool.version_probe_failed')
            ->and($exception->status)
            ->toBe(502)
            ->and($tool->refresh()->failed_operation)
            ->toBe(ToolOperation::Update)
            ->and($manager->calls)
            ->toBe(['installedVersion'])
            ->and($lock->runs)
            ->toBe(1);
    });

    it('rejects an unbounded live version without replacing bounded retry state', function (): void {
        [$action, $manager, $lock, $tool] = update_action_fixture();
        $manager->installedVersions = [str_repeat('1', times: 256)];

        $exception = update_action_exception(fn (): mixed => $action->execute($tool));
        $stored = $tool->refresh();

        expect($exception->errorCode)
            ->toBe('tool.version_probe_failed')
            ->and($exception->status)
            ->toBe(502)
            ->and($stored->status)
            ->toBe(ToolStatus::Failed)
            ->and($stored->installed_version)
            ->toBe('2.4.0')
            ->and($manager->calls)
            ->toBe(['installedVersion'])
            ->and($lock->runs)
            ->toBe(1);
    });

    it('fails closed when the constrained live version cannot be normalized', function (
        string $rawVersion,
        string $errorCode,
    ): void {
        [$action, $manager, $lock, $tool] = update_action_fixture(versionConstraint: '^2.4');
        $manager->installedVersions = [$rawVersion];

        $exception = update_action_exception(fn (): mixed => $action->execute($tool));
        $stored = $tool->refresh();

        expect($exception->errorCode)
            ->toBe($errorCode)
            ->and($exception->status)
            ->toBe(409)
            ->and($stored->status)
            ->toBe(ToolStatus::Failed)
            ->and($stored->failed_operation)
            ->toBe(ToolOperation::Update)
            ->and($stored->installed_version)
            ->toBe($rawVersion)
            ->and($stored->error_code)
            ->toBe($errorCode)
            ->and($manager->calls)
            ->toBe(['installedVersion'])
            ->and($lock->runs)
            ->toBe(1);
    })->with([
        'unparseable live version' => ['release-2.4', 'tool.installed_version_unparseable'],
        'outside live constraint' => ['3.0.0', 'tool.installed_version_constraint_violated'],
    ]);

    it('retains candidate failures without calling update', function (
        mixed $candidate,
        string $errorCode,
        ToolOutcome $outcome,
        int $status,
    ): void {
        [$action, $manager, $lock, $tool] = update_action_fixture(versionConstraint: '^2.4');
        $manager->installedVersions = ['2.4.0'];
        $manager->candidateVersions = [$candidate];

        $exception = update_action_exception(fn (): mixed => $action->execute($tool));
        $stored = $tool->refresh();

        expect($exception->errorCode)
            ->toBe($errorCode)
            ->and($exception->outcome)
            ->toBe($outcome)
            ->and($exception->status)
            ->toBe($status)
            ->and($stored->status)
            ->toBe(ToolStatus::Failed)
            ->and($stored->failed_operation)
            ->toBe(ToolOperation::Update)
            ->and($stored->error_code)
            ->toBe($errorCode)
            ->and($manager->calls)
            ->toBe(['installedVersion', 'candidateVersion'])
            ->and($lock->runs)
            ->toBe(1);
    })->with([
        'candidate probe exception' => [
            new ToolManagerException('candidate-version', 'Candidate probe failed.'),
            'tool.candidate_version_probe_failed',
            ToolOutcome::CandidateVersionUnavailable,
            502,
        ],
        'missing candidate' => [
            null,
            'tool.candidate_version_unavailable',
            ToolOutcome::CandidateVersionUnavailable,
            422,
        ],
        'unparseable candidate' => [
            'release-2.4',
            'tool.candidate_version_unparseable',
            ToolOutcome::CandidateVersionUnparseable,
            422,
        ],
    ]);

    it('returns a blocked update without mutation and keeps the tool installed', function (): void {
        [$action, $manager, $lock, $tool] = update_action_fixture(versionConstraint: '^2.4');
        $manager->installedVersions = ['2.4.0'];
        $manager->candidateVersions = ['3.0.0'];

        $result = $action->execute($tool);
        $stored = $tool->refresh();

        expect($result->outcome)
            ->toBe(ToolOutcome::BlockedByConstraint)
            ->and($stored->status)
            ->toBe(ToolStatus::Installed)
            ->and($stored->installed_version)
            ->toBe('2.4.0')
            ->and($stored->failed_operation)
            ->toBeNull()
            ->and($stored->error_code)
            ->toBeNull()
            ->and($manager->calls)
            ->toBe(['installedVersion', 'candidateVersion'])
            ->and($lock->runs)
            ->toBe(1);
    });

    it('retains a manager update failure for retry', function (): void {
        [$action, $manager, $lock, $tool] = update_action_fixture();
        $manager->installedVersions = ['2.4.0'];
        $manager->failures['update'] = [new ToolManagerException('update', 'Update failed.')];

        $exception = update_action_exception(fn (): mixed => $action->execute($tool));
        $stored = $tool->refresh();

        expect($exception->errorCode)
            ->toBe('tool.update_failed')
            ->and($exception->status)
            ->toBe(502)
            ->and($stored->status)
            ->toBe(ToolStatus::Failed)
            ->and($stored->failed_operation)
            ->toBe(ToolOperation::Update)
            ->and($stored->error_code)
            ->toBe('tool.update_failed')
            ->and($manager->calls)
            ->toBe(['installedVersion', 'update'])
            ->and($lock->runs)
            ->toBe(1);
    });

    it('does not retain unknown update exceptions as previous failures', function (): void {
        [$action, $manager, $lock, $tool] = update_action_fixture();
        $manager->installedVersions = ['2.4.0'];
        $manager->failures['update'] = [new RuntimeException('Sensitive runtime failure.')];

        $exception = update_action_exception(fn (): mixed => $action->execute($tool));

        expect($exception->errorCode)
            ->toBe('tool.update_failed')
            ->and($exception->getPrevious())
            ->toBeNull()
            ->and($tool->refresh()->failed_operation)
            ->toBe(ToolOperation::Update)
            ->and($manager->calls)
            ->toBe(['installedVersion', 'update'])
            ->and($lock->runs)
            ->toBe(1);
    });

    it('retains a post-update installed probe failure', function (mixed $after): void {
        [$action, $manager, $lock, $tool] = update_action_fixture();
        $manager->installedVersions = ['2.4.0', $after];

        $exception = update_action_exception(fn (): mixed => $action->execute($tool));
        $stored = $tool->refresh();

        expect($exception->errorCode)
            ->toBe('tool.version_probe_failed')
            ->and($exception->status)
            ->toBe(502)
            ->and($stored->status)
            ->toBe(ToolStatus::Failed)
            ->and($stored->failed_operation)
            ->toBe(ToolOperation::Update)
            ->and($stored->error_code)
            ->toBe('tool.version_probe_failed')
            ->and($manager->calls)
            ->toBe(['installedVersion', 'update', 'installedVersion'])
            ->and($lock->runs)
            ->toBe(1);
    })->with([
        'missing after version' => [null],
        'post probe exception' => [new ToolManagerException('installed-version', 'Post probe failed.')],
    ]);

    it('retains the raw post-update version for constrained verification failures', function (
        string $after,
        string $errorCode,
    ): void {
        [$action, $manager, $lock, $tool] = update_action_fixture(versionConstraint: '^2.4');
        $manager->installedVersions = ['2.4.0', $after];
        $manager->candidateVersions = ['2.4.1'];

        $exception = update_action_exception(fn (): mixed => $action->execute($tool));
        $stored = $tool->refresh();

        expect($exception->errorCode)
            ->toBe($errorCode)
            ->and($exception->status)
            ->toBe(409)
            ->and($stored->status)
            ->toBe(ToolStatus::Failed)
            ->and($stored->failed_operation)
            ->toBe(ToolOperation::Update)
            ->and($stored->installed_version)
            ->toBe($after)
            ->and($stored->error_code)
            ->toBe($errorCode)
            ->and($manager->calls)
            ->toBe(['installedVersion', 'candidateVersion', 'update', 'installedVersion'])
            ->and($lock->runs)
            ->toBe(1);
    })->with([
        'unparseable after version' => ['release-2.4', 'tool.installed_version_unparseable'],
        'outside after constraint' => ['3.0.0', 'tool.installed_version_constraint_violated'],
    ]);

    it('verifies a constrained update and stores the raw after version', function (): void {
        [$action, $manager, $lock, $tool] = update_action_fixture(versionConstraint: '^2.4');
        $manager->installedVersions = ['2.4.0', '2.4.1'];
        $manager->candidateVersions = ['2.4.1'];

        $result = $action->execute($tool);
        $stored = $tool->refresh();

        expect($result->outcome)
            ->toBe(ToolOutcome::Applied)
            ->and($stored->installed_version)
            ->toBe('2.4.1')
            ->and($stored->status)
            ->toBe(ToolStatus::Installed)
            ->and($manager->calls)
            ->toBe(['installedVersion', 'candidateVersion', 'update', 'installedVersion'])
            ->and($lock->runs)
            ->toBe(1);
    });

    it('passes the exact persisted intent through the operation lock once', function (): void {
        [$action, $manager, $lock, $tool] = update_action_fixture(versionConstraint: '^2.4');
        $manager->installedVersions = ['2.4.0', '2.4.1'];
        $manager->candidateVersions = ['2.4.1'];

        $action->execute($tool);

        expect($lock->arguments)
            ->toBe([[
                'nodeId' => $tool->node_id,
                'manager' => ToolManagerName::Apt,
                'package' => 'jq',
                'operation' => ToolOperation::Update,
                'versionConstraint' => '^2.4',
            ]])
            ->and($lock->runs)
            ->toBe(1);
    });
});

/** @return array{UpdateToolAction, FakeToolManager, ImmediateToolOperationLock, Tool} */
function update_action_fixture(
    ToolStatus $status = ToolStatus::Installed,
    ?string $versionConstraint = null,
    ?ToolOperation $failedOperation = null,
    ?string $errorCode = null,
): array {
    $node = update_action_node();
    $record = update_action_manager($node);
    $tool = update_action_tool(
        $node,
        $record,
        status: $status,
        versionConstraint: $versionConstraint,
        failedOperation: $failedOperation,
        errorCode: $errorCode,
    );

    return [...update_action_fixture_for_node($node), $tool];
}

/** @return array{UpdateToolAction, FakeToolManager, ImmediateToolOperationLock} */
function update_action_fixture_for_node(Node $node, ToolManagerName $managerName = ToolManagerName::Apt): array
{
    $manager = new FakeToolManager($managerName);
    $lock = new ImmediateToolOperationLock;

    return [
        new UpdateToolAction(
            managers: new ToolManagerRegistry([$manager]),
            constraints: new VersionConstraint,
            lock: $lock,
        ),
        $manager,
        $lock,
    ];
}

function update_action_node(
    LifecycleStatus $status = LifecycleStatus::Active,
    ?RoleName $role = null,
    LifecycleStatus $roleStatus = LifecycleStatus::Active,
): Node {
    $node = Node::query()->create([
        'name' => fake()->unique()->slug(2),
        'status' => $status,
        'platform' => 'linux',
        'public_ssh_host' => fake()->unique()->ipv4(),
    ]);

    if ($role !== null) {
        $node->roles()->create([
            'role' => $role,
            'status' => $roleStatus,
        ]);
    }

    return $node;
}

function update_action_manager(
    Node $node,
    ToolManagerName $name = ToolManagerName::Apt,
    LifecycleStatus $status = LifecycleStatus::Active,
): ToolManagerRecord {
    return $node->toolManagers()->create([
        'name' => $name,
        'status' => $status,
    ]);
}

/** @mago-expect lint:excessive-parameter-list The fixture exposes every persisted retry field used by the matrix. */
function update_action_tool(
    Node $node,
    ToolManagerRecord $record,
    ToolStatus $status = ToolStatus::Installed,
    ?string $versionConstraint = null,
    ?ToolOperation $failedOperation = null,
    ?string $errorCode = null,
    string $package = 'jq',
): Tool {
    return $node->tools()->create([
        'tool_manager_id' => $record->id,
        'package' => $package,
        'version_constraint' => $versionConstraint,
        'status' => $status,
        'installed_version' => '2.4.0',
        'failed_operation' => $failedOperation,
        'error_code' => $errorCode,
    ]);
}

function update_action_exception(Closure $callback): ToolOperationException
{
    try {
        $callback();
    } catch (ToolOperationException $exception) {
        expect($exception->step)->toBe(ToolOperation::Update->value);

        return $exception;
    }

    throw new RuntimeException('Expected a ToolOperationException.');
}
