<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Deployments;

use Saloon\Enums\Method;

final class RollbackAppInstanceRequest extends DeploymentStreamRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $appInstanceId,
        private readonly string $release,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->appInstanceId}/rollback";
    }

    /** @return array{release: string} */
    protected function defaultBody(): array
    {
        return ['release' => $this->release];
    }
}
