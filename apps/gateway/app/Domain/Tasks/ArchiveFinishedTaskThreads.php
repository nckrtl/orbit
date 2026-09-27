<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AgentThread;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final readonly class ArchiveFinishedTaskThreads
{
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
            ->where('external_id', 'not like', TaskAgentSpawner::PendingPrefix.'%')
            ->where(static function ($query): void {
                $query->whereIn('task_group_id', static function ($groups): void {
                    $groups->select('id')->from('task_groups')->whereIn('status', [TaskGroupStatus::Completed->value, TaskGroupStatus::Cancelled->value]);
                })->orWhere(static function ($subtasks): void {
                    $subtasks->where('role', TaskThreadRole::Reviewer->value)->whereIn('task_id', static function ($tasks): void {
                        $tasks->select('id')->from('tasks')->whereIn('status', [TaskStatus::Completed->value, TaskStatus::Cancelled->value]);
                    });
                });
            })->orderBy('id')->get();

        foreach ($threads as $thread) {
            $thread->archive_command_id ??= (string) Str::uuid();
            $thread->save();
            try {
                $this->drivers->get($thread->driver)->archive($thread, $thread->archive_command_id);
                $thread->forceFill(['archived_at' => now()])->save();
            } catch (Throwable $exception) {
                Log::warning('Agent thread archive failed; it will be retried.', [
                    'agent_thread_id' => $thread->id,
                    'driver' => $thread->driver,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }
}
