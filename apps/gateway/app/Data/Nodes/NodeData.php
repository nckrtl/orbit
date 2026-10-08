<?php

declare(strict_types=1);

namespace App\Data\Nodes;

use App\Domain\Nodes\NodeUpdates;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Models\Node;
use App\Support\ValidatedData;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class NodeData extends Data
{
    /** @param list<string> $roles */
    public function __construct(
        public int $id,
        public ?int $clusterId,
        public string $name,
        public string $status,
        public ?string $platform,
        public ?string $architecture,
        public ?string $tld,
        public string $publicSshHost,
        public int $publicSshPort,
        public string $user,
        public ?string $wireguardIp,
        public ?string $lanIp,
        public ?string $wireguardPublicKey,
        public ?string $wireguardEndpointOverride,
        public ?string $dnsServerOverride,
        public ?string $sshHostFingerprint,
        public ?string $failedStep,
        public ?string $errorCode,
        public array $roles,
        public ?NodeSettingsData $settings = null,
        public ?NodeUpdatingData $updating = null,
    ) {}

    /**
     * @param  array<int, NodeUpdatingData>|null  $updates  The update states a list read for all its Nodes at once,
     *                                                      keyed by Node id. Null reads this Node's state.
     */
    public static function fromModel(Node $node, ?array $updates = null): self
    {
        $updating = $updates === null ? app(NodeUpdates::class)->forNode($node) : ($updates[$node->id] ?? null);
        $platform = $node->getAttribute('platform');
        $architecture = $node->getAttribute('architecture');
        $tld = $node->getAttribute('tld');
        $wireguardIp = $node->getAttribute('wireguard_ip');
        $lanIp = $node->getAttribute('lan_ip');
        $wireguardPublicKey = $node->getAttribute('wireguard_public_key');
        $wireguardEndpointOverride = $node->getAttribute('wireguard_endpoint_override');
        $dnsServerOverride = $node->getAttribute('dns_server_override');
        $sshHostFingerprint = $node->getAttribute('ssh_host_fingerprint');
        $failedStep = $node->getAttribute('failed_step');
        $errorCode = $node->getAttribute('error_code');

        return new self(
            id: $node->id,
            clusterId: $node->cluster_id,
            name: $node->name,
            status: $node->status->value,
            platform: ValidatedData::nullableString($platform),
            architecture: ValidatedData::nullableString($architecture),
            tld: ValidatedData::nullableString($tld),
            publicSshHost: $node->public_ssh_host,
            publicSshPort: $node->public_ssh_port,
            user: $node->user,
            wireguardIp: ValidatedData::nullableString($wireguardIp),
            lanIp: ValidatedData::nullableString($lanIp),
            wireguardPublicKey: ValidatedData::nullableString($wireguardPublicKey),
            wireguardEndpointOverride: ValidatedData::nullableString($wireguardEndpointOverride),
            dnsServerOverride: ValidatedData::nullableString($dnsServerOverride),
            sshHostFingerprint: ValidatedData::nullableString($sshHostFingerprint),
            failedStep: ValidatedData::nullableString($failedStep),
            errorCode: ValidatedData::nullableString($errorCode),
            roles: self::roles($node),
            settings: new NodeSettingsNormalizer()->fromStored($node->settings),
            updating: $updating,
        );
    }

    /** @return list<string> */
    private static function roles(Node $node): array
    {

        $roleOrder = array_flip(array_map(
            static fn (RoleName $role): string => $role->value,
            RoleName::cases(),
        ));
        $roles = [];
        foreach ($node->roles as $nodeRole) {
            $roles[] = $nodeRole->role;
        }
        usort(
            $roles,
            static fn (RoleName $left, RoleName $right): int => $roleOrder[$left->value]
                <=> $roleOrder[$right->value],
        );

        return array_map(static fn (RoleName $role): string => $role->value, $roles);
    }
}
