<?php

declare(strict_types=1);

namespace App\Infrastructure\WireGuard;

/**
 * Reads the IPv4 nameservers the gateway host already uses on its uplink NIC.
 *
 * Mesh dnsmasq keeps `no-resolv` and emits `server=` lines for these addresses
 * so VPN clients recurse through the same uplink/DHCP resolvers as the host.
 * The systemd stub listener is skipped: pointing dnsmasq at 127.0.0.53 can
 * loop once the mesh listener is bound. When no uplink resolvers are visible,
 * the documented public recursive fallback is used.
 */
final readonly class UplinkDnsResolvers
{
    /**
     * Public recursive resolvers used only when no uplink/DHCP nameservers are
     * visible at converge time.
     *
     * @var non-empty-list<string>
     */
    public const array FALLBACK = ['1.1.1.1', '8.8.8.8'];

    public function __construct(
        private string $root = '/',
    ) {}

    /** @return non-empty-list<string> */
    public function nameservers(): array
    {
        $resolved = $this->fromResolvedUplink();

        if ($resolved !== []) {
            return $resolved;
        }

        $dhcp = $this->fromDefaultRouteDhcpLease();

        if ($dhcp !== []) {
            return $dhcp;
        }

        return self::FALLBACK;
    }

    /** @return list<string> */
    private function fromResolvedUplink(): array
    {
        return $this->nameserversFromResolv($this->read('run/systemd/resolve/resolv.conf'));
    }

    /** @return list<string> */
    private function fromDefaultRouteDhcpLease(): array
    {
        $interface = $this->defaultRouteInterface();

        if ($interface === null) {
            return [];
        }

        $index = $this->interfaceIndex($interface);

        if ($index === null) {
            return [];
        }

        return $this->nameserversFromDhcpLease($this->read("run/systemd/netif/leases/{$index}"));
    }

    /** @return list<string> */
    private function nameserversFromResolv(?string $contents): array
    {
        if ($contents === null) {
            return [];
        }

        $servers = [];
        $lines = preg_split('/\R/', $contents);

        if (! is_array($lines)) {
            return [];
        }

        foreach ($lines as $line) {
            $matches = [];

            if (preg_match('/^nameserver\s+(\S+)/', trim($line), $matches) !== 1) {
                continue;
            }

            $ip = $this->ipv4Nameserver($matches[1]);

            if ($ip === null) {
                continue;
            }

            $servers[] = $ip;
        }

        return $this->unique($servers);
    }

    /** @return list<string> */
    private function nameserversFromDhcpLease(?string $contents): array
    {
        if ($contents === null) {
            return [];
        }

        $servers = [];
        $lines = preg_split('/\R/', $contents);

        if (! is_array($lines)) {
            return [];
        }

        foreach ($lines as $line) {
            if (! str_starts_with($line, 'DNS=')) {
                continue;
            }

            $candidates = preg_split('/\s+/', substr($line, 4));

            if (! is_array($candidates)) {
                continue;
            }

            foreach ($candidates as $candidate) {
                $ip = $this->ipv4Nameserver($candidate);

                if ($ip === null) {
                    continue;
                }

                $servers[] = $ip;
            }
        }

        return $this->unique($servers);
    }

    private function defaultRouteInterface(): ?string
    {
        $contents = $this->read('proc/net/route');

        if ($contents === null) {
            return null;
        }

        $lines = preg_split('/\R/', $contents);

        if (! is_array($lines)) {
            return null;
        }

        $bestInterface = null;
        $bestMetric = null;

        foreach ($lines as $line) {
            $fields = preg_split('/\s+/', trim($line));

            if (! is_array($fields) || count($fields) < 7) {
                continue;
            }

            [$interface, $destination, , , , , $metric] = $fields;

            if ($destination !== '00000000' || $this->isNonUplinkInterface($interface)) {
                continue;
            }

            $metricValue = filter_var($metric, FILTER_VALIDATE_INT);

            if (! is_int($metricValue)) {
                continue;
            }

            if ($bestMetric !== null && $metricValue >= $bestMetric) {
                continue;
            }

            $bestInterface = $interface;
            $bestMetric = $metricValue;
        }

        return $bestInterface;
    }

    private function isNonUplinkInterface(string $interface): bool
    {
        return $interface === 'lo' || $interface === 'orbit';
    }

    private function interfaceIndex(string $interface): ?int
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,14}$/', $interface) !== 1) {
            return null;
        }

        $contents = $this->read("sys/class/net/{$interface}/ifindex");

        if ($contents === null) {
            return null;
        }

        $index = filter_var(trim($contents), FILTER_VALIDATE_INT);

        return is_int($index) && $index > 0 ? $index : null;
    }

    private function ipv4Nameserver(string $value): ?string
    {
        $ip = filter_var(trim($value), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);

        if (! is_string($ip) || str_starts_with($ip, '127.') || $ip === '0.0.0.0') {
            return null;
        }

        return $ip;
    }

    /** @param list<string> $servers @return list<string> */
    private function unique(array $servers): array
    {
        return array_values(array_unique($servers));
    }

    private function read(string $relative): ?string
    {
        $path = rtrim($this->root, '/').'/'.$relative;

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return is_string($contents) ? $contents : null;
    }
}
