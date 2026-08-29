<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Nodes\RetargetNodeAction;
use App\Data\Nodes\RetargetNodeData;
use App\Domain\Nodes\NodeProvisioningException;
use Illuminate\Console\Command;

final class RetargetNodeCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:node-retarget {name : Existing node name} {host : New public SSH host} {--ssh-port=22 : Public SSH port}';

    #[\Override]
    protected $description = 'Retarget an existing provisioned node to a new public SSH host.';

    public function handle(RetargetNodeAction $action): int
    {
        $name = $this->argument('name');
        $host = $this->argument('host');
        $port = $this->option('ssh-port');

        if (! is_string($name) || ! is_string($host) || ! is_numeric($port) || (int) $port < 1 || (int) $port > 65535) {
            $this->error('Node retarget arguments are invalid.');

            return self::FAILURE;
        }

        try {
            $node = $action->execute(new RetargetNodeData($name, $host, (int) $port));
        } catch (NodeProvisioningException $exception) {
            $this->error("Node retarget failed at step [{$exception->step}] with error [{$exception->errorCode}].");

            return self::FAILURE;
        }

        $this->info("Node [{$node->name}] is {$node->status->value}.");

        return self::SUCCESS;
    }
}
