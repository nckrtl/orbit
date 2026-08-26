<?php

declare(strict_types=1);

namespace App\Commands\Firewall;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Firewall\ListFirewallRulesRequest;
use Orbit\Sdk\Responses\Firewall\FirewallRulesResponse;

final class ListFirewallRulesCommand extends FirewallCommand
{
    #[\Override]
    protected $signature = 'firewall:list
        {--node= : Numeric target node ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List named firewall rules for one node.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $nodeId = $this->nodeId();

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->send(
            $connector,
            new ListFirewallRulesRequest($nodeId),
            FirewallRulesResponse::class,
        );

        if (! $response instanceof FirewallRulesResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($response->rules as $rule) {
            $rows[] = [
                $rule->name,
                $rule->action,
                $rule->source,
                $rule->port,
                $rule->protocol,
                $rule->status,
            ];
        }

        $this->table(['Name', 'Action', 'Source', 'Port', 'Protocol', 'Status'], $rows);
        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
