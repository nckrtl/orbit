<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolActionResult;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolOperationException;
use App\Domain\Tools\ToolOperationLock;
use App\Domain\Tools\ToolOutcome;
use App\Domain\Tools\ToolStatus;
use App\Models\Node;
use App\Models\Tool;
use App\Models\ToolManagerRecord;
use Throwable;

/** @mago-expect lint:cyclomatic-complexity The removal state machine keeps exact planning and verification gates explicit. */
final readonly class RemoveToolAction
{
    use MarksToolFailures;

    public function __construct(
        private ToolManagerRegistry $managers,
        private ToolOperationLock $lock,
    ) {}

    public function execute(Tool $tool): ToolActionResult
    {
        if ($tool->protected) {
            throw $this->failure($tool, 'tool.protected', 409, 'Protected tools cannot be removed.');
        }

        [, $record] = $this->resolveState($tool);

        if (! in_array($tool->status, [ToolStatus::Installed, ToolStatus::Failed], strict: true)) {
            throw $this->failure($tool, 'tool.state_invalid', 409, 'The tool is not in a removable state.');
        }

        return $this->lock->run(
            nodeId: $tool->node_id,
            manager: $record->name,
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

        if ($tool->protected) {
            throw $this->failure($tool, 'tool.protected', 409, 'Protected tools cannot be removed.');
        }

        if (! in_array($tool->status, [ToolStatus::Installed, ToolStatus::Failed], strict: true)) {
            throw $this->failure($tool, 'tool.state_invalid', 409, 'The tool is not in a removable state.');
        }

        try {
            $installedVersion = $this->installedVersion($tool, $node, $manager);

            if ($installedVersion === null) {
                $tool->delete();

                return new ToolActionResult($tool, ToolOutcome::Applied);
            }

            try {
                $plan = $manager->planRemoval($node, $tool->package);
            } catch (ToolManagerException $exception) {
                throw $this->failure(
                    tool: $tool,
                    errorCode: 'tool.remove_failed',
                    status: 502,
                    message: 'The tool removal plan failed.',
                    previous: $exception,
                );
            } catch (Throwable) {
                throw $this->failure($tool, 'tool.remove_failed', 502, 'The tool removal plan failed.');
            }

            if (! $plan->removesOnly($tool->package)) {
                throw $this->failure(
                    tool: $tool,
                    errorCode: 'tool.removal_plan_unsafe',
                    status: 409,
                    message: 'The tool removal plan is not exact.',
                );
            }

            $tool->update([
                'status' => ToolStatus::Removing,
                'failed_operation' => null,
                'error_code' => null,
            ]);

            try {
                $manager->remove($node, $tool->package);
            } catch (ToolManagerException $exception) {
                throw $this->failure(
                    tool: $tool,
                    errorCode: 'tool.remove_failed',
                    status: 502,
                    message: 'The tool manager removal failed.',
                    previous: $exception,
                );
            } catch (Throwable) {
                throw $this->failure($tool, 'tool.remove_failed', 502, 'The tool manager removal failed.');
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

            $tool->delete();

            return new ToolActionResult($tool, ToolOutcome::Applied);
        } catch (ToolOperationException $exception) {
            $this->markToolFailure($tool, ToolOperation::Remove, $exception);

            throw $exception;
        }
    }

    private function installedVersion(Tool $tool, Node $node, ToolManager $manager): ?string
    {
        try {
            return $manager->installedVersion($node, $tool->package);
        } catch (ToolManagerException $exception) {
            throw $this->failure(
                tool: $tool,
                errorCode: 'tool.version_probe_failed',
                status: 502,
                message: 'The installed tool version could not be verified.',
                previous: $exception,
            );
        } catch (Throwable) {
            throw $this->failure(
                tool: $tool,
                errorCode: 'tool.version_probe_failed',
                status: 502,
                message: 'The installed tool version could not be verified.',
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

        if ($record->status !== LifecycleStatus::Active) {
            throw $this->failure($tool, 'tool.manager_unavailable', 409, 'The tool manager is not available.');
        }

        $manager = $this->managers->find($record->name->value);

        if (! $manager instanceof ToolManager || ! $manager->supportsNode($node)) {
            throw $this->failure($tool, 'tool.manager_unavailable', 409, 'The tool manager is not available.');
        }

        return [$node, $record, $manager];
    }

    private function failure(
        Tool $tool,
        string $errorCode,
        int $status,
        string $message,
        ?ToolManagerException $previous = null,
    ): ToolOperationException {
        $record = ToolManagerRecord::query()->find($tool->tool_manager_id);
        $manager = $record instanceof ToolManagerRecord
            ? (string) $record->getRawOriginal('name')
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
        );
    }
}
