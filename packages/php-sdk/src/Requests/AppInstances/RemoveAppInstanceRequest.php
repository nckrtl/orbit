<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\AppInstances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RemoveAppInstanceRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly int $appInstanceId,
        private readonly ?bool $discardSource = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->appInstanceId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): AppInstanceResponse
    {
        return AppInstanceResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }

    /** @return array{discard_source?: bool} */
    protected function defaultBody(): array
    {
        return $this->discardSource === null ? [] : ['discard_source' => $this->discardSource];
    }
}
