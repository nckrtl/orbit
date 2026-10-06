<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Instance;
use App\Models\Task;

/** A persisted attempt; it is not permission to bypass current ownership or direction holds. */
final class TaskHandoffRetry
{
    public function __construct(
        public int $sourceCheckId,
        public int $receiptId,
        public int $instanceId,
        public int $implementerId,
        public ?int $reviewerId,
        public int $attempt,
        public string $head,
        public string $tree,
        public string $target,
        public string $proofBase,
        public string $checkout,
        public string $branch,
        public string $indexTree,
        public string $phase = 'prepared',
        public ?string $advancedTree = null,
        public ?string $advancedIndexTree = null,
        public ?int $checkId = null,
        public ?string $error = null,
    ) {}

    /** @param array<string, mixed>|null $data */
    public static function fromArray(?array $data): ?self
    {
        if ($data === null) {
            return null;
        }
        foreach (['sourceCheckId', 'receiptId', 'instanceId', 'implementerId', 'attempt'] as $key) {
            if (! is_int($data[$key] ?? null)) {
                return null;
            }
        }
        foreach (['head', 'tree', 'target', 'proofBase', 'indexTree'] as $key) {
            if (! is_string($data[$key] ?? null) || preg_match('/\A[0-9a-f]{40}\z/', $data[$key]) !== 1) {
                return null;
            }
        }
        if (! is_string($data['checkout'] ?? null) || $data['checkout'] === ''
            || ! is_string($data['branch'] ?? null) || $data['branch'] === ''
            || ! in_array($data['phase'] ?? null, ['prepared', 'advancing', 'advanced', 'start_requested', 'running', 'finished'], true)
            || (isset($data['reviewerId']) && ! is_int($data['reviewerId']))
            || (isset($data['checkId']) && ! is_int($data['checkId']))
            || (isset($data['advancedTree']) && (! is_string($data['advancedTree']) || preg_match('/\A[0-9a-f]{40}\z/', $data['advancedTree']) !== 1))
            || (isset($data['advancedIndexTree']) && (! is_string($data['advancedIndexTree']) || preg_match('/\A[0-9a-f]{40}\z/', $data['advancedIndexTree']) !== 1))
            || (isset($data['error']) && ! is_string($data['error']))) {
            return null;
        }

        return new self($data['sourceCheckId'], $data['receiptId'], $data['instanceId'], $data['implementerId'],
            $data['reviewerId'] ?? null, $data['attempt'], $data['head'], $data['tree'], $data['target'],
            $data['proofBase'], $data['checkout'], $data['branch'], $data['indexTree'], $data['phase'], $data['advancedTree'] ?? null, $data['advancedIndexTree'] ?? null, $data['checkId'] ?? null, $data['error'] ?? null);
    }

    public function owns(Task $group, Task $task): bool
    {
        $group->loadMissing('taskable');
        $instance = $group->taskable;

        return $group->execution_mode === TaskExecutionMode::Managed && $instance instanceof Instance && $instance->checkout_path === $this->checkout && $instance->branch === $this->branch
            && $group->taskable_id === $this->instanceId && $task->implementer_agent_thread_id === $this->implementerId
            && $group->reviewer_agent_thread_id === $this->reviewerId && $task->completion_attempt === $this->attempt
            && TaskReviewBase::commit($task) === $this->proofBase
            && $task->assistance_requested && $task->assistance_kind === AssistanceKind::Failure && $group->assistance_kind !== AssistanceKind::Direction
            && $group->status === TaskGroupStatus::Running && $task->status === TaskStatus::Running && ! TaskExecutionHold::active($group);
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'sourceCheckId' => $this->sourceCheckId,
            'receiptId' => $this->receiptId,
            'instanceId' => $this->instanceId,
            'implementerId' => $this->implementerId,
            'reviewerId' => $this->reviewerId,
            'attempt' => $this->attempt,
            'head' => $this->head,
            'tree' => $this->tree,
            'target' => $this->target,
            'proofBase' => $this->proofBase,
            'checkout' => $this->checkout,
            'branch' => $this->branch,
            'indexTree' => $this->indexTree,
            'phase' => $this->phase,
            'advancedTree' => $this->advancedTree,
            'advancedIndexTree' => $this->advancedIndexTree,
            'checkId' => $this->checkId,
            'error' => $this->error,
        ];
    }
}
