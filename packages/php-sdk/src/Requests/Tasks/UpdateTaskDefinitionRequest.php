<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tasks;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tasks\TaskDefinitionResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasStringBody;
use SensitiveParameter;

final class UpdateTaskDefinitionRequest extends GatewayRequest implements HasBody
{
    use HasStringBody;

    #[\Override]
    protected Method $method = Method::PUT;

    public function __construct(
        private readonly int $projectId,
        private readonly string $name,
        #[SensitiveParameter]
        private readonly string $definition,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/projects/{$this->projectId}/task-definitions/".rawurlencode($this->name);
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return ['Content-Type' => 'application/json'];
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): TaskDefinitionResponse
    {
        return TaskDefinitionResponse::fromGatewayData($this->unwrapDataKeepingObjects($response), $this->successRequestId($response));
    }

    protected function defaultBody(): string
    {
        return $this->definition;
    }
}
