<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Nodes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Nodes\AppsSettings;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Orbit\Sdk\Responses\Nodes\NodeSettings;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class UpdateNodeSettingsRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PATCH;

    public function __construct(
        private readonly int $nodeId,
        private readonly bool $hasApps = false,
        private readonly ?AppsSettings $apps = null,
    ) {}

    public static function fromSettings(int $nodeId, NodeSettings $settings): self
    {
        return new self(
            nodeId: $nodeId,
            hasApps: true,
            apps: $settings->apps,
        );
    }

    public function resolveEndpoint(): string
    {
        return "/api/v1/nodes/{$this->nodeId}/settings";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): NodeResponse
    {
        $data = $this->unwrapData($response);
        $requestId = $this->successRequestId($response);

        return NodeResponse::fromGatewayData($data, $requestId);
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        $body = [];

        if ($this->hasApps) {
            $body['apps'] = $this->apps?->toArray();
        }

        return $body;
    }
}
