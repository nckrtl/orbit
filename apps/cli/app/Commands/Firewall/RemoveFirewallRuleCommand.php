<?php

declare(strict_types=1);

namespace App\Commands\Firewall;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Firewall\RemoveFirewallRuleRequest;
use Orbit\Sdk\Responses\Firewall\FirewallRuleResponse;

final class RemoveFirewallRuleCommand extends FirewallCommand
{
    #[\Override]
    protected $signature = 'firewall:remove
        {name : Stable firewall rule name}
        {--node= : Numeric target node ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove one named UFW rule through the gateway.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $nodeId = $this->nodeId();

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $name = $this->ruleName();

        if ($name === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $rule = $this->send(
            $connector,
            new RemoveFirewallRuleRequest($nodeId, $name),
            FirewallRuleResponse::class,
        );

        if (! $rule instanceof FirewallRuleResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($rule->toArray());

            return self::SUCCESS;
        }

        $this->info("Firewall rule [{$rule->name}] removed.");

        $this->line("Request ID: {$rule->requestId}");

        return self::SUCCESS;
    }
}
