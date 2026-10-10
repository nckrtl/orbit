<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\IncusTaskVmImageBuilder;
use App\Models\Node;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The nightly refresh: rebuilds the base image of every configured host that already has one, one host
 * after another. The first build of a host is always `task-vms:build-image`, so a host without a base
 * image is left alone. A failed host keeps its image; the job fails after the other hosts.
 */
final class RefreshTaskVmBaseImages implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Two hosts of about five minutes each, within the worker's job limit. */
    public int $timeout = 1500;

    public bool $failOnTimeout = true;

    /** The owner of the build locks this job takes, so `failed()` cleans up only its own build. */
    private const string LockOwner = 'nightly-refresh';

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onConnection('task-vms')->onQueue('task-vms');
    }

    public function handle(TaskVmSettings $settings, IncusTaskVmImageBuilder $builder): void
    {
        $failures = [];
        foreach ($settings->hosts as $host) {
            $node = Node::query()->whereKey($host->nodeId)->where('status', LifecycleStatus::Active)->first();
            if (! $node instanceof Node) {
                continue;
            }
            try {
                if ($builder->exists($host, $node)) {
                    $builder->build($host, $node, owner: self::LockOwner);
                }
            } catch (Throwable $exception) {
                Log::error('The task VM base image refresh failed.', ['node_id' => $node->id, 'error' => $exception->getMessage()]);
                $failures[] = "Node [{$node->name}]: ".TaskVmException::codeOf($exception);
            }
        }

        if ($failures !== []) {
            throw new TaskVmException('task_vm.image_build_failed', 'The base image refresh failed on '.implode(', ', $failures).'.', 502);
        }
    }

    /**
     * After a timeout the worker stops this job in the middle of a build, so the build's own cleanup never
     * runs. The worker calls this first: it deletes the builder VM and frees the host's build lock. After an
     * ordinary failure the build has cleaned up already, and this does nothing.
     */
    public function failed(?Throwable $exception): void
    {
        $settings = app(TaskVmSettings::class);
        $builder = app(IncusTaskVmImageBuilder::class);
        foreach ($settings->hosts as $host) {
            $node = Node::query()->find($host->nodeId);
            if ($node instanceof Node) {
                $builder->abandon($host, $node, self::LockOwner);
            }
        }
    }
}
