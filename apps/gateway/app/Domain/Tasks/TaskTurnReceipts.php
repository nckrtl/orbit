<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Instance;

/**
 * Places the turn command in a task workspace and reads the receipt an agent writes with it.
 */
interface TaskTurnReceipts
{
    /**
     * Installs `.git/orbit/turn`, records whose turn starts, whether it reviews the last subtask,
     * the subtask's deliverables, and the acting Orbit thread id, and removes any earlier receipt.
     * When `$context` is set, also writes `.git/orbit/context.md` in the same install.
     *
     * @param  list<TaskDeliverable>  $deliverables
     *
     * @throws TaskTurnReceiptException
     */
    public function prepare(Instance $instance, TaskThreadRole $role, bool $final = false, array $deliverables = [], ?int $threadId = null, ?TaskTurnMode $mode = null, ?string $context = null): void;

    /**
     * Reads the receipt. When `$actingThreadId` is set, the receipt applies only when it names that
     * thread. A receipt with no thread id does not apply. A stale turn file does not hide a match.
     *
     * @throws TaskTurnReceiptException
     */
    public function read(Instance $instance, ?int $actingThreadId = null): ?TaskTurnReceipt;

    /**
     * Whether the current turn file exists and does not name an Orbit thread. A missing turn file is not legacy.
     *
     * @throws TaskTurnReceiptException
     */
    public function hasLegacyTurn(Instance $instance): bool;

    /**
     * Removes the receipt only while it still has the content that was read.
     *
     * @throws TaskTurnReceiptException
     */
    public function clear(Instance $instance, TaskTurnReceipt $receipt): void;
}
