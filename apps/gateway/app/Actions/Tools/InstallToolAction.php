<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Data\Tools\InstallToolData;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolActionResult;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolOperationException;
use App\Domain\Tools\ToolOperationLock;
use App\Domain\Tools\ToolOutcome;
use App\Domain\Tools\ToolStatus;
use App\Domain\Tools\VersionConstraint;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Throwable;

/**
 * @mago-expect lint:cyclomatic-complexity The install state machine keeps every ordered validation and retry gate explicit.
 * @mago-expect lint:kan-defect The score reflects the required fail-closed lifecycle branches.
 * @mago-expect lint:too-many-methods Narrow helpers keep the synchronous install boundary explicit.
 */
final readonly class InstallToolAction
{
    use MarksToolFailures;

    public function __construct(
        private ToolManagerRegistry $managers,
        private VersionConstraint $constraints,
        private ToolOperationLock $lock,
    ) {}

    public function execute(InstallToolData $data): ToolActionResult
    {
        $managerName = ToolManagerName::tryFrom($data->manager);
        $manager = $managerName === null ? null : $this->managers->find($data->manager);

        if ($managerName === null || $manager === null || $manager->name() !== $managerName) {
            throw $this->failure(
                errorCode: 'tool.manager_unsupported',
                outcome: ToolOutcome::ManagerFailed,
                status: 422,
                data: $data,
                message: 'The requested tool manager is not supported.',
            );
        }

        if (! $manager->validatePackage($data->package)) {
            throw $this->failure(
                errorCode: 'tool.package_invalid',
                outcome: ToolOutcome::ManagerFailed,
                status: 422,
                data: $data,
                manager: $managerName,
                message: 'The package coordinate is invalid.',
            );
        }

        if (! $this->constraints->isValid($data->versionConstraint)) {
            throw $this->failure(
                errorCode: 'tool.constraint_invalid',
                outcome: ToolOutcome::ConstraintInvalid,
                status: 422,
                data: $data,
                manager: $managerName,
                message: 'The version constraint is invalid.',
            );
        }

        $node = Node::query()->find($data->nodeId);

        if (! $node instanceof Node || $node->status !== LifecycleStatus::Active) {
            throw $this->failure(
                errorCode: 'tool.node_inactive',
                outcome: ToolOutcome::ManagerFailed,
                status: 409,
                data: $data,
                manager: $managerName,
                message: 'Tools can be installed only on an active node.',
            );
        }

        if ($this->requiresAppRole($managerName) && ! $this->hasActiveAppRole($node)) {
            throw $this->failure(
                errorCode: 'tool.app_role_required',
                outcome: ToolOutcome::ManagerFailed,
                status: 409,
                data: $data,
                manager: $managerName,
                message: 'The tool manager requires an active app role.',
            );
        }

        if (! $manager->supportsNode($node)) {
            throw $this->failure(
                errorCode: 'tool.manager_unavailable',
                outcome: ToolOutcome::ManagerFailed,
                status: 409,
                data: $data,
                manager: $managerName,
                message: 'The tool manager is not available on this node.',
            );
        }

        $record = $this->managerRecord($node, $managerName);

        if ($record === null || $record->status !== LifecycleStatus::Active) {
            throw $this->failure(
                errorCode: 'tool.manager_unavailable',
                outcome: ToolOutcome::ManagerFailed,
                status: 409,
                data: $data,
                manager: $managerName,
                message: 'The tool manager is not available on this node.',
            );
        }

        return $this->lock->run(
            nodeId: $node->id,
            manager: $managerName,
            package: $data->package,
            operation: ToolOperation::Install,
            versionConstraint: $data->versionConstraint,
            callback: fn (): ToolActionResult => $this->underLock(
                node: $node,
                manager: $manager,
                managerName: $managerName,
                data: $data,
            ),
        );
    }

    /** @mago-expect lint:halstead The method preserves the required install transition order under both locks. */
    private function underLock(
        Node $node,
        ToolManager $manager,
        ToolManagerName $managerName,
        InstallToolData $data,
    ): ToolActionResult {
        $node->refresh();

        if ($node->status !== LifecycleStatus::Active) {
            throw $this->failure(
                errorCode: 'tool.node_inactive',
                outcome: ToolOutcome::ManagerFailed,
                status: 409,
                data: $data,
                manager: $managerName,
                message: 'Tools can be installed only on an active node.',
            );
        }

        if ($this->requiresAppRole($managerName) && ! $this->hasActiveAppRole($node)) {
            throw $this->failure(
                errorCode: 'tool.app_role_required',
                outcome: ToolOutcome::ManagerFailed,
                status: 409,
                data: $data,
                manager: $managerName,
                message: 'The tool manager requires an active app role.',
            );
        }

        if (! $manager->supportsNode($node)) {
            throw $this->failure(
                errorCode: 'tool.manager_unavailable',
                outcome: ToolOutcome::ManagerFailed,
                status: 409,
                data: $data,
                manager: $managerName,
                message: 'The tool manager is not available on this node.',
            );
        }

        $record = $this->lockedManagerRecord($node, $managerName);

        if ($record === null || $record->status !== LifecycleStatus::Active) {
            throw $this->failure(
                errorCode: 'tool.manager_unavailable',
                outcome: ToolOutcome::ManagerFailed,
                status: 409,
                data: $data,
                manager: $managerName,
                message: 'The tool manager is not available on this node.',
            );
        }

        $tool = Tool::query()
            ->where('node_id', $node->id)
            ->where('tool_manager_id', $record->id)
            ->where('package', $data->package)
            ->lockForUpdate()
            ->first();

        $this->guardConstraint($tool, $data, $managerName);
        $this->guardState($tool, $data, $managerName);

        $created = false;

        if ($tool === null) {
            $tool = Tool::query()->create([
                'node_id' => $node->id,
                'tool_manager_id' => $record->id,
                'package' => $data->package,
                'version_constraint' => $data->versionConstraint,
                'protected' => false,
                'status' => ToolStatus::Installing,
            ]);
            $created = true;
        }

        try {
            $before = $manager->installedVersion($node, $data->package);
        } catch (ToolManagerException $exception) {
            $failure = $this->managerFailure(
                errorCode: 'tool.version_probe_failed',
                data: $data,
                manager: $managerName,
                previous: $exception,
            );
            $this->markToolFailure($tool, ToolOperation::Install, $failure);
            throw $failure;
        } catch (Throwable) {
            $failure = $this->managerFailure(
                errorCode: 'tool.version_probe_failed',
                data: $data,
                manager: $managerName,
            );
            $this->markToolFailure($tool, ToolOperation::Install, $failure);
            throw $failure;
        }

        if ($before !== null && ! $this->isSafeRawVersion($before)) {
            if ($created) {
                $tool->delete();

                throw $this->alreadyInstalled($data, $managerName);
            }

            $failure = $this->managerFailure(
                errorCode: 'tool.version_probe_failed',
                data: $data,
                manager: $managerName,
            );
            $this->markToolFailure($tool, ToolOperation::Install, $failure);
            throw $failure;
        }

        if ($created && $before !== null) {
            $tool->delete();

            throw $this->alreadyInstalled($data, $managerName);
        }

        if (! $created && $before !== null) {
            $this->verifyExistingVersion($tool, $before, $manager, $data, $managerName);

            $tool->update([
                'status' => ToolStatus::Installed,
                'installed_version' => $before,
                'failed_operation' => null,
                'error_code' => null,
            ]);

            return new ToolActionResult($tool->refresh(), ToolOutcome::Unchanged, false);
        }

        if (! $created) {
            $tool->update([
                'status' => ToolStatus::Installing,
                'failed_operation' => null,
                'error_code' => null,
            ]);
        }

        try {
            $this->verifyCandidate($manager, $node, $data, $managerName);

            try {
                $manager->install($node, $data->package);
            } catch (ToolManagerException $exception) {
                throw $this->managerFailure(
                    errorCode: 'tool.install_failed',
                    data: $data,
                    manager: $managerName,
                    previous: $exception,
                );
            } catch (Throwable) {
                throw $this->managerFailure(
                    errorCode: 'tool.install_failed',
                    data: $data,
                    manager: $managerName,
                );
            }

            try {
                $after = $manager->installedVersion($node, $data->package);
            } catch (ToolManagerException $exception) {
                throw $this->managerFailure(
                    errorCode: 'tool.version_probe_failed',
                    data: $data,
                    manager: $managerName,
                    previous: $exception,
                );
            } catch (Throwable) {
                throw $this->managerFailure(
                    errorCode: 'tool.version_probe_failed',
                    data: $data,
                    manager: $managerName,
                );
            }

            if ($after === null || ! $this->isSafeRawVersion($after)) {
                throw $this->managerFailure(
                    errorCode: 'tool.version_probe_failed',
                    data: $data,
                    manager: $managerName,
                );
            }

            if ($data->versionConstraint !== null) {
                $normalized = $manager->normalizeVersion($after);

                if ($normalized === null) {
                    throw $this->failure(
                        errorCode: 'tool.installed_version_unparseable',
                        outcome: ToolOutcome::ManagerFailed,
                        status: 409,
                        data: $data,
                        manager: $managerName,
                        message: 'The installed version cannot be verified.',
                    );
                }

                if (! $this->constraints->allows($normalized, $data->versionConstraint)) {
                    throw $this->failure(
                        errorCode: 'tool.installed_version_constraint_violated',
                        outcome: ToolOutcome::ManagerFailed,
                        status: 409,
                        data: $data,
                        manager: $managerName,
                        message: 'The installed version does not satisfy the constraint.',
                    );
                }
            }

            $tool->update([
                'status' => ToolStatus::Installed,
                'installed_version' => $after,
                'failed_operation' => null,
                'error_code' => null,
            ]);

            return new ToolActionResult($tool->refresh(), ToolOutcome::Applied, $created);
        } catch (ToolOperationException $exception) {
            $this->markToolFailure(
                tool: $tool,
                operation: ToolOperation::Install,
                failure: $exception,
                installedVersion: $this->knownInstalledVersion($tool, $after ?? null),
            );
            throw $exception;
        } catch (Throwable) {
            $failure = $this->managerFailure(
                errorCode: 'tool.install_failed',
                data: $data,
                manager: $managerName,
            );
            $this->markToolFailure($tool, ToolOperation::Install, $failure);
            throw $failure;
        }
    }

    private function verifyCandidate(
        ToolManager $manager,
        Node $node,
        InstallToolData $data,
        ToolManagerName $managerName,
    ): void {
        if ($data->versionConstraint === null) {
            return;
        }

        try {
            $candidate = $manager->candidateVersion($node, $data->package, ToolOperation::Install);
        } catch (ToolManagerException $exception) {
            throw $this->managerFailure(
                errorCode: 'tool.candidate_version_probe_failed',
                data: $data,
                manager: $managerName,
                outcome: ToolOutcome::CandidateVersionUnavailable,
                previous: $exception,
            );
        } catch (Throwable) {
            throw $this->managerFailure(
                errorCode: 'tool.candidate_version_probe_failed',
                data: $data,
                manager: $managerName,
                outcome: ToolOutcome::CandidateVersionUnavailable,
            );
        }

        if ($candidate === null) {
            throw $this->failure(
                errorCode: 'tool.candidate_version_unavailable',
                outcome: ToolOutcome::CandidateVersionUnavailable,
                status: 422,
                data: $data,
                manager: $managerName,
                message: 'The manager did not provide a candidate version.',
            );
        }

        $normalized = $manager->normalizeVersion($candidate);

        if ($normalized === null) {
            throw $this->failure(
                errorCode: 'tool.candidate_version_unparseable',
                outcome: ToolOutcome::CandidateVersionUnparseable,
                status: 422,
                data: $data,
                manager: $managerName,
                message: 'The manager returned an unparseable candidate version.',
            );
        }

        if (! $this->constraints->allows($normalized, $data->versionConstraint)) {
            throw $this->failure(
                errorCode: 'tool.version_constraint_blocked',
                outcome: ToolOutcome::BlockedByConstraint,
                status: 422,
                data: $data,
                manager: $managerName,
                message: 'The candidate version does not satisfy the constraint.',
            );
        }
    }

    private function verifyExistingVersion(
        Tool $tool,
        string $version,
        ToolManager $manager,
        InstallToolData $data,
        ToolManagerName $managerName,
    ): void {
        if ($data->versionConstraint === null) {
            return;
        }

        $normalized = $manager->normalizeVersion($version);

        if ($normalized === null) {
            $failure = $this->failure(
                errorCode: 'tool.installed_version_unparseable',
                outcome: ToolOutcome::ManagerFailed,
                status: 409,
                data: $data,
                manager: $managerName,
                message: 'The installed version cannot be verified.',
            );
            $this->markToolFailure($tool, ToolOperation::Install, $failure, $version);
            throw $failure;
        }

        if (! $this->constraints->allows($normalized, $data->versionConstraint)) {
            $failure = $this->failure(
                errorCode: 'tool.installed_version_constraint_violated',
                outcome: ToolOutcome::ManagerFailed,
                status: 409,
                data: $data,
                manager: $managerName,
                message: 'The installed version does not satisfy the constraint.',
            );
            $this->markToolFailure($tool, ToolOperation::Install, $failure, $version);
            throw $failure;
        }
    }

    private function guardConstraint(?Tool $tool, InstallToolData $data, ToolManagerName $manager): void
    {
        if ($tool === null || $tool->version_constraint === $data->versionConstraint) {
            return;
        }

        throw $this->failure(
            errorCode: 'tool.constraint_conflict',
            outcome: ToolOutcome::ManagerFailed,
            status: 409,
            data: $data,
            manager: $manager,
            message: 'The stored tool constraint cannot be changed.',
        );
    }

    private function guardState(?Tool $tool, InstallToolData $data, ToolManagerName $manager): void
    {
        if ($tool === null) {
            return;
        }

        if ($tool->protected) {
            throw $this->stateFailure($data, $manager);
        }

        if ($tool->status === ToolStatus::Installed) {
            return;
        }

        if (
            $tool->status === ToolStatus::Failed
            && $tool->failed_operation === ToolOperation::Install
        ) {
            return;
        }

        throw $this->stateFailure($data, $manager);
    }

    private function stateFailure(InstallToolData $data, ToolManagerName $manager): ToolOperationException
    {
        return $this->failure(
            errorCode: 'tool.state_invalid',
            outcome: ToolOutcome::ManagerFailed,
            status: 409,
            data: $data,
            manager: $manager,
            message: 'The tool is not in a retryable install state.',
        );
    }

    private function managerRecord(Node $node, ToolManagerName $manager): ?ToolManagerRecord
    {
        return $node->toolManagers()->where('name', $manager)->first();
    }

    private function lockedManagerRecord(Node $node, ToolManagerName $manager): ?ToolManagerRecord
    {
        return $node
            ->toolManagers()
            ->where('name', $manager)
            ->lockForUpdate()
            ->first();
    }

    private function requiresAppRole(ToolManagerName $manager): bool
    {
        return in_array($manager, [ToolManagerName::Vp, ToolManagerName::Composer], strict: true);
    }

    private function hasActiveAppRole(Node $node): bool
    {
        $node->loadMissing('roles');

        return $node->roles->contains(
            static fn (NodeRole $role): bool => (
                in_array($role->role, [RoleName::AppDev, RoleName::AppProd], strict: true)
                && $role->status === LifecycleStatus::Active
            ),
        );
    }

    private function isSafeRawVersion(?string $version): bool
    {
        return (
            $version !== null
            && $version !== ''
            && strlen($version) <= 255
            && preg_match('/[\x00-\x1F\x7F]/', $version) !== 1
        );
    }

    private function knownInstalledVersion(Tool $tool, ?string $observed): ?string
    {
        if ($this->isSafeRawVersion($observed)) {
            return $observed;
        }

        return $this->isSafeRawVersion($tool->installed_version)
            ? $tool->installed_version
            : null;
    }

    private function alreadyInstalled(InstallToolData $data, ToolManagerName $manager): ToolOperationException
    {
        return $this->failure(
            errorCode: 'tool.already_installed_unmanaged',
            outcome: ToolOutcome::ManagerFailed,
            status: 409,
            data: $data,
            manager: $manager,
            message: 'The package is installed outside Orbit management.',
        );
    }

    private function managerFailure(
        string $errorCode,
        InstallToolData $data,
        ToolManagerName $manager,
        ToolOutcome $outcome = ToolOutcome::ManagerFailed,
        ?ToolManagerException $previous = null,
    ): ToolOperationException {
        return $this->failure(
            errorCode: $errorCode,
            outcome: $outcome,
            status: 502,
            data: $data,
            manager: $manager,
            message: 'The tool manager operation failed.',
            previous: $previous,
        );
    }

    /** @mago-expect lint:excessive-parameter-list The stable operation envelope requires each public field. */
    private function failure(
        string $errorCode,
        ToolOutcome $outcome,
        int $status,
        InstallToolData $data,
        string $message,
        ?ToolManagerName $manager = null,
        ?ToolManagerException $previous = null,
    ): ToolOperationException {
        return new ToolOperationException(
            step: ToolOperation::Install->value,
            errorCode: $errorCode,
            outcome: $outcome,
            status: $status,
            nodeId: $data->nodeId,
            manager: $manager === null ? $data->manager : $manager->value,
            package: $data->package,
            versionConstraint: $data->versionConstraint,
            message: $message,
            previous: $previous,
        );
    }
}
