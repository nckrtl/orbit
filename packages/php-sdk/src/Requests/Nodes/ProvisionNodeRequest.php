<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Nodes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Orbit\Sdk\Responses\Nodes\NodeSettings;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

/**
 * @mago-expect lint:excessive-parameter-list
 */
final class ProvisionNodeRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    /** @param list<string> $roles */
    public function __construct(
        private readonly string $name,
        private readonly ?string $publicSshHost,
        private readonly array $roles = [],
        private readonly int $publicSshPort = 22,
        private readonly string $user = 'root',
        private readonly ?string $orbitUser = null,
        private readonly ?int $clusterId = null,
        private readonly ?string $wireguardIp = null,
        private readonly ?string $lanIp = null,
        private readonly ?string $wireguardEndpointOverride = null,
        private readonly ?string $dnsServerOverride = null,
        private readonly ?string $hostKeyFingerprint = null,
        private readonly string $platform = 'linux',
        private readonly ?string $architecture = null,
        private readonly ?string $tld = null,
        private readonly bool $settingsProvided = false,
        private readonly ?NodeSettings $settings = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/nodes';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): NodeResponse
    {
        $data = $this->unwrapData($response);
        $requestId = $this->successRequestId($response);

        return NodeResponse::fromGatewayData($data, $requestId);
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        $body = array_filter(
            [
                'name' => $this->name,
                'public_ssh_host' => $this->publicSshHost,
                'platform' => $this->platform,
                'architecture' => $this->architecture,
                'tld' => $this->tld,
                'public_ssh_port' => $this->publicSshPort,
                'user' => $this->user,
                'orbit_user' => $this->orbitUser,
                'roles' => $this->roles,
                'cluster_id' => $this->clusterId,
                'wireguard_ip' => $this->wireguardIp,
                'lan_ip' => $this->lanIp,
                'wireguard_endpoint_override' => $this->wireguardEndpointOverride,
                'dns_server_override' => $this->dnsServerOverride,
                'host_key_fingerprint' => $this->hostKeyFingerprint,
            ],
            static fn (mixed $value): bool => $value !== null,
        );

        if ($this->settingsProvided) {
            $body['settings'] = $this->settings?->toArray();
        }

        return $body;
    }
}
