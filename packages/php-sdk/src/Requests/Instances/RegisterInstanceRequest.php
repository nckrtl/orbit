<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Instances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Instances\InstanceRegistrationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RegisterInstanceRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $sourcePath,
        private readonly bool $includeWorktrees = false,
        private readonly ?int $projectId = null,
        private readonly ?string $instanceName = null,
        /** @var array<string, array{path: string, web_root: string|null}>|null */
        private readonly ?array $appOverrides = null,
        private readonly ?string $domain = null,
        private readonly bool $setup = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/instances/register';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): InstanceRegistrationResponse
    {
        return InstanceRegistrationResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }

    /** @return array<string, bool|int|string|object> */
    protected function defaultBody(): array
    {
        $body = ['source_path' => $this->sourcePath];

        if ($this->includeWorktrees) {
            $body['include_worktrees'] = true;
        }

        if ($this->setup) {
            $body['setup'] = true;
        }

        if ($this->appOverrides !== null) {
            $body['app_overrides'] = (object) $this->appOverrides;
        }

        foreach ([
            'project_id' => $this->projectId,
            'instance_name' => $this->instanceName,
            'domain' => $this->domain,
        ] as $key => $value) {
            if ($value === null) {
                continue;
            }

            $body[$key] = $value;
        }

        return $body;
    }
}
