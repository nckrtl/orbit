<?php

declare(strict_types=1);

namespace App\Commands\Firewall;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ProgressState;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Requests\Firewall\ListFirewallRulesRequest;
use Orbit\Sdk\Requests\Firewall\RemoveFirewallRuleRequest;
use Orbit\Sdk\Responses\Firewall\FirewallRuleResponse;
use Orbit\Sdk\Responses\Firewall\FirewallRulesResponse;

final class RemoveFirewallRuleCommand extends FirewallCommand
{
    #[\Override]
    protected $signature = 'firewall:remove
        {name : Stable firewall rule name}
        {--node= : Numeric target node ID}
        {--yes : Confirm firewall rule removal}
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

        if ($this->option('yes') !== true) {
            $rules = $this->sendWithProgress($connector, new ListFirewallRulesRequest($nodeId), FirewallRulesResponse::class,
                ['Resolve firewall rule', 'Loading firewall rules', 'Loaded firewall rules']);

            if (! $rules instanceof FirewallRulesResponse) {
                return self::FAILURE;
            }

            if (! array_any($rules->rules, static fn (FirewallRuleResponse $rule): bool => $rule->name === $name)) {
                return $this->renderGatewayFailure('http.404', 'Resource not found.', $rules->requestId);
            }
        }

        if (! $this->confirmAction("Remove firewall rule [{$name}] from Node #{$nodeId}?", 'Firewall rule removal cancelled.')) {
            return self::FAILURE;
        }

        $rule = $this->sendWithProgress(
            $connector,
            new RemoveFirewallRuleRequest($nodeId, $name),
            FirewallRuleResponse::class,
            ['Remove firewall rule', 'Removing firewall rule', 'Removed firewall rule'],
            static function (object $response): ProgressState {
                if (! $response instanceof FirewallRuleResponse || $response->backendStatus !== 'absent') {
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

        $this->writeHumanMessage("Firewall rule [{$rule->name}] removed.");

        $this->writeHumanMessage("Request ID: {$rule->requestId}");

        return self::SUCCESS;
    }
}
