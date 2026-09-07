<?php

declare(strict_types=1);

namespace App\Domain\WireGuard;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use InvalidArgumentException;

/** @mago-expect lint:cyclomatic-complexity Peer allocation centralizes format, subnet, and uniqueness checks. */
final readonly class WireGuardAddressAllocator
{
    public function __construct(
        private VpnSettings $settings,
    ) {}

    public function next(): string
    {
        $subnet = $this->subnet();
        $used = Node::query()
            ->whereNotNull('wireguard_ip')
            ->pluck('wireguard_ip')
            ->filter(static fn (mixed $address): bool => is_string($address))
            ->all();

        foreach ($subnet->usableAddresses() as $address) {
            if (! in_array($address, $used, strict: true)) {
                return $address;
            }
        }

        throw new ResourceOperationException(
            errorCode: 'vpn.peer_address_exhausted',
            message: "WireGuard subnet [{$subnet->value()}] has no free peer addresses.",
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
