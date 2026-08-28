<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tools;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tools\ToolResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class InstallToolRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $nodeId,
        #[\SensitiveParameter]
        private readonly string $manager,
        #[\SensitiveParameter]
        private readonly string $package,
        #[\SensitiveParameter]
        private readonly ?string $versionConstraint = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/tools';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ToolResponse
    {
        return ToolResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    protected function defaultBody(): array
    {
        return array_filter(
            [
                'node_id' => $this->nodeId,
                'manager' => $this->manager,
                'package' => $this->package,
                'version_constraint' => $this->versionConstraint,
            ],
            static fn (mixed $value): bool => $value !== null,
        );
    }
}
