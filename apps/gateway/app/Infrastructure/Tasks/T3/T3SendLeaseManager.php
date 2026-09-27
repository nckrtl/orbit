<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\T3;

use App\Models\AgentThread;
use App\Models\AgentThreadSendLease;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class T3SendLeaseManager
{
    public const int LeaseSeconds = 60;

    public function acquire(AgentThread $thread): string
    {
        $ownerToken = (string) Str::uuid();

        DB::transaction(function () use ($thread, $ownerToken): void {
            AgentThread::query()->whereKey($thread->id)->increment('t3_metrics_activity_version', 1, [
                't3_metrics_final_at' => null,
                't3_metrics_retry_at' => null,
                't3_metrics_attempts' => 0,
                't3_metrics_observed_activity_version' => null,
            ]);
            AgentThreadSendLease::query()->create([
                'agent_thread_id' => $thread->id,
                'owner_token' => $ownerToken,
                'expires_at' => now()->addSeconds(self::LeaseSeconds),
            ]);
        });

        return $ownerToken;
    }

    public function release(AgentThread $thread, string $ownerToken): void
    {
        DB::transaction(function () use ($thread, $ownerToken): void {
            AgentThreadSendLease::query()
                ->where('agent_thread_id', $thread->id)
                ->where('owner_token', $ownerToken)
                ->delete();

            // Always invalidate a collection or observation that overlapped this sender. The token
            // predicate above ensures a late cleanup never deletes another sender's lease.
            AgentThread::query()->whereKey($thread->id)->increment('t3_metrics_activity_version', 1, [
                't3_metrics_final_at' => null,
                't3_metrics_retry_at' => null,
                't3_metrics_attempts' => 0,
                't3_metrics_observed_activity_version' => null,
            ]);
        });
    }

    public function pruneExpired(): void
    {
        $expiredIds = AgentThreadSendLease::query()
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->limit(100)
            ->select('id');

        AgentThreadSendLease::query()->whereIn('id', $expiredIds)->delete();
    }
}
