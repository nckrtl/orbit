<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\TopologyProfile;
use RuntimeException;

/**
 * Host capacity comes from Incus itself: the harness VMs that exist now and
 * the deterministic `10.232.<slot>.0/24` subnets in use. There is no ledger.
 */
final readonly class HostCapacity
{
    public function __construct(
        private IncusHost $host,
        private int $maxVms,
    ) {
        if ($maxVms < (2 * count(TopologyProfile::ROLES))) {
            throw new RuntimeException('Incus host capacity cannot fit the standby and one feature topology.');
        }
    }

    /** Refuse when one more topology would exceed the VM budget; return its free network slot. */
    public function reserveSlot(): int
    {
        $existing = count($this->host->harnessInstanceMetadata());
        if (($existing + count(TopologyProfile::ROLES)) > $this->maxVms) {
            throw new RuntimeException(
                "Incus host capacity is exhausted: {$existing} harness VMs exist and the limit is {$this->maxVms}.",
            );
        }

        $occupied = $this->occupiedNetworkSlots();
        for ($slot = 2; $slot <= 200; $slot++) {
            if (! isset($occupied[$slot])) {
                return $slot;
            }
        }

        throw new RuntimeException('Incus network slots are exhausted.');
    }

    /** @return array<int, true> */
    private function occupiedNetworkSlots(): array
    {
        $occupied = [1 => true];
        foreach ($this->host->networks() as $network) {
            $address = $network->config['ipv4.address'] ?? null;
            if ($address === null) {
                continue;
            }
            if (preg_match('/\A10\.232\.([0-9]{1,3})\.(?:0|1)\/24\z/D', $address, $match) !== 1) {
                if (str_starts_with($address, '10.232.')) {
                    throw new RuntimeException(
                        'Incus network inventory contains malformed deterministic IPv4 address.',
                    );
                }
                continue;
            }
            $occupied[(int) $match[1]] = true;
        }

        return $occupied;
    }
}
