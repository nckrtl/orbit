<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;

/**
 * Places the run script in a task workspace and reads the receipt an agent writes with it.
 */
interface TaskRunReceipts
{
    /**
     * Installs `.git/orbit/run`, records whose turn starts, whether it reviews the last subtask,
     * the subtask's deliverables, and the acting Orbit thread id, and removes any earlier receipt.
     *
     * @param  list<TaskDeliverable>  $deliverables
     *
     * @throws TaskRunReceiptException
     */
    public function prepare(AppInstance $instance, TaskThreadRole $role, bool $final = false, array $deliverables = [], ?int $threadId = null): void;

    /**
     * Reads the receipt. When `$actingThreadId` is set, the receipt applies only when it names that
     * thread. A receipt with no thread id does not apply. A stale turn file does not hide a match.
     *
     * @throws TaskRunReceiptException
     */
    public function read(AppInstance $instance, ?int $actingThreadId = null): ?TaskRunReceipt;

    /**
     * Whether the current turn file exists and does not name an Orbit thread. A missing turn file is not legacy.
     *
     * @throws TaskRunReceiptException
     */
    public function hasLegacyTurn(AppInstance $instance): bool;

    /**
     * Removes the receipt only while it still has the content that was read.
     *
     * @throws TaskRunReceiptException
     */
    public function clear(AppInstance $instance, TaskRunReceipt $receipt): void;
}
