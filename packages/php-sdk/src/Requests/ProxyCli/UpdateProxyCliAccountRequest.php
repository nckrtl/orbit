<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\ProxyCli;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\ProxyCli\ProxyCliAccountResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;
use SensitiveParameter;

final class UpdateProxyCliAccountRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PATCH;

    public function __construct(
        private readonly string $account,
        private readonly bool $disabled,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/proxycli/accounts/'.rawurlencode($this->account);
    }

    /** @return array{disabled: bool} */
    protected function defaultBody(): array
    {
        return ['disabled' => $this->disabled];
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): ProxyCliAccountResponse
    {
        return ProxyCliAccountResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }
}
