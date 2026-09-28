<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Extensions;

use InvalidArgumentException;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Extensions\ExtensionResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

abstract class SetExtensionRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(public readonly string $extension)
    {
        if (preg_match('/\\A[a-z][a-z0-9-]*\\z/D', $extension) !== 1) {
            throw new InvalidArgumentException('Extension slug is invalid.');
        }
    }

    abstract protected function action(): string;

    public function resolveEndpoint(): string
    {
        return '/api/v1/extensions/'.rawurlencode($this->extension).'/'.$this->action();
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ExtensionResponse
    {
        return ExtensionResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
