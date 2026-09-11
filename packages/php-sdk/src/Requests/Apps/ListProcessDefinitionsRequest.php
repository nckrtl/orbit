<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Apps;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Apps\AppRuntimeDefinitionResponse;
use Orbit\Sdk\Responses\Apps\AppRuntimeDefinitionsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListProcessDefinitionsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(private readonly int $appId) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/apps/{$this->appId}/process-definitions";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): AppRuntimeDefinitionsResponse
    {
        $requestId = $this->successRequestId($response);
        $definitions = array_map(
            static fn (array $data): AppRuntimeDefinitionResponse => AppRuntimeDefinitionResponse::fromGatewayData(
                $data,
                $requestId,
            ),
            $this->unwrapDataList($response),
        );

        return new AppRuntimeDefinitionsResponse($definitions, $requestId);
    }
}
