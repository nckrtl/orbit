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

final class UpdateInstanceDeployStepRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PATCH;

    public function __construct(
        private readonly int $appInstanceId,
        private readonly string $name,
        private readonly bool $hasCommand = false,
        #[SensitiveParameter]
        private readonly ?string $command = null,
        private readonly bool $hasPhase = false,
        private readonly ?string $phase = null,
        private readonly bool $hasTimeout = false,
        private readonly ?int $timeoutSeconds = null,
        private readonly bool $hasBefore = false,
        private readonly ?string $before = null,
        private readonly bool $hasAfter = false,
        private readonly ?string $after = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->appInstanceId}/deploy-steps/{$this->name}";
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

    /** @return array<string, int|string|null> */
    protected function defaultBody(): array
    {
        $body = [];

        if ($this->hasCommand) {
            $body['command'] = $this->command;
        }

        if ($this->hasPhase) {
            $body['phase'] = $this->phase;
        }

        if ($this->hasTimeout) {
            $body['timeout_seconds'] = $this->timeoutSeconds;
        }

        if ($this->hasBefore) {
            $body['before'] = $this->before;
        }

        if ($this->hasAfter) {
            $body['after'] = $this->after;
        }

        return $body;
    }
}
