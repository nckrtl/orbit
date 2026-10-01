<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\DatabaseConnections;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\DatabaseConnections\CreatedDatabaseUserResponse;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseUserResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;
use SensitiveParameter;

final class CreateDatabaseUserRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $slug,
        private readonly string $username,
        #[SensitiveParameter]
        private readonly string $password,
        private readonly bool $readOnly = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/database-connections/'.rawurlencode($this->slug).'/users';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): CreatedDatabaseUserResponse
    {
        return new CreatedDatabaseUserResponse(
            DatabaseUserResponse::fromGatewayData($this->unwrapData($response)),
            $this->successRequestId($response),
        );
    }

    /** @return array{username: string, password: string, read_only: bool} */
    protected function defaultBody(): array
    {
        return [
            'username' => $this->username,
            'password' => $this->password,
            'read_only' => $this->readOnly,
        ];
    }
}
