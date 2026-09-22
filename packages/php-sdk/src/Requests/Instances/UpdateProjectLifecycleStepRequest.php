<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Instances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Instances\LifecycleStepResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;
use SensitiveParameter;

final class UpdateProjectLifecycleStepRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PATCH;

    public function __construct(
        private readonly int $projectId,
        private readonly string $collection,
        private readonly string $name,
        #[SensitiveParameter]
        private readonly ?string $command = null,
        private readonly ?int $timeoutSeconds = null,
        private readonly ?string $before = null,
        private readonly ?string $after = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/projects/{$this->projectId}/{$this->collection}/{$this->name}";
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): LifecycleStepResponse
    {
        return LifecycleStepResponse::fromData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array<string, int|string> */
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

        return $body;
    }
}
