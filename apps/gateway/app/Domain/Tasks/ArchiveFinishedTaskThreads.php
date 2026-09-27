<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AgentThread;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

final readonly class ArchiveFinishedTaskThreads
{
    private const int MAX_THREADS_PER_RUN = 10;

    /** @var list<int> */
    private const array RETRY_BACKOFF_MINUTES = [1, 5, 30, 120];

    public function __construct(private AgentDriverRegistry $drivers) {}

    public function run(): void
    {
        AgentThread::query()->where('external_id', 'like', TaskAgentSpawner::PendingPrefix.'%')
            ->where(static function ($query): void {
                $query->whereIn('task_group_id', static function ($groups): void {
                    $groups->select('id')->from('task_groups')->whereIn('status', [TaskGroupStatus::Completed->value, TaskGroupStatus::Cancelled->value]);
                })->orWhereIn('task_id', static function ($tasks): void {
                    $tasks->select('id')->from('tasks')->whereIn('status', [TaskStatus::Completed->value, TaskStatus::Cancelled->value]);
                });
            })->delete();

        $threads = AgentThread::query()->where('driver', 't3')->whereNull('archived_at')
            ->whereNotNull('t3_metrics_final_at')
            ->where(static fn ($query) => $query->whereNull('archive_retry_at')->orWhere('archive_retry_at', '<=', now()))
            ->where('external_id', 'not like', TaskAgentSpawner::PendingPrefix.'%')
            ->where(static function ($query): void {
                $query->whereIn('task_group_id', static function ($groups): void {
                    $groups->select('id')->from('task_groups')->whereIn('status', [TaskGroupStatus::Completed->value, TaskGroupStatus::Cancelled->value]);
                })->orWhere(static function ($subtasks): void {
                    $subtasks->where('role', TaskThreadRole::Reviewer->value)->whereIn('task_id', static function ($tasks): void {
                        $tasks->select('id')->from('tasks')->whereIn('status', [TaskStatus::Completed->value, TaskStatus::Cancelled->value]);
                    });
                });
            })->orderBy('id')->limit(self::MAX_THREADS_PER_RUN)->get();

        foreach ($threads as $thread) {
            $thread->archive_command_id ??= (string) Str::uuid();
            $thread->save();
            try {
                $this->drivers->get($thread->driver)->archive($thread, $thread->archive_command_id);
                $thread->forceFill([
                    'archived_at' => now(),
                    'archive_attempts' => 0,
                    'archive_retry_at' => null,
                ])->save();
            } catch (Throwable $exception) {
                $attempts = $thread->archive_attempts + 1;
                $backoff = self::RETRY_BACKOFF_MINUTES[min($attempts - 1, count(self::RETRY_BACKOFF_MINUTES) - 1)];
                $thread->forceFill([
                    'archive_attempts' => $attempts,
                    'archive_retry_at' => now()->addMinutes($backoff),
                ])->save();

                if (Cache::add('task-thread-archive-failure-reported:'.$thread->id, true, now()->addHour())) {
                    report($exception);
                }
            }
        }
    }
}
