<?php

declare(strict_types=1);

namespace App\Domain\WireGuard;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmSettings;
use App\Models\Node;
use InvalidArgumentException;

final readonly class WireGuardAddressAllocator
{
    public function __construct(
        private VpnSettings $settings,
    ) {}

    /**
     * The next free fleet address. It never lies in the range reserved for task VMs. It reads the
     * range through `TaskVmSettings`, so an invalid task VM config fails with `task_vm.invalid_config`.
     */
    public function next(): string
    {
        $subnet = $this->subnet();
        $reserved = Ipv4Subnet::from(resolve(TaskVmSettings::class)->wireguardRange);
        $used = $this->nodeAddresses();

        foreach ($subnet->usableAddresses() as $address) {
            if (! $reserved->contains($address) && ! in_array($address, $used, strict: true)) {
                return $address;
            }
        }

        throw new ResourceOperationException(
            errorCode: 'vpn.peer_address_exhausted',
            message: "WireGuard subnet [{$subnet->value()}] has no free peer addresses.",
            status: 409,
        );
    }

    /**
     * The next free address inside a range of the fleet subnet, such as the task VM range that
     * `TaskVmSettings` validated.
     *
     * @param  list<string>  $taken  addresses that are reserved but may not belong to a Node yet
     */
    public function nextIn(string $cidr, array $taken): string
    {
        $range = Ipv4Subnet::from($cidr);
        $used = [...$this->nodeAddresses(), ...$taken];

        foreach ($range->usableAddresses() as $address) {
            if (! in_array($address, $used, strict: true)) {
                return $address;
            }
        }

        throw new ResourceOperationException(
            errorCode: 'vpn.peer_address_exhausted',
            message: "WireGuard range [{$range->value()}] has no free peer addresses.",
            status: 409,
        );
    }

    public function forProvisioning(?string $requestedAddress, ?Node $node = null): string
    {
        if ($requestedAddress === null) {
            return $this->next();
        }

        $subnet = $this->subnet();

        if (! $subnet->containsUsableAddress($requestedAddress)) {
            throw new ResourceOperationException(
                errorCode: 'vpn.peer_address_invalid',
                message: "WireGuard peer address [{$requestedAddress}] is outside the usable subnet range.",
            );
        }

        $query = Node::query()->where('wireguard_ip', $requestedAddress);

        if ($node instanceof Node && $node->exists) {
            $query->whereKeyNot($node->getKey());
        }

        if ($query->exists()) {
            throw new ResourceOperationException(
                errorCode: 'vpn.peer_address_taken',
                message: "WireGuard peer address [{$requestedAddress}] is already assigned.",
                status: 409,
            );
        }

        return $requestedAddress;
    }

    /** @return list<string> */
    private function nodeAddresses(): array
    {
        return array_values(Node::query()
            ->whereNotNull('wireguard_ip')
            ->pluck('wireguard_ip')
            ->filter(static fn (mixed $address): bool => is_string($address))
            ->all());
    }

    private function subnet(): Ipv4Subnet
    {
        $value = $this->settings->subnet();

        try {
            return Ipv4Subnet::from($value);
        } catch (InvalidArgumentException) {
            throw $this->invalidSubnet($value);
        }
    }

    private function invalidSubnet(string $subnet): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'vpn.subnet_invalid',
            message: "WireGuard subnet [{$subnet}] is invalid.",
        );
    }
}
