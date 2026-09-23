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
     * Installs `.git/orbit/run`, records whose turn starts, and removes any earlier receipt.
     *
     * @throws TaskRunReceiptException
     */
    public function prepare(AppInstance $instance, TaskThreadRole $role): void;

    /** @throws TaskRunReceiptException */
    public function read(AppInstance $instance): ?TaskRunReceipt;

    /**
     * Removes the receipt only while it still has the content that was read.
     *
     * @throws TaskRunReceiptException
     */
    public function clear(AppInstance $instance, TaskRunReceipt $receipt): void;
}
