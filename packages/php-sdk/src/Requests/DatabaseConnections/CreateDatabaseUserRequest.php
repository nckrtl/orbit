<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\DatabaseConnections;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionResponse;
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
        private readonly int $processId,
        private readonly string $slug,
        private readonly string $database,
        private readonly string $username,
        #[SensitiveParameter]
        private readonly string $password,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/processes/'.$this->processId.'/database-users';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): DatabaseConnectionResponse
    {
        return DatabaseConnectionResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array<string, string> */
    protected function defaultBody(): array
    {
        return [
            'slug' => $this->slug,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
        ];
    }
}
