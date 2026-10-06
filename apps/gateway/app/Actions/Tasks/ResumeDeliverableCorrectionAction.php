<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskExecutionHold;
use App\Domain\Tasks\TaskQuestions;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskTurnFetcher;
use App\Domain\Tasks\TaskTurnMode;
use App\Domain\Tasks\TaskTurnReceiptException;
use App\Domain\Tasks\TaskTurnReceipts;
use App\Models\Activity;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Only the correction resume uses this durable, keyed delivery. Ordinary resolutions keep their existing flow. */
final readonly class ResumeDeliverableCorrectionAction
{
    public function __construct(
        private AgentDriverRegistry $drivers,
        private TaskTurnReceipts $receipts,
        private TaskTurnFetcher $fetcher,
    ) {}

    /** Reserve with the resolution comment, before any remote effects. The first resolution owns retries. */
    public function reserve(Task $task, TaskComment $comment, ?Node $actor = null, ?string $requestId = null): void
    {
        $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
        if ($locked->deliverable_correction_resume !== null) {
            return;
        }
        $contract = json_encode($locked->deliverables, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $locked->update(['deliverable_correction_resume' => [
            'comment_id' => $comment->id, 'thread_id' => $locked->implementer_agent_thread_id,
            'key' => (string) Str::uuid(), 'state' => 'pending',
            'caller_node_id' => $actor?->id, 'caller_ip' => $actor?->wireguard_ip,
            'request_id' => $requestId ?? (string) Str::uuid(),
            'message' => $comment->body."\n\nDeliverables were corrected. This complete list replaces the previous contract. Confirm every corrected deliverable at handoff:\n\n```json\n".$contract."\n```",
        ]]);
    }

    /** Called both after the resolution write and by the scheduler while assistance still holds progress. */
    public function execute(Task $task): void
    {
        TaskExecutionHold::run($task->parent, function () use ($task): void {
            $task = Task::query()->findOrFail($task->id);
            $resume = $task->deliverable_correction_resume;
            if ($resume === null || $resume['state'] !== 'pending' || $task->status !== TaskStatus::Running
                || self::directionPending($task, $task->parent)) {
                return;
            }
            $comment = TaskComment::query()->findOrFail($resume['comment_id']);
            $thread = AgentThread::query()->where('task_group_id', $task->parent_id)->find($resume['thread_id']);
            $group = $task->parent()->with(['project', 'taskable'])->firstOrFail();
            $instance = $group->taskable;
            if (! $thread instanceof AgentThread || ! $instance instanceof Instance) {
                return;
            }
            try {
                $this->fetcher->beforeTurn($group);
                $task->refresh();
                $group->refresh();
                if ($task->deliverable_correction_resume !== $resume || $task->status !== TaskStatus::Running
                    || self::directionPending($task, $group)) {
                    return;
                }
                // The remote metadata commit is keyed too. A lost prepare reply cannot erase a resumed receipt.
                $this->receipts->prepare($instance, TaskThreadRole::Implementer, false, $task->deliverableList(), $thread->id, new TaskTurnMode(deliveryKey: $resume['key']));
                $task->refresh();
                $group->refresh();
                if ($task->deliverable_correction_resume !== $resume || $task->status !== TaskStatus::Running
                    || self::directionPending($task, $group)) {
                    return;
                }
                // Pi reconciles acceptance with the same key, even when both replies to a send were lost.
                $this->drivers->get($thread->driver)->send($thread, $resume['message'], $resume['key']);
            } catch (AgentDriverException|TaskTurnReceiptException) {
                return;
            }

            DB::transaction(static function () use ($task, $comment, $resume): void {
                $parent = Task::topLevel()->lockForUpdate()->findOrFail($task->parent_id);
                $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
                if ($locked->deliverable_correction_resume !== $resume || TaskExecutionHold::active($parent)
                    || $locked->status !== TaskStatus::Running) {
                    return;
                }
                $direction = self::directionPending($locked, $parent);
                $locked->update([
                    ...($direction ? [] : TaskAssistance::cleared()), 'communication_failures' => 0,
                    'completion_attempt' => $locked->completion_attempt + 1,
                    'completion_reminder_attempt' => null, 'completion_reminder_input_id' => null,
                    'resolution_delivered_comment_id' => $comment->id,
                    'deliverable_correction_resume' => [...$resume, 'state' => 'delivered'],
                ]);
                if (! $direction) {
                    $parent->update(TaskAssistance::cleared());
                    TaskQuestions::attachResolution($locked, $comment);
                }
                Activity::query()->create([
                    'log_name' => 'tasks', 'description' => 'deliverable correction resumed', 'subject_type' => Task::class,
                    'subject_id' => $locked->id, 'properties' => ['comment_id' => $comment->id, 'author' => $comment->author, 'send_key' => $resume['key']],
                    'caller_node_id' => $resume['caller_node_id'] ?? null, 'caller_ip' => $resume['caller_ip'] ?? null,
                    'request_id' => $resume['request_id'] ?? (string) Str::uuid(), 'command' => 'tasks:comment', 'status' => 'completed',
                ]);
            });
        });
    }

    /** A direction continuation carries the corrected contract instead of replaying an older correction turn. */
    public function continuationMessage(Task $task, string $answer): string
    {
        if (($task->deliverable_correction_resume['state'] ?? null) !== 'pending') {
            return $answer;
        }
        $contract = json_encode($task->deliverables, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $answer."\n\nThe direction above is authoritative. This corrected deliverable list replaces the previous contract. Confirm every corrected deliverable at handoff:\n\n```json\n".$contract."\n```";
    }

    /** Called with the fresh locked task inside the transaction that finishes the authoritative continuation. */
    public function supersede(Task $task, TaskComment $continuation): void
    {
        $resume = $task->deliverable_correction_resume;
        if ($resume === null || $resume['state'] !== 'pending') {
            return;
        }
        $task->update(['deliverable_correction_resume' => [...$resume, 'state' => 'superseded']]);
        Activity::query()->create([
            'log_name' => 'tasks', 'description' => 'deliverable correction superseded by direction',
            'subject_type' => Task::class, 'subject_id' => $task->id,
            'properties' => ['comment_id' => $resume['comment_id'], 'continuation_comment_id' => $continuation->id],
            'caller_node_id' => $resume['caller_node_id'] ?? null, 'caller_ip' => $resume['caller_ip'] ?? null,
            'request_id' => $resume['request_id'] ?? (string) Str::uuid(), 'command' => 'tasks:comment', 'status' => 'completed',
        ]);
    }

    private static function directionPending(Task $task, Task $group): bool
    {
        return $task->assistance_kind === AssistanceKind::Direction || $group->assistance_kind === AssistanceKind::Direction
            || $task->direction_relay_comment_id !== null || $task->consult_comment_id !== null;
    }
}
