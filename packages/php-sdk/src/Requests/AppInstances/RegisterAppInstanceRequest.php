<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\AppInstances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceRegistrationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RegisterAppInstanceRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $sourcePath,
        private readonly bool $includeWorktrees = false,
        private readonly ?int $appId = null,
        private readonly ?string $appName = null,
        private readonly ?string $appSlug = null,
        private readonly ?string $defaultBranch = null,
        private readonly ?string $instanceName = null,
        private readonly ?string $root = null,
        private readonly ?string $hostname = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/instances/register';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): AppInstanceRegistrationResponse
    {
        return AppInstanceRegistrationResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }

    /** @return array<string, bool|int|string> */
    protected function defaultBody(): array
    {
        $body = ['source_path' => $this->sourcePath];

        if ($this->includeWorktrees) {
            $body['include_worktrees'] = true;
        }

        foreach ([
            'app_id' => $this->appId,
            'app_name' => $this->appName,
            'app_slug' => $this->appSlug,
            'default_branch' => $this->defaultBranch,
            'instance_name' => $this->instanceName,
            'root' => $this->root,
            'hostname' => $this->hostname,
        ] as $key => $value) {
            if ($value === null) {
                continue;
            }

            $body[$key] = $value;
        }

        return $body;
    }
}
