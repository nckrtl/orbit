<?php

declare(strict_types=1);

namespace App\Actions\Gateway;

use App\Data\Gateway\BootstrapGatewayData;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Domain\WireGuard\WireGuardEndpoint;
use InvalidArgumentException;

final readonly class GatewayBootstrapIdentityValidator
{
    public function validate(BootstrapGatewayData $data): void
    {
        $hostname = "{$data->name}.{$data->domain}";

        if (filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new InvalidArgumentException("Gateway hostname [{$hostname}] is invalid.");
        }

        if (! $this->isHost($data->publicHost)) {
            throw new InvalidArgumentException("Gateway public host [{$data->publicHost}] is invalid.");
        }

        if (filter_var($data->wireguardIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException("Gateway WireGuard address [{$data->wireguardIp}] is invalid.");
        }

        $this->validateSubnet($data->wireguardSubnet, $data->wireguardIp);

        if (filter_var($data->dnsServer, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException("Gateway DNS server [{$data->dnsServer}] is invalid.");
        }

        if ($data->wireguardPort < 1 || $data->wireguardPort > 65_535) {
            throw new InvalidArgumentException('Gateway WireGuard port is invalid.');
        }

        if (! WireGuardEndpoint::isValid($data->wireguardEndpoint)) {
            throw new InvalidArgumentException("Gateway WireGuard endpoint [{$data->wireguardEndpoint}] is invalid.");
        }

        if (
            $data->privateInterface !== null
            && preg_match('/^[A-Za-z0-9_.:+-]{1,15}$/', $data->privateInterface) !== 1
        ) {
            throw new InvalidArgumentException("Gateway private interface [{$data->privateInterface}] is invalid.");
        }
    }

    private function isHost(string $host): bool
    {
        return
            filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    private function validateSubnet(string $subnet, string $address): void
    {
        try {
            $network = Ipv4Subnet::from($subnet);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException("Gateway WireGuard subnet [{$subnet}] is invalid.");
        }

        if (! $network->contains($address)) {
            throw new InvalidArgumentException("Gateway WireGuard address [{$address}] is outside [{$subnet}].");
        }

        if (! $network->containsUsableAddress($address)) {
            throw new InvalidArgumentException("Gateway WireGuard address [{$address}] is not usable in [{$subnet}].");
        }
    }
}
