<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Firewall;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Firewall\FirewallRuleResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class RemoveFirewallRuleRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly int $nodeId,
        private readonly string $name,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/nodes/{$this->nodeId}/firewall-rules/".rawurlencode($this->name);
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): FirewallRuleResponse
    {
        $requestId = $this->successRequestId($response);

        return FirewallRuleResponse::fromGatewayData($this->unwrapData($response), $requestId);
    }
}
