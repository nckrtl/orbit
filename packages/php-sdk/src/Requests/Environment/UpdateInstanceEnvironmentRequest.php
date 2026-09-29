<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Environment;

use Saloon\Enums\Method;
use SensitiveParameter;

final class UpdateInstanceEnvironmentRequest extends EnvironmentRequest
{
    #[\Override]
    protected Method $method = Method::PUT;

    public function __construct(
        private readonly int|string $instance,
        private readonly string $key,
        #[SensitiveParameter]
        private readonly string $value,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/instances/'.rawurlencode((string) $this->instance).'/environment/'.rawurlencode($this->key);
    }

    protected function expectedOperation(): string
    {
        return 'update';
    }

    /** @return array{value: string} */
    protected function defaultBody(): array
    {
        return ['value' => $this->value];
    }
}
