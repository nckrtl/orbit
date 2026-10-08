<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\GatewayReleases;

use InvalidArgumentException;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\GatewayReleases\GatewayReleaseSmokeResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Runs `bin/gateway-smoke` of the current release against the live Gateway, for the current commit
 * unless `commit` names one. The Gateway answers when smoke ends, within 10 minutes, with `passed` or
 * `failed` and the report. It changes nothing and writes no release record.
 */
final class SmokeGatewayReleaseRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly ?string $commit = null,
        public readonly ?string $since = null,
    ) {
        if ($commit !== null && preg_match('/\A[0-9a-f]{7,40}\z/D', $commit) !== 1) {
            throw new InvalidArgumentException('Name the commit by its hex SHA (7 to 40 characters).');
        }
    }

    public function resolveEndpoint(): string
    {
        return '/api/v1/gateway/release-smoke';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): GatewayReleaseSmokeResponse
    {
        return GatewayReleaseSmokeResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /**
     * Both fields go out, null when unset, so the body stays a JSON object.
     *
     * @return array{commit: string|null, since: string|null}
     */
    protected function defaultBody(): array
    {
        return ['commit' => $this->commit, 'since' => $this->since];
    }
}
