<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\DatabaseConnections;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseUserResponse;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseUsersResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListDatabaseUsersRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly string $slug,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/database-connections/'.$this->slug.'/users';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): DatabaseUsersResponse
    {
        $requestId = $this->successRequestId($response);
        $users = [];

        foreach ($this->unwrapDataList($response) as $user) {
            $users[] = DatabaseUserResponse::fromGatewayData($user);
        }

        return new DatabaseUsersResponse($users, $requestId);
    }
}
