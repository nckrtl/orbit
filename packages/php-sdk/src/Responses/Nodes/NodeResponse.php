<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Support\GatewayErrorCode;
use SensitiveParameter;

/**
 * @mago-expect lint:cyclomatic-complexity Gateway values are validated at the DTO boundary.
 * @mago-expect lint:excessive-parameter-list
 */
final readonly class NodeResponse
{
    /**
     * @param list<string> $roles
     * @param list<NodeRoleAssignmentResponse> $roleAssignments
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $status,
        public string $publicSshHost,
        public int $publicSshPort,
        public string $sshUser,
        public ?string $wireguardAddress,
        public array $roles,
        public string $requestId,
        public ?string $platform = null,
        public ?string $architecture = null,
        public ?string $sshHostFingerprint = null,
        public ?string $failedStep = null,
        public ?string $errorCode = null,
        public ?string $tld = null,
        public ?string $wireguardPublicKey = null,
        public ?string $wireguardEndpointOverride = null,
        public ?string $dnsServerOverride = null,
        public array $roleAssignments = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        return new self(
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            status: is_string($data['status'] ?? null) ? $data['status'] : '',
            platform: is_string($data['platform'] ?? null) ? $data['platform'] : null,
            architecture: is_string($data['architecture'] ?? null) ? $data['architecture'] : null,
            tld: is_string($data['tld'] ?? null) ? $data['tld'] : null,
            publicSshHost: is_string($data['public_ssh_host'] ?? null) ? $data['public_ssh_host'] : '',
            publicSshPort: is_int($data['public_ssh_port'] ?? null) ? $data['public_ssh_port'] : 0,
            sshUser: is_string($data['ssh_user'] ?? null) ? $data['ssh_user'] : '',
            wireguardAddress: is_string($data['wireguard_address'] ?? null) ? $data['wireguard_address'] : null,
            wireguardPublicKey: is_string($data['wireguard_public_key'] ?? null)
                ? $data['wireguard_public_key']
                : null,
            wireguardEndpointOverride: is_string($data['wireguard_endpoint_override'] ?? null)
                ? $data['wireguard_endpoint_override']
                : null,
            dnsServerOverride: is_string($data['dns_server_override'] ?? null)
                ? $data['dns_server_override']
                : null,
            sshHostFingerprint: is_string($data['ssh_host_fingerprint'] ?? null)
                ? $data['ssh_host_fingerprint']
                : null,
            failedStep: is_string($data['failed_step'] ?? null) ? $data['failed_step'] : null,
            errorCode: GatewayErrorCode::fromTransport($data['error_code'] ?? null),
            roles: self::stringList($data['roles'] ?? []),
            requestId: $requestId,
            roleAssignments: array_key_exists('role_assignments', $data)
                ? self::roleAssignmentList($data['role_assignments'])
                : [],
        );
    }

    /** @return array<string, int|string|list<string>|list<array<string, bool|string|null>>|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'platform' => $this->platform,
            'architecture' => $this->architecture,
            'tld' => $this->tld,
            'public_ssh_host' => $this->publicSshHost,
            'public_ssh_port' => $this->publicSshPort,
            'ssh_user' => $this->sshUser,
            'wireguard_address' => $this->wireguardAddress,
            'wireguard_public_key' => $this->wireguardPublicKey,
            'wireguard_endpoint_override' => $this->wireguardEndpointOverride,
            'dns_server_override' => $this->dnsServerOverride,
            'ssh_host_fingerprint' => $this->sshHostFingerprint,
            'failed_step' => $this->failedStep,
            'error_code' => $this->errorCode,
            'roles' => $this->roles,
            'role_assignments' => array_map(
                static fn (NodeRoleAssignmentResponse $assignment): array => $assignment->toArray(),
                $this->roleAssignments,
            ),
            'request_id' => $this->requestId,
        ];
    }

    /**
     * @mago-expect analysis:mixed-assignment Gateway role values remain mixed until validated.
     *
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $result[] = $item;
        }

        return $result;
    }

    /**
     * @mago-expect analysis:mixed-assignment Gateway role assignments remain mixed until validated.
     *
     * @return list<NodeRoleAssignmentResponse>
     */
    private static function roleAssignmentList(#[SensitiveParameter] mixed $value): array
    {
        if (! is_array($value)) {
            self::invalidRoleAssignment();
        }

        $assignments = [];

        foreach ($value as $assignment) {
            if (! is_array($assignment)) {
                self::invalidRoleAssignment();
            }

            $assignments[] = NodeRoleAssignmentResponse::fromGatewayData($assignment);
        }

        return $assignments;
    }

    private static function invalidRoleAssignment(): never
    {
        throw new GatewayApiException('Gateway response contains an invalid node role assignment.');
    }
}
