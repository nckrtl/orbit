<?php

declare(strict_types=1);

namespace App\Data\Nodes;

use App\Domain\Nodes\RoleName;
use Spatie\LaravelData\Data;

/** @mago-expect lint:excessive-parameter-list */
final class ProvisionNodeData extends Data
{
    /** @param list<RoleName> $roles */
    public function __construct(
        public string $name,
        public string $publicSshHost,
        public array $roles = [],
        public int $publicSshPort = 22,
        public string $user = 'root',
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
    ) {}
}
