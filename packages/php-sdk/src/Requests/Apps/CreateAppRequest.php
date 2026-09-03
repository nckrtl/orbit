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
     * @param array<array-key, mixed>|null $defaults
     *
     * @mago-expect lint:excessive-parameter-list The request transports the complete bounded App creation contract.
     */
    public function __construct(
        private readonly string $slug,
        #[\SensitiveParameter]
        private readonly string $repositoryUrl,
        private readonly string $root,
        private readonly ?string $name = null,
        private readonly ?string $mainBranch = null,
        #[\SensitiveParameter]
        private readonly ?array $defaults = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/apps';
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
                'repository_url' => $this->repositoryUrl,
                'main_branch' => $this->mainBranch,
                'root' => $this->root,
                'defaults' => $this->defaults,
            ],
            static fn (mixed $value): bool => $value !== null,
        );
    }
}
