<?php

declare(strict_types=1);

namespace Orbit\Sdk;

use Closure;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;
use Saloon\Traits\Plugins\AlwaysThrowOnErrors;

final class GatewayConnector extends Connector
{
    use AlwaysThrowOnErrors;

    /** @param null|Closure(): string $requestIdResolver */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string|bool|null $caPemPath = null,
        private readonly int $timeout = 900,
        private readonly string $clientName = 'cli',
        private readonly ?Closure $requestIdResolver = null,
    ) {}

    public function resolveBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function boot(PendingRequest $pendingRequest): void
    {
        $requestId = $this->requestIdResolver instanceof Closure
            ? ($this->requestIdResolver)()
            : null;

        if (is_string($requestId) && $requestId !== '') {
            $pendingRequest->headers()->add('X-Orbit-Request-Id', $requestId);
        }
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
