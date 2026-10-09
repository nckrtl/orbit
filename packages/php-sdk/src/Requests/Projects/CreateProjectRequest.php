<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Projects;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Projects\ProjectResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class CreateProjectRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $slug,
        #[\SensitiveParameter]
        private readonly string $repositoryUrl,
        /** @var list<array{name: string, path: string, web_root: string|null, type: string}> */
        private readonly array $apps,
        private readonly string $type = 'laravel-app',
        private readonly ?string $name = null,
        private readonly ?string $defaultBranch = null,
        private readonly ?string $taskCheck = null,
        private readonly bool $taskCheckProvided = false,
        private readonly ?string $sourceAccess = null,
        private readonly ?bool $taskWorkspaceRouted = null,
        private readonly ?string $taskCompute = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/projects';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ProjectResponse
    {
        $data = $this->unwrapData($response);
        $requestId = $this->successRequestId($response);

        return ProjectResponse::fromGatewayData($data, $requestId);
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
                'source_access' => $this->sourceAccess,
                'default_branch' => $this->defaultBranch,
                'apps' => $this->apps,
                'task_compute' => $this->taskCompute,
                ...($this->taskCheckProvided ? ['task_check' => $this->taskCheck] : []),
                ...($this->taskWorkspaceRouted === null ? [] : ['task_workspace_routed' => $this->taskWorkspaceRouted]),
            ],
            static fn (mixed $value, string $key): bool => $key === 'task_check' || $key === 'task_workspace_routed' || $value !== null,
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
