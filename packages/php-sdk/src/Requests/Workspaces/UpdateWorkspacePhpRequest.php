<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Workspaces;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Workspaces\WorkspaceResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class UpdateWorkspacePhpRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::PATCH;

    public function __construct(
        private readonly int $workspaceId,
        private readonly string $phpVersion,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/workspaces/{$this->workspaceId}/php";
    }

    public function createDtoFromResponse(Response $response): WorkspaceResponse
    {
        $data = $this->unwrapData($response);
        $meta = $this->unwrapMeta($response);
        $requestId = is_string($meta['request_id'] ?? null) ? $meta['request_id'] : '';

        return WorkspaceResponse::fromGatewayData($data, $requestId);
    }

    /** @return array{php_version: string} */
    protected function defaultBody(): array
    {
        return ['php_version' => $this->phpVersion];
    }
}
