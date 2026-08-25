<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Nodes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:excessive-parameter-list
 */
final class ProvisionNodeRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /** @param list<string> $roles */
    public function __construct(
        private readonly string $name,
        private readonly string $publicSshHost,
        private readonly array $roles = [],
        private readonly int $publicSshPort = 22,
        private readonly string $sshUser = 'root',
        private readonly ?string $wireguardAddress = null,
        private readonly ?string $wireguardEndpointOverride = null,
        private readonly ?string $dnsServerOverride = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/nodes';
    }

    public function createDtoFromResponse(Response $response): NodeResponse
    {
        $data = $this->unwrapData($response);
        $meta = $this->unwrapMeta($response);

        return new NodeResponse(
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            status: is_string($data['status'] ?? null) ? $data['status'] : '',
            publicSshHost: is_string($data['public_ssh_host'] ?? null) ? $data['public_ssh_host'] : '',
            publicSshPort: is_int($data['public_ssh_port'] ?? null) ? $data['public_ssh_port'] : 22,
            sshUser: is_string($data['ssh_user'] ?? null) ? $data['ssh_user'] : '',
            wireguardAddress: is_string($data['wireguard_address'] ?? null) ? $data['wireguard_address'] : null,
            roles: $this->stringList($data['roles'] ?? []),
            requestId: is_string($meta['request_id'] ?? null) ? $meta['request_id'] : '',
        );
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return [
            'name' => $this->name,
            'public_ssh_host' => $this->publicSshHost,
            'public_ssh_port' => $this->publicSshPort,
            'ssh_user' => $this->sshUser,
            'roles' => $this->roles,
            'wireguard_address' => $this->wireguardAddress,
            'wireguard_endpoint_override' => $this->wireguardEndpointOverride,
            'dns_server_override' => $this->dnsServerOverride,
        ];
    }
}
