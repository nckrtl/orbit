<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Environment;

use Saloon\Enums\Method;

final class ImportInstanceEnvironmentRequest extends EnvironmentRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int|string $instance,
        private readonly ?bool $replace = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/instances/'.rawurlencode((string) $this->instance).'/environment/import';
    }

    protected function expectedOperation(): string
    {
        return 'import';
    }

    /** @return array{replace?: bool} */
    protected function defaultBody(): array
    {
        return $this->replace === null ? [] : ['replace' => $this->replace];
    }
}
