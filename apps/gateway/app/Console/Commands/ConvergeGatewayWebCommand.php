<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Gateway\ConvergeGatewayWebAction;
use App\Domain\Nodes\NodeProvisioningException;
use Illuminate\Console\Command;

final class ConvergeGatewayWebCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:gateway-web';

    #[\Override]
    protected $description = 'Render and publish the Gateway site, certificate, and web directory without bootstrapping.';

    public function handle(ConvergeGatewayWebAction $action): int
    {
        try {
            $node = $action->execute();
        } catch (NodeProvisioningException $exception) {
            $this->error(
                "Gateway web convergence failed at step [{$exception->step}] with error [{$exception->errorCode}].",
            );

            return self::FAILURE;
        }

        $this->info("Gateway [{$node->name}] site is published.");

        return self::SUCCESS;
    }
}
