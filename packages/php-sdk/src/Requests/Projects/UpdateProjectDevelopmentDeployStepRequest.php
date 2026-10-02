<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Projects;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Projects\DevelopmentDeployStepResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;
use SensitiveParameter;

final class UpdateProjectDevelopmentDeployStepRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PATCH;

    public function __construct(
        private readonly int $projectId,
        private readonly string $name,
        #[SensitiveParameter]
        private readonly ?string $command = null,
        private readonly ?int $timeoutSeconds = null,
        private readonly ?string $before = null,
        private readonly ?string $after = null,
        private readonly ?bool $required = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/projects/{$this->projectId}/dev-deploy-steps/{$this->name}";
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): DevelopmentDeployStepResponse
    {
        return DevelopmentDeployStepResponse::fromData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array<string, int|string|bool> */
    protected function defaultBody(): array
    {
        $body = [];

        if ($this->command !== null) {
            $body['command'] = $this->command;
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

        if ($this->required !== null) {
            $body['required'] = $this->required;
        }

        return $body;
    }
}
