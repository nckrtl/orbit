<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Data\Tools\AdoptToolData;
use App\Domain\Nodes\NodeLockLoss;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\SupportsToolAdoption;
use App\Domain\Tools\ToolActionResult;
use App\Domain\Tools\ToolAdoptionFact;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolNodeEligibility;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolOperationException;
use App\Domain\Tools\ToolOperationLock;
use App\Domain\Tools\ToolOutcome;
use App\Domain\Tools\ToolStatus;
use App\Domain\Tools\VersionConstraint;
use App\Models\Node;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Registers one selected installed package.
 * It revalidates the live scope, support, and version under the tool and manager locks.
 * It does not bootstrap, repin, refresh manager metadata, or mutate the package.
 */
final readonly class AdoptToolAction
{
    public function __construct(
        private ToolManagerRegistry $managers,
        private VersionConstraint $constraints,
        private ToolOperationLock $lock,
        private ToolNodeEligibility $eligibility,
    ) {}

    public function execute(AdoptToolData $data): ToolActionResult
    {
        $managerName = ToolManagerName::tryFrom($data->manager);
        $manager = $managerName === null ? null : $this->managers->find($data->manager);

        if (
            $managerName === null
            || ! $manager instanceof SupportsToolAdoption
            || $manager->name() !== $managerName
        ) {
            throw $this->failure(
                errorCode: 'tool.manager_unsupported',
                status: 422,
                data: $data,
                message: 'The requested tool manager is not supported.',
            );
        }

        if (! $manager->validatePackage($data->package)) {
            throw $this->failure(
                errorCode: 'tool.package_invalid',
                status: 422,
                data: $data,
                manager: $managerName,
                message: 'The package coordinate is invalid.',
            );
        }

        if (! $this->constraints->isValid($data->versionConstraint)) {
            throw $this->failure(
                errorCode: 'tool.constraint_invalid',
                status: 422,
                data: $data,
                manager: $managerName,
                outcome: ToolOutcome::ConstraintInvalid,
                message: 'The version constraint is invalid.',
            );
        }

        $node = Node::query()->find($data->nodeId);

        if (! $node instanceof Node || $node->status !== LifecycleStatus::Active) {
            throw $this->failure(
                errorCode: 'tool.node_inactive',
                status: 409,
                data: $data,
                manager: $managerName,
                message: 'Tools can be adopted only on an active node.',
            );
        }

        if (! $this->eligibility->allows($node)) {
            throw $this->failure(
                errorCode: 'tool.node_unmanaged',
                status: 409,
                data: $data,
                manager: $managerName,
                message: 'Tools can be adopted only on a Gateway-managed node.',
            );
        }

        if (! $manager->supportsNode($node)) {
            throw $this->failure(
                errorCode: 'tool.manager_unsupported',
                status: 422,
                data: $data,
                manager: $managerName,
                message: 'The requested tool manager is not supported.',
            );
        }

        return $this->lock->run(
            nodeId: $node->id,
            manager: $managerName,
            package: $data->package,
            operation: ToolOperation::Adopt,
            versionConstraint: $data->versionConstraint,
            callback: fn (): ToolActionResult => $this->underLock($node, $manager, $managerName, $data),
        );
    }

    private function underLock(
        Node $node,
        SupportsToolAdoption&ToolManager $manager,
        ToolManagerName $managerName,
        AdoptToolData $data,
    ): ToolActionResult {
        $node->refresh();

        if ($node->status !== LifecycleStatus::Active) {
            throw $this->failure(
                errorCode: 'tool.node_inactive',
                status: 409,
                data: $data,
                manager: $managerName,
                message: 'Tools can be adopted only on an active node.',
            );
        }

        if (! $this->eligibility->allows($node)) {
            throw $this->failure(
                errorCode: 'tool.node_unmanaged',
                status: 409,
                data: $data,
                manager: $managerName,
                message: 'Tools can be adopted only on a Gateway-managed node.',
            );
        }

        if (! $manager->supportsNode($node)) {
            throw $this->failure(
                errorCode: 'tool.manager_unsupported',
                status: 422,
                data: $data,
                manager: $managerName,
                message: 'The requested tool manager is not supported.',
            );
        }

        $record = $this->lockedManagerRecord($node, $managerName);
        $tool = $record instanceof ToolManagerRecord
            ? $this->lockedTool($node, $record, $data->package)
            : null;

        if ($record instanceof ToolManagerRecord && $record->status !== LifecycleStatus::Active) {
            throw $this->failure(
                errorCode: 'tool.manager_unavailable',
                status: 409,
                data: $data,
                manager: $managerName,
                message: 'The tool manager is not available on this node.',
                toolId: $tool?->id,
            );
        }

        try {
            $fact = $manager->inspectForAdoption($node, $data->package);
        } catch (ToolManagerException $exception) {
            throw $this->fromManager($exception, $data, $managerName, $tool?->id);
        } catch (Throwable $exception) {
            throw $this->failure(
                errorCode: 'tool.version_probe_failed',
                status: 409,
                data: $data,
                manager: $managerName,
                message: 'The installed version cannot be read.',
                previous: NodeLockLoss::keep($exception),
                toolId: $tool?->id,
            );
        }

        if ($tool instanceof Tool && $this->transitional($tool)) {
            throw $this->failure(
                errorCode: 'tool.state_invalid',
                status: 409,
                data: $data,
                manager: $managerName,
                message: 'The tool is not in a state that adoption can repair.',
                toolId: $tool->id,
            );
        }

        if ($tool instanceof Tool && $tool->version_constraint !== $data->versionConstraint) {
            throw $this->failure(
                errorCode: 'tool.constraint_conflict',
                status: 409,
                data: $data,
                manager: $managerName,
                message: 'The stored tool constraint cannot be changed.',
                toolId: $tool->id,
            );
        }

        if ($fact->absent()) {
            throw $this->failure(
                errorCode: 'tool.package_absent',
                status: 409,
                data: $data,
                manager: $managerName,
                message: 'The package is not installed.',
                toolId: $tool?->id,
            );
        }

        if (! $fact->supported()) {
            throw $this->failure(
                errorCode: 'tool.adoption_unsupported',
                status: 409,
                data: $data,
                manager: $managerName,
                message: 'The package cannot be adopted.',
                toolId: $tool?->id,
                adoptionBlock: $fact->adoptionBlock,
            );
        }

        if ($tool instanceof Tool && $tool->status === ToolStatus::Installed) {
            $this->guardConstraintSatisfied($fact->installedVersion, $manager, $data, $managerName, $tool->id);

            return new ToolActionResult($tool, ToolOutcome::Unchanged, false);
        }

        $this->guardConstraintSatisfied($fact->installedVersion, $manager, $data, $managerName, $tool?->id);

        if ($tool instanceof Tool) {
            $tool->update([
                'status' => ToolStatus::Installed,
                'installed_version' => $fact->installedVersion,
                'failed_operation' => null,
                'error_code' => null,
            ]);

            return new ToolActionResult($tool->refresh(), ToolOutcome::Applied, false);
        }

        try {
            $created = DB::transaction(function () use ($node, $managerName, $data, $fact, $record): Tool {
                $managerRecord = $record instanceof ToolManagerRecord
                    ? $record
                    : $this->createManagerRecord($node, $managerName, $data);

                return Tool::query()->create([
                    'node_id' => $node->id,
                    'tool_manager_id' => $managerRecord->id,
                    'package' => $data->package,
                    'version_constraint' => $data->versionConstraint,
                    'status' => ToolStatus::Installed,
                    'installed_version' => $fact->installedVersion,
                    'failed_operation' => null,
                    'error_code' => null,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->existingAfterRace($node, $manager, $managerName, $data, $fact);
        }

        return new ToolActionResult($created->refresh(), ToolOutcome::Applied, true);
    }

    private function existingAfterRace(
        Node $node,
        SupportsToolAdoption&ToolManager $manager,
        ToolManagerName $managerName,
        AdoptToolData $data,
        ToolAdoptionFact $fact,
    ): ToolActionResult {
        $record = $this->lockedManagerRecord($node, $managerName);
        $tool = $record instanceof ToolManagerRecord
            ? $this->lockedTool($node, $record, $data->package)
            : null;

        if (! $tool instanceof Tool) {
            throw $this->failure(
                errorCode: 'tool.operation_locked',
                status: 409,
                data: $data,
                manager: $managerName,
                message: 'A tool mutation for this package is already active.',
            );
        }

        if ($this->transitional($tool)) {
            throw $this->failure(
                errorCode: 'tool.state_invalid',
                status: 409,
                data: $data,
                manager: $managerName,
                message: 'The tool is not in a state that adoption can repair.',
                toolId: $tool->id,
            );
        }

        if ($tool->version_constraint !== $data->versionConstraint) {
            throw $this->failure(
                errorCode: 'tool.constraint_conflict',
                status: 409,
                data: $data,
                manager: $managerName,
                message: 'The stored tool constraint cannot be changed.',
                toolId: $tool->id,
            );
        }

        if ($tool->status === ToolStatus::Installed) {
            $this->guardConstraintSatisfied($fact->installedVersion, $manager, $data, $managerName, $tool->id);

            return new ToolActionResult($tool, ToolOutcome::Unchanged, false);
        }

        $this->guardConstraintSatisfied($fact->installedVersion, $manager, $data, $managerName, $tool->id);

        $tool->update([
            'status' => ToolStatus::Installed,
            'installed_version' => $fact->installedVersion,
            'failed_operation' => null,
            'error_code' => null,
        ]);

        return new ToolActionResult($tool->refresh(), ToolOutcome::Applied, false);
    }

    private function guardConstraintSatisfied(
        ?string $installedVersion,
        ToolManager $manager,
        AdoptToolData $data,
        ToolManagerName $managerName,
        ?int $toolId,
    ): void {
        if ($data->versionConstraint === null || ! is_string($installedVersion)) {
            return;
        }

        $normalized = $manager->normalizeVersion($installedVersion);

        if ($normalized === null) {
            throw $this->failure(
                errorCode: 'tool.installed_version_unparseable',
                status: 409,
                data: $data,
                manager: $managerName,
                message: 'The installed version cannot be verified.',
                toolId: $toolId,
            );
        }

        if (! $this->constraints->allows($normalized, $data->versionConstraint)) {
            throw $this->failure(
                errorCode: 'tool.installed_version_constraint_violated',
                status: 409,
                data: $data,
                manager: $managerName,
                message: 'The installed version does not satisfy the constraint.',
                toolId: $toolId,
            );
        }
    }

    private function transitional(Tool $tool): bool
    {
        return in_array($tool->status, [
            ToolStatus::Installing,
            ToolStatus::Updating,
            ToolStatus::Removing,
        ], true);
    }

    private function lockedManagerRecord(Node $node, ToolManagerName $manager): ?ToolManagerRecord
    {
        $record = $node->toolManagers()->where('name', $manager)->lockForUpdate()->first();

        return $record instanceof ToolManagerRecord ? $record : null;
    }

    private function lockedTool(Node $node, ToolManagerRecord $record, string $package): ?Tool
    {
        $tool = Tool::query()
            ->where('node_id', $node->id)
            ->where('tool_manager_id', $record->id)
            ->where('package', $package)
            ->lockForUpdate()
            ->first();

        return $tool instanceof Tool ? $tool : null;
    }

    private function createManagerRecord(Node $node, ToolManagerName $manager, AdoptToolData $data): ToolManagerRecord
    {
        $existing = $this->lockedManagerRecord($node, $manager);

        if ($existing instanceof ToolManagerRecord) {
            if ($existing->status !== LifecycleStatus::Active) {
                throw $this->failure(
                    errorCode: 'tool.manager_unavailable',
                    status: 409,
                    data: $data,
                    manager: $manager,
                    message: 'The tool manager is not available on this node.',
                );
            }

            return $existing;
        }

        return $node->toolManagers()->create([
            'name' => $manager->value,
            'status' => LifecycleStatus::Active,
        ]);
    }

    private function fromManager(
        ToolManagerException $exception,
        AdoptToolData $data,
        ToolManagerName $manager,
        ?int $toolId,
    ): ToolOperationException {
        $unavailable = in_array($exception->step, [
            'manager-absent',
            'manager-conflict',
            'manager-probe',
            'manager-version',
        ], true);

        return $this->failure(
            errorCode: $unavailable ? 'tool.manager_unavailable' : 'tool.version_probe_failed',
            status: 409,
            data: $data,
            manager: $manager,
            message: $unavailable
                ? 'The tool manager is not available on this node.'
                : 'The installed version cannot be read.',
            previous: $exception,
            toolId: $toolId,
        );
    }

    private function failure(
        string $errorCode,
        int $status,
        AdoptToolData $data,
        string $message,
        ?ToolManagerName $manager = null,
        ToolOutcome $outcome = ToolOutcome::ManagerFailed,
        ?Throwable $previous = null,
        ?int $toolId = null,
        ?string $adoptionBlock = null,
    ): ToolOperationException {
        return new ToolOperationException(
            step: ToolOperation::Adopt->value,
            errorCode: $errorCode,
            outcome: $outcome,
            status: $status,
            nodeId: $data->nodeId,
            manager: $manager === null ? $data->manager : $manager->value,
            package: $data->package,
            versionConstraint: $data->versionConstraint,
            message: $message,
            previous: $previous,
            toolId: $toolId,
            adoptionBlock: $adoptionBlock,
        );
    }
}
