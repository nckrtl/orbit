<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Deployments;

use Saloon\Enums\Method;

final class DeployInstanceRequest extends DeploymentStreamRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(private readonly int $instanceId) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->instanceId}/deploy";
    }

    /** @return array<never, never> */
    protected function defaultBody(): array
    {
        return [];
    }
}
