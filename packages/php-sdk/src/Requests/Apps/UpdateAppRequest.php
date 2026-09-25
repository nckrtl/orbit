<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Apps;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Apps\AppResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class UpdateAppRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PATCH;

    public function __construct(
        private readonly int $appId,
        private readonly ?string $type = null,
        private readonly ?string $slug = null,
        #[\SensitiveParameter]
        private readonly ?string $repositoryUrl = null,
        private readonly ?string $defaultBranch = null,
        private readonly ?string $root = null,
        #[\SensitiveParameter]
        private readonly ?string $taskBaselineCheck = null,
        private readonly bool $taskBaselineCheckProvided = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/projects/{$this->appId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): AppResponse
    {
        return AppResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return array_filter(
            [
                'type' => $this->type,
                'slug' => $this->slug,
                'repository_url' => $this->repositoryUrl,
                'default_branch' => $this->defaultBranch,
                'root' => $this->root,
                ...($this->taskBaselineCheckProvided ? ['task_baseline_check' => $this->taskBaselineCheck] : []),
            ],
            static fn (mixed $value, string $key): bool => $key === 'task_baseline_check' || $value !== null,
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
