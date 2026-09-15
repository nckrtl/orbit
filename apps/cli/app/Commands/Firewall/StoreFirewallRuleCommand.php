<?php

declare(strict_types=1);

namespace App\Commands\Firewall;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ProgressState;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Firewall\FirewallRuleResponse;

abstract class StoreFirewallRuleCommand extends FirewallCommand
{
    abstract protected function request(
        int $nodeId,
        string $name,
        ?string $source,
        ?string $protocol,
        string $port,
    ): GatewayRequest;

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

        $protocol = $this->protocol();

        if ($protocol === false) {
            return self::FAILURE;
        }

        $port = $this->port();

        if ($port === null) {
            return self::FAILURE;
        }

        $source = $this->source();

        if ($source === false) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $rule = $this->sendWithProgress(
            $connector,
            $this->request($nodeId, $name, $source, $protocol, $port),
            FirewallRuleResponse::class,
            ['Apply firewall rule', 'Applying firewall rule', 'Applied firewall rule'],
            static function (object $response): ProgressState {
                if (! $response instanceof FirewallRuleResponse || $response->backendStatus !== 'active') {
                    throw new GatewayApiException('Gateway response does not confirm the firewall operation.',
                        'gateway.invalid_response', requestId: $response instanceof FirewallRuleResponse ? $response->requestId : null);
                }

                return ProgressState::Success;
            },
        );

        if (! $rule instanceof FirewallRuleResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($rule->toArray());

            return self::SUCCESS;
        }

        $this->writeHumanMessage("Firewall rule [{$rule->name}] is active.");

        $this->writeHumanMessage("Request ID: {$rule->requestId}");

        return self::SUCCESS;
    }
}
