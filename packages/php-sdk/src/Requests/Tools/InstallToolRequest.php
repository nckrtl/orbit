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
        private readonly string $manager,
        private readonly string $package,
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

    /** @return array<string, int|string> */
    protected function defaultBody(): array
    {
        return [
            'node_id' => $this->nodeId,
            'manager' => $this->manager,
            'package' => $this->package,
            ...($this->versionConstraint === null ? [] : ['version_constraint' => $this->versionConstraint]),
        ];
    }
}
