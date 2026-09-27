<?php

declare(strict_types=1);

use App\Data\AppInstances\AppInstanceTransferData;
use App\Data\Tasks\TaskCommentData;
use App\Models\AppInstanceTransfer;
use App\Models\TaskComment;

it('refuses a task comment whose type is not a known comment', function (): void {
    $comment = new TaskComment;
    $comment->setRawAttributes([
        'id' => 1,
        'task_group_id' => 2,
        'task_id' => 3,
        'agent_thread_id' => null,
        'type' => 'not-a-type',
        'body' => 'body',
        'author' => 'ada',
        'posted_at' => '2026-09-27 12:00:00',
    ], true);

    expect(fn () => TaskCommentData::fromModel($comment))
        ->toThrow(InvalidArgumentException::class, 'Task comment type is invalid.');
});

it('refuses transfer history that no longer belongs to an Instance', function (): void {
    $transfer = new AppInstanceTransfer;
    $transfer->setRawAttributes(['app_instance_id' => null], true);

    expect(fn () => AppInstanceTransferData::fromModel($transfer))
        ->toThrow(InvalidArgumentException::class, 'An Instance transfer has no Instance.');
});
