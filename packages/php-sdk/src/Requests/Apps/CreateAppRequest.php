<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Apps;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Apps\AppResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class CreateAppRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    /**
     * @param  array<array-key, mixed>|null  $defaults
     */
    public function __construct(
        private readonly string $slug,
        #[\SensitiveParameter]
        private readonly string $repositoryUrl,
        private readonly string $root,
        private readonly string $type = 'laravel-app',
        private readonly ?string $name = null,
        private readonly ?string $defaultBranch = null,
        #[\SensitiveParameter]
        private readonly ?array $defaults = null,
        private readonly ?string $taskCheck = null,
        private readonly bool $taskCheckProvided = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/projects';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): AppResponse
    {
        $data = $this->unwrapData($response);
        $requestId = $this->successRequestId($response);

        return AppResponse::fromGatewayData($data, $requestId);
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return array_filter(
            [
                'name' => $this->name,
                'slug' => $this->slug,
                'type' => $this->type,
                'repository_url' => $this->repositoryUrl,
                'default_branch' => $this->defaultBranch,
                'root' => $this->root,
                'defaults' => $this->defaults,
                ...($this->taskCheckProvided ? ['task_check' => $this->taskCheck] : []),
            ],
            static fn (mixed $value, string $key): bool => $key === 'task_check' || $value !== null,
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
