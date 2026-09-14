<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Deployments;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Deployments\DeploymentStepResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;
use SensitiveParameter;

final class CreateInstanceDeployStepRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $appInstanceId,
        private readonly string $name,
        #[SensitiveParameter]
        private readonly string $command,
        private readonly ?string $phase = null,
        private readonly ?int $timeoutSeconds = null,
        private readonly ?string $before = null,
        private readonly ?string $after = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->appInstanceId}/deploy-steps";
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): DeploymentStepResponse
    {
        $data = $this->unwrapData($response);

        return new DeploymentStepResponse(
            is_string($data['name'] ?? null) ? $data['name'] : '',
            is_string($data['phase'] ?? null) ? $data['phase'] : '',
            is_string($data['command'] ?? null) ? $data['command'] : '',
            is_int($data['timeout_seconds'] ?? null) ? $data['timeout_seconds'] : 0,
            $this->successRequestId($response),
        );
    }

    /** @return array<string, int|string> */
    protected function defaultBody(): array
    {
        $body = [
            'name' => $this->name,
            'command' => $this->command,
        ];

        if ($this->phase !== null) {
            $body['phase'] = $this->phase;
        }

        if ($this->timeoutSeconds !== null) {
            $body['timeout_seconds'] = $this->timeoutSeconds;
        }

        if ($this->before !== null) {
            $body['before'] = $this->before;
        }

        if ($this->after !== null) {
            $body['after'] = $this->after;
        }

        return $body;
    }
}
