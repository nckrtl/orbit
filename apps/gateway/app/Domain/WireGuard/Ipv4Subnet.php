<?php

declare(strict_types=1);

namespace App\Domain\WireGuard;

use Generator;
use InvalidArgumentException;

final readonly class Ipv4Subnet
{
    private function __construct(
        private string $networkAddress,
        private int $prefixLength,
        private int $networkStart,
        private int $networkEnd,
    ) {}

    public static function from(string $value): self
    {
        [$networkAddress, $prefix] = array_pad(
            array: explode(separator: '/', string: $value, limit: 2),
            length: 2,
            value: null,
        );
        $network = is_string($networkAddress)
            ? filter_var($networkAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            : false;
        $numericNetwork = is_string($network) ? ip2long($network) : false;
        $prefixLength = filter_var($prefix, FILTER_VALIDATE_INT);

        if (
            ! is_string($network)
            || $numericNetwork === false
            || ! is_int($prefixLength)
            || $prefixLength < 8
            || $prefixLength > 30
        ) {
            throw new InvalidArgumentException("IPv4 subnet [{$value}] is invalid.");
        }

        $mask = (-1 << (32 - $prefixLength)) & 0xFFFF_FFFF;
        $networkStart = $numericNetwork & $mask;

        if ($networkStart !== $numericNetwork) {
            throw new InvalidArgumentException("IPv4 subnet [{$value}] is invalid.");
        }

        return new self(
            networkAddress: $network,
            prefixLength: $prefixLength,
            networkStart: $networkStart,
            networkEnd: $networkStart | (~$mask & 0xFFFF_FFFF),
        );
    }

    public function value(): string
    {
        return "{$this->networkAddress}/{$this->prefixLength}";
    }

    public function networkAddress(): string
    {
        return $this->networkAddress;
    }

    public function prefixLength(): int
    {
        return $this->prefixLength;
    }

    /** @return array{string, string} */
    public function usableRange(): array
    {
        $first = long2ip($this->networkStart + 1);
        $last = long2ip($this->networkEnd - 1);

        return [$first, $last];
    }

    public function contains(string $address): bool
    {
        $candidate = $this->numericAddress($address);

        return $candidate !== null && $candidate >= $this->networkStart && $candidate <= $this->networkEnd;
    }

    public function containsUsableAddress(string $address): bool
    {
        $candidate = $this->numericAddress($address);

        return $candidate !== null && $candidate > $this->networkStart && $candidate < $this->networkEnd;
    }

    /** @return Generator<int, string> */
    public function usableAddresses(): Generator
    {
        for ($candidate = $this->networkStart + 1; $candidate < $this->networkEnd; $candidate++) {
            $address = long2ip($candidate);

            yield $address;
        }
    }

    private function numericAddress(string $address): ?int
    {
        $validated = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
        $numeric = is_string($validated) ? ip2long($validated) : false;

        return is_int($numeric) ? $numeric : null;
    }
}
