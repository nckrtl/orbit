<?php

declare(strict_types=1);

namespace App\Actions\Tools;

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
 * @mago-expect lint:cyclomatic-complexity The update state machine keeps every ordered constraint and retry gate explicit.
 * @mago-expect lint:kan-defect The score reflects the required fail-closed lifecycle branches.
 * @mago-expect lint:too-many-methods Narrow helpers keep the synchronous update boundary explicit.
 */
final readonly class UpdateToolAction
{
    use MarksToolFailures;

    public function __construct(
        private ToolManagerRegistry $managers,
        private VersionConstraint $constraints,
        private ToolOperationLock $lock,
    ) {}

    public function execute(Tool $tool): ToolActionResult
    {
        $tool->loadMissing(['node', 'manager']);
        $node = $tool->node;
        $record = $tool->manager;

        if ($record->node_id !== $tool->node_id) {
            throw $this->failure(
                tool: $tool,
                errorCode: 'tool.state_invalid',
                outcome: ToolOutcome::ManagerFailed,
                status: 409,
                message: 'Tool ownership or manager state is invalid.',
            );
        }

        $manager = $this->managers->find($record->name->value);

        if ($manager === null || $manager->name() !== $record->name) {
            throw $this->failure(
                tool: $tool,
                errorCode: 'tool.state_invalid',
                outcome: ToolOutcome::ManagerFailed,
                status: 409,
                message: 'Tool ownership or manager state is invalid.',
            );
        }

        $this->guardAvailable($tool, $node, $record, $manager);
        $this->guardState($tool, $manager->name());

        return $this->lock->run(
            nodeId: $tool->node_id,
            manager: $record->name,
            package: $tool->package,
            operation: ToolOperation::Update,
            versionConstraint: $tool->version_constraint,
            callback: fn (): ToolActionResult => $this->underLock($tool, $manager),
        );
    }

    /** @mago-expect lint:halstead The method preserves the required update transition order under both locks. */
    private function underLock(Tool $tool, ToolManager $manager): ToolActionResult
    {
        $current = Tool::query()
            ->with(['node', 'manager'])
            ->find($tool->id);

        if (! $current instanceof Tool) {
            throw $this->failure(
                tool: $tool,
                errorCode: 'tool.state_invalid',
                outcome: ToolOutcome::ManagerFailed,
                status: 409,
                message: 'Tool ownership or manager state is invalid.',
            );
        }

        $node = $current->node;
        $record = $current->manager;

        if ($record->node_id !== $current->node_id || $record->name !== $manager->name()) {
            throw $this->failure(
                tool: $current,
                errorCode: 'tool.state_invalid',
                outcome: ToolOutcome::ManagerFailed,
                status: 409,
                message: 'Tool ownership or manager state is invalid.',
            );
        }

        $this->guardAvailable($current, $node, $record, $manager);
        $this->guardState($current, $manager->name());

        try {
            $before = $manager->installedVersion($node, $current->package);
        } catch (ToolManagerException $exception) {
            $failure = $this->managerFailure(
                tool: $current,
                errorCode: 'tool.version_probe_failed',
                status: 502,
                message: 'The installed tool version could not be determined.',
                previous: $exception,
            );
            $this->markToolFailure($current, ToolOperation::Update, $failure);
            throw $failure;
        } catch (Throwable) {
            $failure = $this->managerFailure(
                tool: $current,
                errorCode: 'tool.version_probe_failed',
                status: 502,
                message: 'The installed tool version could not be determined.',
            );
            $this->markToolFailure($current, ToolOperation::Update, $failure);
            throw $failure;
        }

        if ($before === null) {
            $failure = $this->managerFailure(
                tool: $current,
                errorCode: 'tool.version_probe_failed',
                status: 502,
                message: 'The managed tool is not installed on the node.',
            );
            $this->markToolFailure($current, ToolOperation::Update, $failure);
            throw $failure;
        }

        if (! $this->isSafeRawVersion($before)) {
            $failure = $this->managerFailure(
                tool: $current,
                errorCode: 'tool.version_probe_failed',
                status: 502,
                message: 'The installed tool version could not be determined.',
            );
            $this->markToolFailure($current, ToolOperation::Update, $failure);
            throw $failure;
        }

        $constraint = $current->version_constraint;

        if ($constraint !== null) {
            $this->verifyInstalledVersion($current, $manager, $before, $constraint);
        }

        $current->update([
            'status' => ToolStatus::Updating,
            'installed_version' => $before,
            'failed_operation' => null,
            'error_code' => null,
        ]);

        if ($constraint !== null) {
            try {
                $candidate = $manager->candidateVersion(
                    $node,
                    $current->package,
                    ToolOperation::Update,
                );
            } catch (ToolManagerException $exception) {
                $failure = $this->managerFailure(
                    tool: $current,
                    errorCode: 'tool.candidate_version_probe_failed',
                    status: 502,
                    message: 'The tool candidate version could not be determined.',
                    outcome: ToolOutcome::CandidateVersionUnavailable,
                    previous: $exception,
                );
                $this->markToolFailure($current, ToolOperation::Update, $failure, $before);
                throw $failure;
            } catch (Throwable) {
                $failure = $this->managerFailure(
                    tool: $current,
                    errorCode: 'tool.candidate_version_probe_failed',
                    status: 502,
                    message: 'The tool candidate version could not be determined.',
                    outcome: ToolOutcome::CandidateVersionUnavailable,
                );
                $this->markToolFailure($current, ToolOperation::Update, $failure, $before);
                throw $failure;
            }

            if ($candidate === null) {
                $failure = $this->failure(
                    tool: $current,
                    errorCode: 'tool.candidate_version_unavailable',
                    outcome: ToolOutcome::CandidateVersionUnavailable,
                    status: 422,
                    message: 'The manager did not provide a candidate version.',
                );
                $this->markToolFailure($current, ToolOperation::Update, $failure, $before);
                throw $failure;
            }

            $normalizedCandidate = $manager->normalizeVersion($candidate);

            if ($normalizedCandidate === null) {
                $failure = $this->failure(
                    tool: $current,
                    errorCode: 'tool.candidate_version_unparseable',
                    outcome: ToolOutcome::CandidateVersionUnparseable,
                    status: 422,
                    message: 'The manager returned an unparseable candidate version.',
                );
                $this->markToolFailure($current, ToolOperation::Update, $failure, $before);
                throw $failure;
            }

            if (! $this->constraints->allows($normalizedCandidate, $constraint)) {
                $current->update([
                    'status' => ToolStatus::Installed,
                    'installed_version' => $before,
                    'failed_operation' => null,
                    'error_code' => null,
                ]);

                return new ToolActionResult($current->refresh(), ToolOutcome::BlockedByConstraint, false);
            }
        }

        try {
            $manager->update($node, $current->package);
        } catch (ToolManagerException $exception) {
            $failure = $this->managerFailure(
                tool: $current,
                errorCode: 'tool.update_failed',
                status: 502,
                message: 'The tool update failed.',
                previous: $exception,
            );
            $this->markToolFailure($current, ToolOperation::Update, $failure, $before);
            throw $failure;
        } catch (Throwable) {
            $failure = $this->managerFailure(
                tool: $current,
                errorCode: 'tool.update_failed',
                status: 502,
                message: 'The tool update failed.',
            );
            $this->markToolFailure($current, ToolOperation::Update, $failure, $before);
            throw $failure;
        }

        try {
            $after = $manager->installedVersion($node, $current->package);
        } catch (ToolManagerException $exception) {
            $failure = $this->managerFailure(
                tool: $current,
                errorCode: 'tool.version_probe_failed',
                status: 502,
                message: 'The updated tool version could not be determined.',
                previous: $exception,
            );
            $this->markToolFailure($current, ToolOperation::Update, $failure, $before);
            throw $failure;
        } catch (Throwable) {
            $failure = $this->managerFailure(
                tool: $current,
                errorCode: 'tool.version_probe_failed',
                status: 502,
                message: 'The updated tool version could not be determined.',
            );
            $this->markToolFailure($current, ToolOperation::Update, $failure, $before);
            throw $failure;
        }

        if ($after === null || ! $this->isSafeRawVersion($after)) {
            $failure = $this->managerFailure(
                tool: $current,
                errorCode: 'tool.version_probe_failed',
                status: 502,
                message: 'The updated tool version could not be determined.',
            );
            $this->markToolFailure($current, ToolOperation::Update, $failure, $before);
            throw $failure;
        }

        if ($constraint !== null) {
            $normalizedAfter = $manager->normalizeVersion($after);

            if ($normalizedAfter === null) {
                $failure = $this->managerFailure(
                    tool: $current,
                    errorCode: 'tool.installed_version_unparseable',
                    status: 409,
                    message: 'The updated tool version cannot be verified.',
                );
                $this->markToolFailure($current, ToolOperation::Update, $failure, $after);
                throw $failure;
            }

            if (! $this->constraints->allows($normalizedAfter, $constraint)) {
                $failure = $this->managerFailure(
                    tool: $current,
                    errorCode: 'tool.installed_version_constraint_violated',
                    status: 409,
                    message: 'The updated tool version does not satisfy the constraint.',
                );
                $this->markToolFailure($current, ToolOperation::Update, $failure, $after);
                throw $failure;
            }
        }

        $current->update([
            'status' => ToolStatus::Installed,
            'installed_version' => $after,
            'failed_operation' => null,
            'error_code' => null,
        ]);

        return new ToolActionResult(
            $current->refresh(),
            $before === $after ? ToolOutcome::Unchanged : ToolOutcome::Applied,
            false,
        );
    }

    private function guardAvailable(
        Tool $tool,
        Node $node,
        ToolManagerRecord $record,
        ToolManager $manager,
    ): void {
        if ($node->status !== LifecycleStatus::Active) {
            throw $this->failure(
                tool: $tool,
                errorCode: 'tool.node_inactive',
                outcome: ToolOutcome::ManagerFailed,
                status: 409,
                message: 'Tools can be updated only on an active node.',
            );
        }

        if ($this->requiresAppRole($record->name) && ! $this->hasActiveAppRole($node)) {
            throw $this->failure(
                tool: $tool,
                errorCode: 'tool.app_role_required',
                outcome: ToolOutcome::ManagerFailed,
                status: 409,
                message: 'The tool manager requires an active app role.',
            );
        }

        if (! $manager->supportsNode($node)) {
            throw $this->failure(
                tool: $tool,
                errorCode: 'tool.manager_unavailable',
                outcome: ToolOutcome::ManagerFailed,
                status: 409,
                message: 'The tool manager is not available on this node.',
            );
        }

        if ($record->status !== LifecycleStatus::Active) {
            throw $this->failure(
                tool: $tool,
                errorCode: 'tool.manager_unavailable',
                outcome: ToolOutcome::ManagerFailed,
                status: 409,
                message: 'The tool manager is not available on this node.',
            );
        }
    }

    private function guardState(Tool $tool, ToolManagerName $manager): void
    {
        if (
            $tool->status === ToolStatus::Installed
            || $tool->status === ToolStatus::Failed
            && $tool->failed_operation === ToolOperation::Update
        ) {
            return;
        }

        throw $this->failure(
            tool: $tool,
            errorCode: 'tool.state_invalid',
            outcome: ToolOutcome::ManagerFailed,
            status: 409,
            message: "The tool is not in a retryable update state for manager [{$manager->value}].",
        );
    }

    private function verifyInstalledVersion(
        Tool $tool,
        ToolManager $manager,
        string $rawVersion,
        string $constraint,
    ): void {
        $normalized = $manager->normalizeVersion($rawVersion);

        if ($normalized === null) {
            $failure = $this->managerFailure(
                tool: $tool,
                errorCode: 'tool.installed_version_unparseable',
                status: 409,
                message: 'The installed tool version cannot be verified.',
            );
            $this->markToolFailure($tool, ToolOperation::Update, $failure, $rawVersion);
            throw $failure;
        }

        if (! $this->constraints->allows($normalized, $constraint)) {
            $failure = $this->managerFailure(
                tool: $tool,
                errorCode: 'tool.installed_version_constraint_violated',
                status: 409,
                message: 'The installed tool version does not satisfy the constraint.',
            );
            $this->markToolFailure($tool, ToolOperation::Update, $failure, $rawVersion);
            throw $failure;
        }
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

    private function isSafeRawVersion(string $version): bool
    {
        return $version !== '' && strlen($version) <= 255 && preg_match('/[\x00-\x1F\x7F]/', $version) !== 1;
    }

    /** @mago-expect lint:excessive-parameter-list Manager failures preserve the stable operation envelope. */
    private function managerFailure(
        Tool $tool,
        string $errorCode,
        int $status,
        string $message,
        ToolOutcome $outcome = ToolOutcome::ManagerFailed,
        ?ToolManagerException $previous = null,
    ): ToolOperationException {
        return $this->failure(
            tool: $tool,
            errorCode: $errorCode,
            outcome: $outcome,
            status: $status,
            message: $message,
            previous: $previous,
        );
    }

    /** @mago-expect lint:excessive-parameter-list The stable operation envelope requires each public field. */
    private function failure(
        Tool $tool,
        string $errorCode,
        ToolOutcome $outcome,
        int $status,
        string $message,
        ?ToolManagerException $previous = null,
    ): ToolOperationException {
        return new ToolOperationException(
            step: ToolOperation::Update->value,
            errorCode: $errorCode,
            outcome: $outcome,
            status: $status,
            nodeId: $tool->node_id,
            manager: $tool->manager->name->value,
            package: $tool->package,
            versionConstraint: $tool->version_constraint,
            message: $message,
            previous: $previous,
        );
    }
}
