<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\AppInstances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RegisterAppInstanceRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $app,
        private readonly string $checkoutPath,
        private readonly ?string $name = null,
        private readonly ?string $root = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/instances/register';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): AppInstanceResponse
    {
        return AppInstanceResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }

    /** @return array{app: string, checkout_path: string, name?: string, root?: string} */
    protected function defaultBody(): array
    {
        $body = [
            'app' => $this->app,
            'checkout_path' => $this->checkoutPath,
        ];

        if ($this->name !== null) {
            $body['name'] = $this->name;
        }

        if ($this->root !== null) {
            $body['root'] = $this->root;
        }

        return $body;
    }
}
