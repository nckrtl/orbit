<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Environment;

use Saloon\Enums\Method;

final class SynchronizeInstanceEnvironmentRequest extends EnvironmentRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int|string $instance,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/instances/'.rawurlencode((string) $this->instance).'/environment/sync';
    }

    protected function expectedOperation(): string
    {
        return 'sync';
    }

    /** @return array<never, never> */
    protected function defaultBody(): array
    {
        return [];
    }
}
