<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Nodes\RepairNodeDnsAction;
use App\Domain\Nodes\NodeProvisioningException;
use Illuminate\Console\Command;

final class RepairNodeDnsCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:node-dns-repair {name : Existing managed peer name}';

    #[\Override]
    protected $description = 'Repair one managed peer DNS configuration without reprovisioning it.';

    public function handle(RepairNodeDnsAction $action): int
    {
        $name = $this->argument('name');

        if ($name === '') {
            $this->error('Node DNS repair arguments are invalid.');

            return self::FAILURE;
        }

        try {
            $node = $action->execute($name);
        } catch (NodeProvisioningException $exception) {
            $this->error(
                "Node DNS repair failed at step [{$exception->step}] with error [{$exception->errorCode}].",
            );

            return self::FAILURE;
        }

        $this->info("Node [{$node->name}] DNS is aligned with its managed resolver policy.");

        return self::SUCCESS;
    }
}
