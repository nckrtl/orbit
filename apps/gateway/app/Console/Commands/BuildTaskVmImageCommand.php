<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\IncusTaskVmImageBuilder;
use App\Models\Node;
use Illuminate\Console\Command;

/** Builds the base image of one task VM host and prints each stage with its time. */
final class BuildTaskVmImageCommand extends Command
{
    #[\Override]
    protected $signature = 'task-vms:build-image {node : Id or name of the Incus host Node}';

    #[\Override]
    protected $description = 'Build the task VM base image of an Incus host and point orbit-task-base at it.';

    public function handle(IncusTaskVmImageBuilder $builder): int
    {
        $started = microtime(true);
        try {
            $node = $this->node((string) $this->argument('node'));
            $host = resolve(TaskVmSettings::class)->host($node->id);
            $fingerprint = $builder->build($host, $node, function (string $stage, float $seconds): void {
                $this->line(sprintf('%-22s %7.1fs', $stage, $seconds));
            });
        } catch (ResourceOperationException $exception) {
            $this->error("[{$exception->errorCode}] {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info(sprintf('Node [%s] has the base image [%s] (%s) after %.1fs.', $node->name, TaskVmHost::BaseImage, substr($fingerprint, 0, 12), microtime(true) - $started));

        return self::SUCCESS;
    }

    private function node(string $reference): Node
    {
        $node = ctype_digit($reference)
            ? Node::query()->find((int) $reference)
            : Node::query()->where('name', $reference)->first();

        if (! $node instanceof Node || $node->status !== LifecycleStatus::Active) {
            throw new TaskVmException('task_vm.unknown_host', "Node [{$reference}] is not an active Node.");
        }

        return $node;
    }
}
