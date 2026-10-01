<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Domain\Nodes\NodeLockLoss;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolActionResult;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolNodeEligibility;
use App\Domain\Tools\ToolObjectStatus;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolOperationException;
use App\Domain\Tools\ToolOperationLock;
use App\Domain\Tools\ToolOutcome;
use App\Domain\Tools\ToolStatus;
use App\Models\Node;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Throwable;

final readonly class RemoveToolAction
{
    use MarksToolFailures;

    public function __construct(
        private ToolManagerRegistry $managers,
        private ToolOperationLock $lock,
        private ToolNodeEligibility $eligibility,
    ) {}

    public function execute(Tool $tool): ToolActionResult
    {
        [, , $manager] = $this->resolveState($tool);

        if (! in_array($tool->status, [ToolStatus::Installed, ToolStatus::Failed], strict: true)) {
            throw $this->failure($tool, 'tool.state_invalid', 409, 'The tool is not in a removable state.');
        }

        return $this->lock->run(
            nodeId: $tool->node_id,
            manager: $manager->name(),
            package: $tool->package,
            operation: ToolOperation::Remove,
            versionConstraint: $tool->version_constraint,
            callback: fn (): ToolActionResult => $this->underLock($tool),
        );
    }

    private function underLock(Tool $tool): ToolActionResult
    {
        $tool->refresh();
        [$node, , $manager] = $this->resolveState($tool);

        if (! in_array($tool->status, [ToolStatus::Installed, ToolStatus::Failed], strict: true)) {
            throw $this->failure($tool, 'tool.state_invalid', 409, 'The tool is not in a removable state.');
        }

        if ($this->isUnprovenFailedTool($tool)) {
            return $this->removed($tool);
        }

        try {
            $installedVersion = $this->installedVersion($tool, $node, $manager);

            if ($installedVersion === null) {
                return $this->removed($tool);
            }

            try {
                $plan = $manager->planRemoval($node, $tool->package);
            } catch (ToolManagerException $exception) {
                throw $this->removalFailure($tool, $exception, 'The tool removal plan failed.');
            } catch (Throwable $exception) {
                throw $this->failure($tool, 'tool.remove_failed', 502, 'The tool removal plan failed.', previous: NodeLockLoss::keep($exception));
            }

            if (! $plan->removesOnly($tool->package)) {
                throw $this->failure(
                    tool: $tool,
                    errorCode: 'tool.removal_plan_unsafe',
                    status: 409,
                    message: 'The tool removal plan is not exact.',
                );
            }

            $storedStatus = $tool->status;
            $storedFailure = $tool->failed_operation;
            $storedErrorCode = $tool->error_code;
            $tool->update([
                'status' => ToolStatus::Removing,
                'failed_operation' => null,
                'error_code' => null,
            ]);

            try {
                $manager->remove($node, $tool->package);
            } catch (ToolManagerException $exception) {
                throw $this->removalFailure($tool, $exception, 'The tool manager removal failed.');
            } catch (Throwable $exception) {
                throw $this->failure($tool, 'tool.remove_failed', 502, 'The tool manager removal failed.', previous: NodeLockLoss::keep($exception));
            }

            $after = $this->installedVersion($tool, $node, $manager);

            if ($after !== null) {
                throw $this->failure(
                    tool: $tool,
                    errorCode: 'tool.remove_failed',
                    status: 502,
                    message: 'The tool remained installed after removal.',
                );
            }

            return $this->removed($tool, $storedStatus, $storedFailure, $storedErrorCode);
        } catch (ToolOperationException $exception) {
            $this->markToolFailure($tool, ToolOperation::Remove, $exception);

            throw $exception;
        }
    }

    /**
     * The activity snapshot is this deleted model. Drop the transient removing
     * claim so a finished removal does not show `removing`.
     */
    private function removed(
        Tool $tool,
        ?ToolStatus $statusBeforeClaim = null,
        ?ToolOperation $failedOperationBeforeClaim = null,
        ?string $errorCodeBeforeClaim = null,
    ): ToolActionResult {
        $tool->delete();

        if ($statusBeforeClaim instanceof ToolStatus && $tool->status === ToolStatus::Removing) {
            $tool->status = $statusBeforeClaim;
            $tool->failed_operation = $failedOperationBeforeClaim;
            $tool->error_code = $errorCodeBeforeClaim;
            $tool->syncOriginal();
        }

        return new ToolActionResult($tool, ToolOutcome::Applied, status: ToolObjectStatus::Removed);
    }

    private function isUnprovenFailedTool(Tool $tool): bool
    {
        return $tool->status === ToolStatus::Failed
            && $tool->error_code === 'tool.version_probe_failed'
            && ($tool->installed_version === null || $tool->installed_version === '');
    }

    private function installedVersion(Tool $tool, Node $node, ToolManager $manager): ?string
    {
        try {
            return $manager->installedVersion($node, $tool->package);
        } catch (ToolManagerException $exception) {
            throw $this->removalFailure(
                $tool,
                $exception,
                'The installed tool version could not be verified.',
                'tool.version_probe_failed',
            );
        } catch (Throwable $exception) {
            throw $this->failure(
                tool: $tool,
                errorCode: 'tool.version_probe_failed',
                status: 502,
                message: 'The installed tool version could not be verified.',
                previous: NodeLockLoss::keep($exception),
            );
        }
    }

    /** @return array{Node, ToolManagerRecord, ToolManager} */
    private function resolveState(Tool $tool): array
    {
        $node = Node::query()->find($tool->node_id);
        $record = ToolManagerRecord::query()->find($tool->tool_manager_id);

        if (! $node instanceof Node || ! $record instanceof ToolManagerRecord || $record->node_id !== $tool->node_id) {
            throw $this->failure($tool, 'tool.state_invalid', 409, 'The persisted tool ownership is invalid.');
        }

        if ($node->status !== LifecycleStatus::Active) {
            throw $this->failure($tool, 'tool.node_inactive', 409, 'Tools can be removed only from an active node.');
        }

        if (! $this->eligibility->allows($node)) {
            throw $this->failure(
                $tool,
                'tool.node_unmanaged',
                409,
                'Tools can be removed only from a Gateway-managed node.',
            );
        }

        if ($record->status !== LifecycleStatus::Active) {
            throw $this->failure($tool, 'tool.manager_unavailable', 409, 'The tool manager is not available.');
        }

        $manager = $this->managers->find($record->name);

        if (! $manager instanceof ToolManager || ! $manager->supportsNode($node)) {
            throw $this->failure($tool, 'tool.manager_unsupported', 422, 'The requested tool manager is not supported.');
        }

        return [$node, $record, $manager];
    }

    private function removalFailure(
        Tool $tool,
        ToolManagerException $exception,
        string $message,
        string $errorCode = 'tool.remove_failed',
    ): ToolOperationException {
        if (in_array($exception->step, ['manager-absent', 'manager-conflict'], true)) {
            return $this->failure(
                tool: $tool,
                errorCode: 'tool.manager_unavailable',
                status: 409,
                message: 'The tool manager is not available.',
                previous: $exception,
            );
        }

        return $this->failure(
            tool: $tool,
            errorCode: $errorCode,
            status: 502,
            message: $message,
            previous: $exception,
        );
    }

    private function failure(
        Tool $tool,
        string $errorCode,
        int $status,
        string $message,
        ?Throwable $previous = null,
    ): ToolOperationException {
        $record = ToolManagerRecord::query()->find($tool->tool_manager_id);
        $manager = $record instanceof ToolManagerRecord
            ? $record->name
            : 'unknown';

        return new ToolOperationException(
            step: ToolOperation::Remove->value,
            errorCode: $errorCode,
            outcome: ToolOutcome::ManagerFailed,
            status: $status,
            nodeId: $tool->node_id,
            manager: $manager,
            package: $tool->package,
            versionConstraint: $tool->version_constraint,
            message: $message,
            previous: $previous,
            toolId: $tool->id,
        );
    }
}
