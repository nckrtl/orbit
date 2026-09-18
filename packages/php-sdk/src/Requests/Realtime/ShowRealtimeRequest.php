<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Realtime;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Realtime\RealtimeResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

/**
 * TEMPORARY: this class is a local stand-in for the one PR #490 (branch `nck/gateway-events`)
 * adds under the same name and namespace. It exists so `orbit top` (this PR) can code against
 * `Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest` before that PR merges. Drop this file (and
 * `Responses\Realtime\RealtimeResponse`, plus their tests) once #490 lands and rebase onto it;
 * do not keep both.
 *
 * Asks the Gateway for its realtime endpoint (`url`, `key`, `channel`). A 404 means realtime is
 * not configured on this Gateway; the request tolerates that status instead of throwing, so
 * `createDtoFromResponse()` can return `RealtimeResponse::notConfigured()`.
 */
final class ShowRealtimeRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/realtime';
    }

    #[\Override]
    public function hasRequestFailed(#[SensitiveParameter] Response $response): ?bool
    {
        if ($response->status() === 404) {
            return false;
        }

        return parent::hasRequestFailed($response);
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): RealtimeResponse
    {
        $requestId = $this->successRequestId($response);

        if ($response->status() === 404) {
            return RealtimeResponse::notConfigured($requestId);
        }

        return RealtimeResponse::fromGatewayData($this->unwrapData($response), $requestId);
    }
}
