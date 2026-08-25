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

    protected Method $method = Method::POST;

    /** @param array<string, mixed>|null $defaults */
    public function __construct(
        private readonly string $slug,
        private readonly string $repositoryUrl,
        private readonly ?string $name = null,
        private readonly ?array $defaults = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/apps';
    }

    public function createDtoFromResponse(Response $response): AppResponse
    {
        $data = $this->unwrapData($response);
        $meta = $this->unwrapMeta($response);
        $requestId = is_string($meta['request_id'] ?? null) ? $meta['request_id'] : '';

        return AppResponse::fromGatewayData($data, $requestId);
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return [
            'name' => $this->name ?? $this->slug,
            'slug' => $this->slug,
            'repository_url' => $this->repositoryUrl,
            'defaults' => $this->defaults,
        ];
    }
}
