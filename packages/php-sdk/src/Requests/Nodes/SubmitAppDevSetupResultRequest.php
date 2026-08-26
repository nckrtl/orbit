<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Nodes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Nodes\NodeRoleResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;
use SensitiveParameter;

final class SubmitAppDevSetupResultRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $exitCode,
        #[SensitiveParameter]
        private readonly string $diagnostics,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/node-role-setups/app-dev/result';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): NodeRoleResponse
    {
        return NodeRoleResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }

    /** @return array{exit_code: int, diagnostics: string} */
    protected function defaultBody(): array
    {
        return [
            'exit_code' => $this->exitCode,
            'diagnostics' => $this->diagnostics,
        ];
    }
}
