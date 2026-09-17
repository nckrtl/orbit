<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Deployments;

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Deployments\DeploymentStream;
use Orbit\Sdk\Support\GatewayRequestId;
use Saloon\Contracts\Body\HasBody;
use Saloon\Http\Response;
use Saloon\Repositories\Body\JsonBodyRepository;
use Saloon\Traits\Body\HasJsonBody;
use SensitiveParameter;

abstract class DeploymentStreamRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody {
        body as private jsonBody;
    }

    private const int OPERATION_TIMEOUT_SECONDS = 4_500;

    final public function body(): JsonBodyRepository
    {
        return $this->jsonBody()->setJsonFlags(JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);
    }

    final public function hasRequestFailed(#[SensitiveParameter] Response $response): bool
    {
        return $response->clientError() || $response->serverError();
    }

    final public function createDtoFromResponse(#[SensitiveParameter] Response $response): DeploymentStream
    {
        $contentType = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
        $requestId = $this->successRequestId($response);

        if ($contentType !== 'application/x-ndjson' || $requestId === '') {
            $response->close();

            throw new GatewayApiException(
                'Gateway response is not a valid deployment stream.',
                requestId: $requestId === '' ? null : $requestId,
            );
        }

        return new DeploymentStream(
            $response->stream(),
            static function () use ($response): void {
                $response->close();
            },
            $requestId,
            silenceLimitSeconds: self::OPERATION_TIMEOUT_SECONDS,
        );
    }

    /** @return array{Accept: string} */
    protected function defaultHeaders(): array
    {
        return ['Accept' => 'application/x-ndjson'];
    }

    /**
     * read_timeout bounds each individual socket read, not the whole operation, so a silent
     * step keeps polling instead of blocking the process on one indefinite read. This is what
     * lets a Ctrl-C during a silent human stream take effect promptly instead of waiting for
     * the next byte (up to `timeout` later).
     *
     * @return array{stream: true, timeout: int, read_timeout: float}
     */
    protected function defaultConfig(): array
    {
        return ['stream' => true, 'timeout' => self::OPERATION_TIMEOUT_SECONDS, 'read_timeout' => 0.5];
    }

    protected function successRequestId(#[SensitiveParameter] Response $response): string
    {
        return GatewayRequestId::fromTransport($response->header('X-Orbit-Request-Id')) ?? '';
    }
}
