<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\GitHub;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\GitHub\GitHubAppInstallResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasStringBody;
use SensitiveParameter;

final class InstallGitHubAppRequest extends GatewayRequest implements HasBody
{
    use HasStringBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly ?string $name = null,
        private readonly ?string $owner = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/github/app/install';
    }

    protected function defaultHeaders(): array
    {
        return ['Content-Type' => 'application/json'];
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): GitHubAppInstallResponse
    {
        return GitHubAppInstallResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }

    protected function defaultBody(): string
    {
        $body = array_filter(
            ['name' => $this->name, 'owner' => $this->owner],
            static fn (?string $value): bool => $value !== null,
        );

        return json_encode($body === [] ? (object) [] : $body, JSON_THROW_ON_ERROR);
    }
}
