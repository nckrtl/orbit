<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

use SensitiveParameter;

/**
 * @mago-expect lint:cyclomatic-complexity Gateway values are validated at the DTO boundary.
 * @mago-expect lint:excessive-parameter-list
 */
final readonly class RemovedNodeResponse
{
    /**
     * @param list<string> $rolesShed
     * @param list<string> $retainedOnNode
     */
    public function __construct(
        public int $id,
        public string $name,
        public bool $removed,
        public bool $wireguardPeerRemoved,
        public bool $dnsRecordsRemoved,
        public ?string $degradation,
        public array $rolesShed,
        public array $retainedOnNode,
        public ?string $followUp,
        public string $requestId,
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
            removed: ($data['removed'] ?? false) === true,
            wireguardPeerRemoved: ($data['wireguard_peer_removed'] ?? false) === true,
            dnsRecordsRemoved: ($data['dns_records_removed'] ?? false) === true,
            degradation: is_string($data['degradation'] ?? null) ? $data['degradation'] : null,
            rolesShed: self::stringList($data['roles_shed'] ?? []),
            retainedOnNode: self::stringList($data['retained_on_node'] ?? []),
            followUp: is_string($data['follow_up'] ?? null) ? $data['follow_up'] : null,
            requestId: $requestId,
        );
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     removed: bool,
     *     wireguard_peer_removed: bool,
     *     dns_records_removed: bool,
     *     degradation: ?string,
     *     roles_shed: list<string>,
     *     retained_on_node: list<string>,
     *     follow_up: ?string,
     *     request_id: string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'removed' => $this->removed,
            'wireguard_peer_removed' => $this->wireguardPeerRemoved,
            'dns_records_removed' => $this->dnsRecordsRemoved,
            'degradation' => $this->degradation,
            'roles_shed' => $this->rolesShed,
            'retained_on_node' => $this->retainedOnNode,
            'follow_up' => $this->followUp,
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
}
