<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\WireGuard\Ipv4Subnet;

/**
 * One compute host Node that runs task VMs, as `TaskVmSettings::fromConfig()` validated it.
 *
 * `cidr` is the task bridge network, for example `10.251.77.0/24`. The bridge owns the first
 * usable address. Guests get the other usable addresses. `pool` is a ZFS storage pool; when it is
 * missing, `task-vms:prepare-host` creates it from `zfsDataset`.
 */
final readonly class TaskVmHost
{
    /** The stock Ubuntu cloud image that `incus-host.sh` copies. Only the base image build launches it. */
    public const string SourceImage = 'ubuntu-26.04-vm';

    /** The alias of the base image that every task VM launches. Each build also adds a dated alias `orbit-task-base-<time>`. */
    public const string BaseImage = 'orbit-task-base';

    public function __construct(
        public int $nodeId,
        public string $project,
        public string $network,
        public string $cidr,
        public int $maxVms,
        public int $cpus,
        public string $memory,
        public string $disk,
        public string $pool = 'orbit-tasks',
        public ?string $zfsDataset = null,
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
