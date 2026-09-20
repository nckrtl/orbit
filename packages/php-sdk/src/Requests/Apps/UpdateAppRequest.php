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
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/projects/{$this->appId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): AppResponse
    {
        return AppResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array<string, string> */
    protected function defaultBody(): array
    {
        return array_filter(
            [
                'type' => $this->type,
                'slug' => $this->slug,
                'repository_url' => $this->repositoryUrl,
                'default_branch' => $this->defaultBranch,
                'root' => $this->root,
            ],
            static fn (?string $value): bool => $value !== null,
        );
    }
}
