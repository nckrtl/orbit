<?php

declare(strict_types=1);

namespace Orbit\Sdk;

use Closure;
use InvalidArgumentException;
use LogicException;
use Orbit\Sdk\Requests\Gateway\FetchRootCaCertificateRequest;
use Orbit\Sdk\Responses\Gateway\RootCaCertificateResponse;
use Orbit\Sdk\Support\GatewayOrigin;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;
use UnexpectedValueException;

final class GatewayRootCaClient
{
    /** @var Closure(): string */
    private Closure $requestIdResolver;

    /** @param null|Closure(): string $requestIdResolver */
    public function __construct(#[SensitiveParameter] ?Closure $requestIdResolver = null)
    {
        $this->requestIdResolver = $requestIdResolver ?? GatewayRequestId::generate(...);
    }

    /** @return array{type: class-string<self>} */
    public function __debugInfo(): array
    {
        return ['type' => self::class];
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        throw new LogicException('Orbit gateway root CA clients cannot be serialized.');
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(#[SensitiveParameter] array $data): void
    {
        throw new LogicException('Orbit gateway root CA clients cannot be unserialized.');
    }

    public function fetch(#[SensitiveParameter] string $gatewayUrl): RootCaCertificateResponse
    {
        $baseUrl = $this->safeOrigin($gatewayUrl);
        $requestIdResolver = $this->requestIdResolver;
        $this->discardRequestIdResolver();
        $requestId = GatewayRequestId::resolve($requestIdResolver);

        $connector = new GatewayConnector(
            baseUrl: $baseUrl,
            timeout: 10,
            requestIdResolver: static fn (): string => $requestId,
        );
        $connector->config()->add('verify', false);
        /** @mago-expect analysis:mixed-assignment Saloon returns DTOs through a mixed boundary. */
        $response = $connector->send(new FetchRootCaCertificateRequest)->dto();

        if (! $response instanceof RootCaCertificateResponse) {
            throw new UnexpectedValueException('Gateway root CA response is invalid.');
        }

        return $response;
    }

    private function discardRequestIdResolver(): void
    {
        $this->requestIdResolver = static function (): never {
            throw new UnexpectedValueException('Gateway request ID resolver failed.');
        };
    }

    private function safeOrigin(#[SensitiveParameter] string $gatewayUrl): string
    {
        $safeOrigin = GatewayOrigin::fromTransport($gatewayUrl);

        if ($safeOrigin === null) {
            throw new InvalidArgumentException('Gateway root CA bootstrap requires a safe HTTPS origin.');
        }

        return $safeOrigin;
    }
}
