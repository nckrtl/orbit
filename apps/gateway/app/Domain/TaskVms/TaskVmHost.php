<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\WireGuard\Ipv4Subnet;

/**
 * One compute host Node that runs task VMs, as `TaskVmSettings::fromConfig()` validated it.
 *
 * `cidr` is the task bridge network, for example `10.251.77.0/24`. The bridge owns the first
 * usable address. Guests get the other usable addresses.
 */
final readonly class TaskVmHost
{
    public function __construct(
        public int $nodeId,
        public string $project,
        public string $network,
        public string $cidr,
        public string $image,
        public int $maxVms,
        public int $cpus,
        public string $memory,
        public string $disk,
        public string $pool = 'default',
    ) {}

    /** The bridge address: the first usable address of `cidr`. */
    public function bridgeAddress(): string
    {
        return Ipv4Subnet::from($this->cidr)->usableRange()[0];
    }

    /** True for a usable address of `cidr` other than the bridge address. */
    public function isGuestAddress(string $address): bool
    {
        return Ipv4Subnet::from($this->cidr)->containsUsableAddress($address) && $address !== $this->bridgeAddress();
    }
}
