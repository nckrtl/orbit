<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Workspaces;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Workspaces\WorkspaceResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class CreateWorkspaceRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $instanceId,
        private readonly string $name,
        private readonly ?string $branch = null,
        private readonly ?string $checkoutPath = null,
        private readonly ?string $phpVersion = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/workspaces';
    }

    public function createDtoFromResponse(Response $response): WorkspaceResponse
    {
        $data = $this->unwrapData($response);
        $meta = $this->unwrapMeta($response);
        $requestId = is_string($meta['request_id'] ?? null) ? $meta['request_id'] : '';

        return WorkspaceResponse::fromGatewayData($data, $requestId);
    }

    /** @return array<string, int|string|null> */
    protected function defaultBody(): array
    {
        return [
            'instance_id' => $this->instanceId,
            'name' => $this->name,
            'branch' => $this->branch ?? $this->name,
            'checkout_path' => $this->checkoutPath,
            'php_version' => $this->phpVersion,
        ];
    }
}
