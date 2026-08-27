<?php

declare(strict_types=1);

namespace Orbit\Sdk;

use Closure;
use InvalidArgumentException;
use LogicException;
use Orbit\Sdk\Support\GatewayOrigin;
use Orbit\Sdk\Support\GatewayRequestId;
use Saloon\Enums\PipeOrder;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;
use Saloon\Traits\Plugins\AlwaysThrowOnErrors;
use SensitiveParameter;
use UnexpectedValueException;

/** @mago-expect lint:too-many-methods Central connector boundaries override unsafe inherited diagnostics. */
final class GatewayConnector extends Connector
{
    use AlwaysThrowOnErrors;

    private readonly string $baseUrl;

    /** @var Closure(): string */
    private Closure $requestIdResolver;

    /** @param null|Closure(): string $requestIdResolver */
    public function __construct(
        #[SensitiveParameter]
        string $baseUrl,
        #[SensitiveParameter]
        private readonly ?string $caPemPath = null,
        private readonly int $timeout = 900,
        private readonly string $clientName = 'cli',
        #[SensitiveParameter]
        ?Closure $requestIdResolver = null,
    ) {
        $safeOrigin = GatewayOrigin::fromTransport($baseUrl);

        if ($safeOrigin === null) {
            throw new InvalidArgumentException('Gateway connector requires a safe HTTPS origin.');
        }

        $this->baseUrl = $safeOrigin;
        $this->requestIdResolver = $requestIdResolver ?? GatewayRequestId::generate(...);
    }

    public function resolveBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /** @return array{type: class-string<self>} */
    public function __debugInfo(): array
    {
        return ['type' => self::class];
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        throw new LogicException('Orbit gateway connectors cannot be serialized.');
    }

    /** @param array<array-key, mixed> $data */
    public function __unserialize(#[SensitiveParameter] array $data): void
    {
        throw new LogicException('Orbit gateway connectors cannot be unserialized.');
    }

    public function debug(bool $die = false): static
    {
        throw new LogicException('Orbit SDK raw transport debugging is disabled.');
    }

    public function debugRequest(
        #[SensitiveParameter]
        ?callable $onRequest = null,
        bool $die = false,
    ): static {
        throw new LogicException('Orbit SDK raw transport debugging is disabled.');
    }

    public function debugResponse(
        #[SensitiveParameter]
        ?callable $onResponse = null,
        bool $die = false,
    ): static {
        throw new LogicException('Orbit SDK raw transport debugging is disabled.');
    }

    public function boot(#[SensitiveParameter] PendingRequest $pendingRequest): void
    {
        try {
            $requestId = GatewayRequestId::resolve($this->requestIdResolver);
        } catch (UnexpectedValueException $exception) {
            $this->discardRequestIdResolver();

            throw $exception;
        }

        $pendingRequest->headers()->add('X-Orbit-Request-Id', $requestId);
    }

    private function discardRequestIdResolver(): void
    {
        $this->requestIdResolver = static function (): never {
            throw new UnexpectedValueException('Gateway request ID resolver failed.');
        };
    }

    public static function bootAlwaysThrowOnErrors(#[SensitiveParameter] PendingRequest $pendingRequest): void
    {
        $pendingRequest->middleware()->onResponse(
            callable: static fn (#[SensitiveParameter] Response $response): Response => $response->throw(),
            name: 'alwaysThrowOnErrors',
            order: PipeOrder::LAST,
        );
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'X-Orbit-Client' => $this->clientName,
        ];
    }

    /** @return array<string, mixed> */
    protected function defaultConfig(): array
    {
        $config = [
            'allow_redirects' => false,
            'connect_timeout' => 10,
            'timeout' => $this->timeout,
        ];

        if ($this->caPemPath !== null) {
            $config['verify'] = $this->caPemPath;
        }

        return $config;
    }
}
