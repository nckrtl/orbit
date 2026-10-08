<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Projects;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Projects\ProjectResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class UpdateProjectRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PATCH;

    public function __construct(
        private readonly int $projectId,
        private readonly ?string $type = null,
        private readonly ?string $slug = null,
        #[\SensitiveParameter]
        private readonly ?string $repositoryUrl = null,
        private readonly ?string $defaultBranch = null,
        private readonly ?string $root = null,
        private readonly ?string $taskCheck = null,
        private readonly bool $taskCheckProvided = false,
        private readonly ?string $sourceAccess = null,
        private readonly ?bool $taskWorkspaceRouted = null,
        private readonly ?string $taskCompute = null,
        private readonly ?bool $reviewAndMerge = null,
        private readonly ?string $mergeCheck = null,
        private readonly bool $mergeCheckProvided = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/projects/{$this->projectId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ProjectResponse
    {
        return ProjectResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return array_filter(
            [
                'type' => $this->type,
                'slug' => $this->slug,
                'repository_url' => $this->repositoryUrl,
                'source_access' => $this->sourceAccess,
                'default_branch' => $this->defaultBranch,
                'root' => $this->root,
                'task_compute' => $this->taskCompute,
                ...($this->taskCheckProvided ? ['task_check' => $this->taskCheck] : []),
                ...($this->taskWorkspaceRouted === null ? [] : ['task_workspace_routed' => $this->taskWorkspaceRouted]),
                ...($this->reviewAndMerge === null ? [] : ['review_and_merge' => $this->reviewAndMerge]),
                ...($this->mergeCheckProvided ? ['merge_check' => $this->mergeCheck] : []),
            ],
            static fn (mixed $value, string $key): bool => in_array($key, ['task_check', 'task_workspace_routed', 'review_and_merge', 'merge_check'], true) || $value !== null,
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
