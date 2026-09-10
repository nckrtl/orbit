<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Environment;

use JsonException;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Environment\EnvironmentOperationResponse;
use Orbit\Sdk\Responses\Environment\EnvironmentTransportResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Http\Response;
use Saloon\Repositories\Body\JsonBodyRepository;
use Saloon\Traits\Body\HasJsonBody;
use SensitiveParameter;
use Throwable;

abstract class EnvironmentRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody {
        body as private jsonBody;
    }

    final public function body(): JsonBodyRepository
    {
        return $this->jsonBody()->setJsonFlags(JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);
    }

    /** @return class-string<EnvironmentTransportResponse> */
    final public function resolveResponseClass(): ?string
    {
        return EnvironmentTransportResponse::class;
    }

    final public function createDtoFromResponse(
        #[SensitiveParameter]
        Response $response,
    ): EnvironmentOperationResponse {
        return EnvironmentOperationResponse::fromGatewayData(
            $this->environmentData($response),
            $this->expectedOperation(),
            $this->successRequestId($response),
        );
    }

    final public function getRequestException(
        #[SensitiveParameter]
        Response $response,
        #[SensitiveParameter]
        ?Throwable $senderException,
    ): ?Throwable {
        return new GatewayApiException(
            "Gateway environment operation failed with HTTP status {$response->status()}.",
            errorCode: $this->errorCode($response),
            requestId: $response->getPsrResponse()->getHeaderLine('X-Orbit-Request-Id'),
        );
    }

    abstract protected function expectedOperation(): string;

    /**
     * @mago-expect analysis:mixed-assignment JSON values remain mixed until the environment DTO validates them.
     *
     * @return array<array-key, mixed>
     */
    private function environmentData(#[SensitiveParameter] Response $response): array
    {
        try {
            $body = json_decode(
                json: $response->body(),
                associative: true,
                depth: 16,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            throw new GatewayApiException('Gateway response contains invalid environment operation data.');
        }

        if (! is_array($body) || ! is_array($body['data'] ?? null)) {
            throw new GatewayApiException(
                'Gateway response contains invalid environment operation data.',
                requestId: $this->successRequestId($response),
            );
        }

        return $body['data'];
    }

    /** @mago-expect analysis:mixed-assignment Gateway errors begin at an untrusted JSON boundary. */
    private function errorCode(#[SensitiveParameter] Response $response): ?string
    {
        try {
            $body = json_decode(
                json: $response->body(),
                associative: true,
                depth: 16,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            return null;
        }

        if (! is_array($body) || ! is_array($body['error'] ?? null)) {
            return null;
        }

        $errorCode = $body['error']['code'] ?? null;

        return is_string($errorCode) ? $errorCode : null;
    }
}
