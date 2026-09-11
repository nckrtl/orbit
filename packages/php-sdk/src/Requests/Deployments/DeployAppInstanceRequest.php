<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Deployments;

use Saloon\Enums\Method;

final class DeployAppInstanceRequest extends DeploymentStreamRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(private readonly int $appInstanceId) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->appInstanceId}/deploy";
    }

    /** @return array<never, never> */
    protected function defaultBody(): array
    {
        return [];
    }
}
