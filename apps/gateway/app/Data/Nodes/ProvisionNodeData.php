<?php

declare(strict_types=1);

namespace App\Data\Nodes;

use App\Domain\Nodes\RoleName;
use Spatie\LaravelData\Data;

final class ProvisionNodeData extends Data
{
    /**
     * @param  list<RoleName>  $roles
     * @param  int|null  $sshJumpNodeId  Internal: the Node that public SSH goes through until the Node has an active role. No API or CLI field sets it.
     * @param  int|null  $taskVmId  Internal: the task VM row that the new Node serves. Provisioning links them when it saves the Node record, before any convergence. No API or CLI field sets it.
     */
    public function __construct(
        public string $name,
        public string $publicSshHost,
        public array $roles = [],
        public int $publicSshPort = 22,
        public ?string $user = null,
        public ?string $orbitUser = null,
        public ?string $wireguardIp = null,
        public ?string $wireguardEndpointOverride = null,
        public ?string $dnsServerOverride = null,
        public ?string $expectedSshHostFingerprint = null,
        public string $platform = 'linux',
        public ?string $architecture = null,
        public bool $tldProvided = false,
        public ?string $tld = null,
        public bool $clusterProvided = false,
        public ?int $clusterId = null,
        public bool $lanIpProvided = false,
        public ?string $lanIp = null,
        public bool $settingsProvided = false,
        public ?NodeSettingsData $settings = null,
        public bool $platformProvided = false,
        public ?int $sshJumpNodeId = null,
        public ?int $taskVmId = null,
    ) {}
}
