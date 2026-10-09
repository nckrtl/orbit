<?php

declare(strict_types=1);

namespace App\Domain\Compute;

final readonly class SandboxSpec
{
    /** The pinned public Ubuntu template; any other image is a published Orbit base template. */
    public const string Image = '01000000-0000-4000-8000-000030260200';

    public const string DefaultSize = 'starter-2x2';

    /** Size name => [UpCloud plan, disk in GB]. Recorded reservations keep their original size. */
    private const array Sizes = ['starter-small' => ['STARTER-1xCPU-1GB', 20], 'starter-2x2' => ['STARTER-2xCPU-2GB', 30]];

    public function __construct(
        public string $zone,
        public string $gatewayAddress,
        public string $wireguardAddress,
        public int $wireguardPort,
        public string $publicKey,
        public string $image = self::Image,
        public string $size = 'starter-small',
    ) {
        if (preg_match('/\A[a-z]{2}-[a-z]{3}[1-9]\z/D', $zone) !== 1
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $image) !== 1
            || ! array_key_exists($size, self::Sizes)
            || ! self::publicIpv4($gatewayAddress) || ! self::publicIpv4($wireguardAddress)
            || $wireguardPort < 1 || $wireguardPort > 65535
            || preg_match('/\Assh-ed25519 [A-Za-z0-9+\/]+={0,2}(?: [^\r\n]+)?\z/D', $publicKey) !== 1) {
            throw new ComputeException('compute.invalid_spec', 'The sandbox image, size, or network configuration is invalid.');
        }
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value): self
    {
        if (! is_string($value['image'] ?? null) || ! is_string($value['size'] ?? null)
            || ! is_string($value['zone'] ?? null) || ! is_string($value['gateway_address'] ?? null)
            || ! is_string($value['wireguard_address'] ?? null) || ! is_int($value['wireguard_port'] ?? null)
            || ! is_string($value['public_key'] ?? null)) {
            throw new ComputeException('compute.invalid_spec', 'The sandbox image, size, or network configuration is invalid.');
        }

        return new self($value['zone'], $value['gateway_address'], $value['wireguard_address'], $value['wireguard_port'], $value['public_key'],
            $value['image'], $value['size']);
    }

    public function usesBaseImage(): bool
    {
        return $this->image !== self::Image;
    }

    public function plan(): string
    {
        return self::Sizes[$this->size][0];
    }

    public function diskGb(): int
    {
        return self::Sizes[$this->size][1];
    }

    /** @return array{image: string, size: string, zone: string, gateway_address: string, wireguard_address: string, wireguard_port: int, public_key: string} */
    public function toArray(): array
    {
        return [
            'image' => $this->image, 'size' => $this->size, 'zone' => $this->zone,
            'gateway_address' => $this->gatewayAddress, 'wireguard_address' => $this->wireguardAddress,
            'wireguard_port' => $this->wireguardPort, 'public_key' => $this->publicKey,
        ];
    }

    private static function publicIpv4(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}
