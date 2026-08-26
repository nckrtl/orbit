<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Nodes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Nodes\AppDevSetupScriptResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;
use SensitiveParameter;

final class FetchAppDevSetupScriptRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $platform,
        private readonly string $architecture,
        #[SensitiveParameter]
        private readonly string $username,
        #[SensitiveParameter]
        private readonly string $homeDirectory,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/node-role-setups/app-dev/script';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): AppDevSetupScriptResponse
    {
        return AppDevSetupScriptResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }

    /** @return array{platform: string, architecture: string, username: string, home_directory: string} */
    protected function defaultBody(): array
    {
        return [
            'platform' => $this->platform,
            'architecture' => $this->architecture,
            'username' => $this->username,
            'home_directory' => $this->homeDirectory,
        ];
    }
}
