<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Deployments;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Deployments\DeploymentConfigResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;
use SensitiveParameter;

final class UpdateAppInstanceDeploymentConfigRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PUT;

    /** @param list<DeploymentStepInput> $steps */
    public function __construct(
        private readonly int $appInstanceId,
        private readonly string $branch,
        #[SensitiveParameter]
        private readonly array $steps,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->appInstanceId}/deployment-config";
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): DeploymentConfigResponse
    {
        return DeploymentConfigResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }

    /** @return array{branch: string, steps: list<array{name: string, phase: string, command: string, timeout_seconds?: int}>} */
    protected function defaultBody(): array
    {
        return [
            'branch' => $this->branch,
            'steps' => array_map(
                static fn (DeploymentStepInput $step): array => $step->toArray(),
                $this->steps,
            ),
        ];
    }
}
