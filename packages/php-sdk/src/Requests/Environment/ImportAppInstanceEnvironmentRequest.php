<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Environment;

use Saloon\Enums\Method;

final class ImportAppInstanceEnvironmentRequest extends EnvironmentRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int|string $appInstance,
        private readonly ?bool $replace = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/instances/'.rawurlencode((string) $this->appInstance).'/environment/import';
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
