<?php

declare(strict_types=1);

use App\Actions\Tools\RemoveToolAction;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolOperationException;
use App\Domain\Tools\ToolOutcome;
use App\Domain\Tools\ToolRemovalPlan;
use App\Domain\Tools\ToolStatus;
use App\Models\Node;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Tests\Support\FakeToolManager;
use Tests\Support\ImmediateToolOperationLock;

/** @mago-expect lint:halstead The removal matrix keeps every required plan, retry, and verification contract visible. */
describe(RemoveToolAction::class, function (): void {
    it('rejects protected intent before the lock or manager calls', function (): void {
        [$tool] = removal_tool_fixture(protected: true);
        [$action, $manager, $lock] = removal_tool_action();

        $exception = removal_tool_exception(fn () => $action->execute($tool));

        expect($exception->errorCode)
            ->toBe('tool.protected')
            ->and($exception->status)
            ->toBe(409)
            ->and($manager->calls)
            ->toBeEmpty()
            ->and($lock->runs)
            ->toBe(0)
            ->and(Tool::query()->find($tool->id))
            ->not->toBeNull();
    });

    it('rejects manager and node ownership mismatches', function (): void {
        $node = removal_tool_node('removal-owner');
        $other = removal_tool_node('removal-other');
        $record = removal_tool_manager($other);
        $tool = removal_tool_record($node, $record);
        [$action, $manager, $lock] = removal_tool_action();

        $exception = removal_tool_exception(fn () => $action->execute($tool));

        expect($exception->errorCode)
            ->toBe('tool.state_invalid')
            ->and($manager->calls)
            ->toBeEmpty()
            ->and($lock->runs)
            ->toBe(0);
    });

    it('rejects inactive nodes and managers before the lock', function (
        LifecycleStatus $nodeStatus,
        LifecycleStatus $managerStatus,
        string $errorCode,
    ): void {
        [$tool] = removal_tool_fixture(nodeStatus: $nodeStatus, managerStatus: $managerStatus);
        [$action, $manager, $lock] = removal_tool_action();

        $exception = removal_tool_exception(fn () => $action->execute($tool));

        expect($exception->errorCode)
            ->toBe($errorCode)
            ->and($manager->calls)
            ->toBeEmpty()
            ->and($lock->runs)
            ->toBe(0);
    })->with([
        'inactive node' => [LifecycleStatus::Failed, LifecycleStatus::Active, 'tool.node_inactive'],
        'inactive manager' => [LifecycleStatus::Active, LifecycleStatus::Failed, 'tool.manager_unavailable'],
    ]);

    it('rejects unsupported manager state before the lock', function (): void {
        [$tool] = removal_tool_fixture();
        [$action, $manager, $lock] = removal_tool_action();
        $manager->supports = false;

        $exception = removal_tool_exception(fn () => $action->execute($tool));

        expect($exception->errorCode)
            ->toBe('tool.manager_unavailable')
            ->and($manager->calls)
            ->toBeEmpty()
            ->and($lock->runs)
            ->toBe(0);
    });

    it('rejects in-progress transitions before manager I/O', function (ToolStatus $status): void {
        [$tool] = removal_tool_fixture(status: $status);
        [$action, $manager, $lock] = removal_tool_action();

        $exception = removal_tool_exception(fn () => $action->execute($tool));

        expect($exception->errorCode)
            ->toBe('tool.state_invalid')
            ->and($manager->calls)
            ->toBeEmpty()
            ->and($lock->runs)
            ->toBe(0);
    })->with([
        ToolStatus::Installing,
        ToolStatus::Updating,
        ToolStatus::Removing,
    ]);

    it('allows removal retry from every failed operation when the package is absent', function (ToolOperation $failedOperation): void {
        [$tool] = removal_tool_fixture(status: ToolStatus::Failed, failedOperation: $failedOperation);
        [$action, $manager, $lock] = removal_tool_action();
        $manager->installedVersions = [null];

        $result = $action->execute($tool);

        expect($result->outcome)
            ->toBe(ToolOutcome::Applied)
            ->and($result->tool->id)
            ->toBe($tool->id)
            ->and($manager->calls)
            ->toBe(['installedVersion'])
            ->and($lock->runs)
            ->toBe(1)
            ->and(Tool::query()->find($tool->id))
            ->toBeNull();
    })->with(ToolOperation::cases());

    it('retains a bounded failure when the initial installed probe fails', function (): void {
        [$tool] = removal_tool_fixture();
        [$action, $manager] = removal_tool_action();
        $manager->installedVersions = [new ToolManagerException('installed', 'Probe failed.')];

        $exception = removal_tool_exception(fn () => $action->execute($tool));
        $failed = $tool->refresh();

        expect($exception->errorCode)
            ->toBe('tool.version_probe_failed')
            ->and($exception->status)
            ->toBe(502)
            ->and($failed->status)
            ->toBe(ToolStatus::Failed)
            ->and($failed->failed_operation)
            ->toBe(ToolOperation::Remove)
            ->and($failed->error_code)
            ->toBe('tool.version_probe_failed');
    });

    it('deletes only absent package intent without planning or manager removal', function (): void {
        [$tool, $record] = removal_tool_fixture();
        $sibling = removal_tool_record($tool->node, $record, package: 'curl');
        [$action, $manager] = removal_tool_action();
        $manager->installedVersions = [null];

        $result = $action->execute($tool);

        expect($result->outcome)
            ->toBe(ToolOutcome::Applied)
            ->and($result->tool->id)
            ->toBe($tool->id)
            ->and($manager->calls)
            ->toBe(['installedVersion'])
            ->and(Tool::query()->find($tool->id))
            ->toBeNull()
            ->and(Tool::query()->find($sibling->id))
            ->not->toBeNull()->and(ToolManagerRecord::query()->find($record->id))
            ->not->toBeNull();
    });

    it('rejects unsafe exact-removal plans and retains intent', function (): void {
        [$tool] = removal_tool_fixture();
        [$action, $manager] = removal_tool_action();
        $manager->installedVersions = ['2.4.1'];
        $manager->removalPlan = new ToolRemovalPlan(['jq', 'curl']);

        $exception = removal_tool_exception(fn () => $action->execute($tool));
        $failed = $tool->refresh();

        expect($exception->errorCode)
            ->toBe('tool.removal_plan_unsafe')
            ->and($exception->status)
            ->toBe(409)
            ->and($failed->status)
            ->toBe(ToolStatus::Failed)
            ->and($failed->failed_operation)
            ->toBe(ToolOperation::Remove)
            ->and($failed->error_code)
            ->toBe('tool.removal_plan_unsafe')
            ->and($manager->calls)
            ->toBe(['installedVersion', 'planRemoval']);
    });

    it('retains removal-plan manager failures', function (): void {
        [$tool] = removal_tool_fixture();
        [$action, $manager] = removal_tool_action();
        $manager->installedVersions = ['2.4.1'];
        $manager->failures['planRemoval'] = [new ToolManagerException('plan', 'Plan failed.')];

        $exception = removal_tool_exception(fn () => $action->execute($tool));

        expect($exception->errorCode)
            ->toBe('tool.remove_failed')
            ->and($tool->refresh()->failed_operation)
            ->toBe(ToolOperation::Remove)
            ->and($tool->error_code)
            ->toBe('tool.remove_failed')
            ->and($manager->calls)
            ->toBe(['installedVersion', 'planRemoval']);
    });

    it('removes an exact package, verifies absence, and deletes only its intent', function (): void {
        [$tool, $record] = removal_tool_fixture();
        $sibling = removal_tool_record($tool->node, $record, package: 'curl');
        [$action, $manager] = removal_tool_action();
        $manager->installedVersions = ['2.4.1', null];
        $manager->removalPlan = new ToolRemovalPlan(['jq']);

        $result = $action->execute($tool);

        expect($result->outcome)
            ->toBe(ToolOutcome::Applied)
            ->and($manager->calls)
            ->toBe(['installedVersion', 'planRemoval', 'remove', 'installedVersion'])
            ->and(Tool::query()->find($tool->id))
            ->toBeNull()
            ->and(Tool::query()->find($sibling->id))
            ->not->toBeNull()->and(ToolManagerRecord::query()->find($record->id))
            ->not->toBeNull();
    });

    it('retains manager removal failures', function (): void {
        [$tool] = removal_tool_fixture();
        [$action, $manager] = removal_tool_action();
        $manager->installedVersions = ['2.4.1'];
        $manager->removalPlan = new ToolRemovalPlan(['jq']);
        $manager->failures['remove'] = [new ToolManagerException('remove', 'Remove failed.')];

        $exception = removal_tool_exception(fn () => $action->execute($tool));
        $failed = $tool->refresh();

        expect($exception->errorCode)
            ->toBe('tool.remove_failed')
            ->and($failed->status)
            ->toBe(ToolStatus::Failed)
            ->and($failed->failed_operation)
            ->toBe(ToolOperation::Remove)
            ->and($failed->error_code)
            ->toBe('tool.remove_failed');
    });

    it('does not persist an unbounded raw version while marking removal failure', function (): void {
        [$tool] = removal_tool_fixture();
        [$action, $manager] = removal_tool_action();
        $manager->installedVersions = [str_repeat('1', times: 256)];
        $manager->removalPlan = new ToolRemovalPlan(['jq']);
        $manager->failures['remove'] = [new ToolManagerException('remove', 'Remove failed.')];

        removal_tool_exception(fn () => $action->execute($tool));
        $failed = $tool->refresh();

        expect($failed->status)
            ->toBe(ToolStatus::Failed)
            ->and($failed->installed_version)
            ->toBe('2.4.1')
            ->and($failed->failed_operation)
            ->toBe(ToolOperation::Remove);
    });

    it('retains post-removal verification failures', function (
        string|ToolManagerException $after,
        string $errorCode,
    ): void {
        [$tool] = removal_tool_fixture();
        [$action, $manager] = removal_tool_action();
        $manager->installedVersions = ['2.4.1', $after];
        $manager->removalPlan = new ToolRemovalPlan(['jq']);

        $exception = removal_tool_exception(fn () => $action->execute($tool));
        $failed = $tool->refresh();

        expect($exception->errorCode)
            ->toBe($errorCode)
            ->and($failed->status)
            ->toBe(ToolStatus::Failed)
            ->and($failed->failed_operation)
            ->toBe(ToolOperation::Remove)
            ->and($failed->error_code)
            ->toBe($errorCode);
    })->with([
        'probe exception' => [new ToolManagerException('installed', 'Probe failed.'), 'tool.version_probe_failed'],
        'package still installed' => ['2.4.1', 'tool.remove_failed'],
    ]);

    it('passes the exact removal intent through the operation lock', function (): void {
        [$tool] = removal_tool_fixture(versionConstraint: '^2.4');
        [$action, $manager, $lock] = removal_tool_action();
        $manager->installedVersions = [null];

        $action->execute($tool);

        expect($lock->arguments)->toBe([[
            'nodeId' => $tool->node_id,
            'manager' => ToolManagerName::Apt,
            'package' => 'jq',
            'operation' => ToolOperation::Remove,
            'versionConstraint' => '^2.4',
        ]]);
    });
});

/** @return array{RemoveToolAction, FakeToolManager, ImmediateToolOperationLock} */
function removal_tool_action(): array
{
    $manager = new FakeToolManager;
    $lock = new ImmediateToolOperationLock;

    return [
        new RemoveToolAction(
            managers: new ToolManagerRegistry([$manager]),
            lock: $lock,
        ),
        $manager,
        $lock,
    ];
}

/** @return array{Tool, ToolManagerRecord} */
/** @mago-expect lint:excessive-parameter-list The fixture exposes every persisted removal state used by the matrix. */
function removal_tool_fixture(
    LifecycleStatus $nodeStatus = LifecycleStatus::Active,
    LifecycleStatus $managerStatus = LifecycleStatus::Active,
    ToolStatus $status = ToolStatus::Installed,
    ?ToolOperation $failedOperation = null,
    bool $protected = false,
    ?string $versionConstraint = null,
): array {
    $node = removal_tool_node(fake()->unique()->slug(2), $nodeStatus);
    $manager = removal_tool_manager($node, $managerStatus);

    return [
        removal_tool_record(
            node: $node,
            manager: $manager,
            status: $status,
            failedOperation: $failedOperation,
            protected: $protected,
            versionConstraint: $versionConstraint,
        ),
        $manager,
    ];
}

function removal_tool_node(string $name, LifecycleStatus $status = LifecycleStatus::Active): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => $status,
        'platform' => 'linux',
        'public_ssh_host' => fake()->unique()->ipv4(),
    ]);
}

function removal_tool_manager(Node $node, LifecycleStatus $status = LifecycleStatus::Active): ToolManagerRecord
{
    return $node->toolManagers()->create([
        'name' => ToolManagerName::Apt,
        'status' => $status,
    ]);
}

/** @mago-expect lint:excessive-parameter-list The fixture exposes the complete persisted removal intent. */
function removal_tool_record(
    Node $node,
    ToolManagerRecord $manager,
    string $package = 'jq',
    ToolStatus $status = ToolStatus::Installed,
    ?ToolOperation $failedOperation = null,
    bool $protected = false,
    ?string $versionConstraint = null,
): Tool {
    return $node->tools()->create([
        'tool_manager_id' => $manager->id,
        'package' => $package,
        'version_constraint' => $versionConstraint,
        'protected' => $protected,
        'status' => $status,
        'installed_version' => '2.4.1',
        'failed_operation' => $failedOperation,
        'error_code' => $status === ToolStatus::Failed ? 'tool.previous_failure' : null,
    ]);
}

function removal_tool_exception(Closure $callback): ToolOperationException
{
    try {
        $callback();
    } catch (ToolOperationException $exception) {
        expect($exception->step)->toBe(ToolOperation::Remove->value);

        return $exception;
    }

    throw new RuntimeException('Expected a ToolOperationException.');
}
