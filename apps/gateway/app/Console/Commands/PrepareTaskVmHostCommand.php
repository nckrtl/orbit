<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\TaskVmSetupScript;
use App\Models\Node;
use Illuminate\Console\Command;

/** Runs `incus-host.sh` on one host with the values that `TaskVmSettings` validated. */
final class PrepareTaskVmHostCommand extends Command
{
    #[\Override]
    protected $signature = 'task-vms:prepare-host {node : Id or name of the Incus host Node}';

    #[\Override]
    protected $description = 'Prepare an Incus host for task VMs: ZFS pool, project, stock image, bridge, egress ACL, profile and the ufw route rule.';

    public function handle(TaskVmSetupScript $script): int
    {
        try {
            $node = $this->node((string) $this->argument('node'));
            $host = resolve(TaskVmSettings::class)->host($node->id);
            $script->run($node, TaskVmSetupScript::HostScript, [
                $host->project, $host->network, $host->cidr, $host->pool, TaskVmHost::SourceImage,
                ...($host->zfsDataset === null ? [] : [$host->zfsDataset]),
            ]);
        } catch (ResourceOperationException $exception) {
            $this->error("[{$exception->errorCode}] {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Node [{$node->name}] is ready for task VMs on bridge [{$host->network}]. Build its base image with task-vms:build-image.");

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
