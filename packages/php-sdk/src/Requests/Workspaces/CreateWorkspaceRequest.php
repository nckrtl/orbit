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

    #[\Override]
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

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): WorkspaceResponse
    {
        $data = $this->unwrapData($response);
        $requestId = $this->successRequestId($response);

        return WorkspaceResponse::fromGatewayData($data, $requestId);
    }

    /** @return array<string, int|string|null> */
    protected function defaultBody(): array
    {
        return array_filter(
            [
                'instance_id' => $this->instanceId,
                'name' => $this->name,
                'branch' => $this->branch,
                'checkout_path' => $this->checkoutPath,
                'php_version' => $this->phpVersion,
            ],
            static fn (int|string|null $value): bool => $value !== null,
        );
    }
}
