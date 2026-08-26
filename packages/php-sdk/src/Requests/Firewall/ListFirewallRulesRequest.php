<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Firewall;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Firewall\FirewallRuleResponse;
use Orbit\Sdk\Responses\Firewall\FirewallRulesResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListFirewallRulesRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $nodeId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/nodes/{$this->nodeId}/firewall-rules";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): FirewallRulesResponse
    {
        $requestId = $this->successRequestId($response);
        $rules = [];

        foreach ($this->unwrapDataList($response) as $data) {
            $rules[] = FirewallRuleResponse::fromGatewayData($data, $requestId);
        }

        return new FirewallRulesResponse($rules, $requestId);
    }
}
