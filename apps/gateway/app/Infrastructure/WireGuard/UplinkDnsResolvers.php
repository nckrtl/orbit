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
 *
 * @mago-expect lint:cyclomatic-complexity The reader keeps uplink and DHCP host-file branches explicit.
 * @mago-expect lint:kan-defect The score reflects guarded parsing of two host-file formats.
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
        $resolved = $this->parseNameserverLines(
            $this->read('run/systemd/resolve/resolv.conf'),
            '/^nameserver\s+(\S+)/',
        );

        if ($resolved !== []) {
            return $resolved;
        }

        $dhcp = $this->fromDefaultRouteDhcpLease();

        if ($dhcp !== []) {
            return $dhcp;
        }

        return self::FALLBACK;
    }

    /**
     * @mago-expect lint:cyclomatic-complexity Default-route selection and lease parsing stay in one host-file path.
     *
     * @return list<string>
     */
    private function fromDefaultRouteDhcpLease(): array
    {
        $contents = $this->read('proc/net/route');

        if ($contents === null) {
            return [];
        }

        $lines = preg_split('/\R/', $contents);

        if (! is_array($lines)) {
            return [];
        }

        $bestInterface = null;
        $bestMetric = null;

        foreach ($lines as $line) {
            $fields = preg_split('/\s+/', trim($line));

            if (! is_array($fields) || count($fields) < 7) {
                continue;
            }

            [$interface, $destination, , , , , $metric] = $fields;

            if ($destination !== '00000000' || $interface === 'lo' || $interface === 'orbit') {
                continue;
            }

            $metricValue = filter_var($metric, FILTER_VALIDATE_INT);

            if (! is_int($metricValue) || $bestMetric !== null && $metricValue >= $bestMetric) {
                continue;
            }

            $bestInterface = $interface;
            $bestMetric = $metricValue;
        }

        if (
            $bestInterface === null
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,14}$/', $bestInterface) !== 1
        ) {
            return [];
        }

        $index = filter_var(trim((string) $this->read("sys/class/net/{$bestInterface}/ifindex")), FILTER_VALIDATE_INT);

        if (! is_int($index) || $index < 1) {
            return [];
        }

        return $this->parseNameserverLines($this->read("run/systemd/netif/leases/{$index}"), '/^DNS=((?:\S+\s*)+)/');
    }

    /** @return list<string> */
    private function parseNameserverLines(?string $contents, string $pattern): array
    {
        if ($contents === null) {
            return [];
        }

        $lines = preg_split('/\R/', $contents);

        if (! is_array($lines)) {
            return [];
        }

        $servers = [];

        foreach ($lines as $line) {
            $matches = [];

            if (preg_match($pattern, trim($line), $matches) !== 1) {
                continue;
            }

            foreach (preg_split('/\s+/', trim($matches[1])) ?: [] as $candidate) {
                $ip = filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);

                if (! is_string($ip) || str_starts_with($ip, '127.') || $ip === '0.0.0.0') {
                    continue;
                }

                $servers[] = $ip;
            }
        }

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
