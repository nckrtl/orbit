<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\GatewayReleases;

use InvalidArgumentException;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\GatewayReleases\GatewayReleaseResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Queues a rollback to a retained release, named by its 12-digit id. `force` switches the code
 * across a migration the target does not have. The Gateway answers 202 with the queued record.
 */
final class RollbackGatewayReleaseRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $release,
        public readonly bool $force = false,
    ) {
        if (preg_match('/\A[0-9a-f]{12}\z/D', $release) !== 1) {
            throw new InvalidArgumentException('A release id is the first 12 hex digits of its commit.');
        }
    }

    public function resolveEndpoint(): string
    {
        return '/api/v1/gateway/releases/'.$this->release.'/rollback';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): GatewayReleaseResponse
    {
        return GatewayReleaseResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array{force: bool} */
    protected function defaultBody(): array
    {
        return ['force' => $this->force];
    }
}
