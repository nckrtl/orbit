<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\GatewayReleases;

use InvalidArgumentException;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\GatewayReleases\GatewayReleaseResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

/** Shows one release record by its id, or the newest record of a commit named by a hex SHA of 7 to 40 characters. */
final class ShowGatewayReleaseRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(public readonly string $release)
    {
        if (preg_match('/\A(?:[1-9][0-9]{0,5}|[0-9a-f]{7,40})\z/D', $release) !== 1) {
            throw new InvalidArgumentException('A release is a record id or a hex SHA of 7 to 40 characters.');
        }
    }

    public function resolveEndpoint(): string
    {
        return '/api/v1/gateway/releases/'.$this->release;
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): GatewayReleaseResponse
    {
        return GatewayReleaseResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
