<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

/**
 * Renders the listener's systemd units. `orbit-private-dns.socket` holds the UDP and TCP sockets, so a restart of
 * `orbit-private-dns.service` never closes them. The service runs `serve.php` from an installed release. ADR 0149.
 */
final readonly class PrivateDnsListenerUnitRenderer
{
    public function name(): string
    {
        return 'orbit-private-dns.service';
    }

    public function socketName(): string
    {
        return 'orbit-private-dns.socket';
    }

    public function path(string $unitDirectory = '/etc/systemd/system'): string
    {
        return rtrim($unitDirectory, '/').'/'.$this->name();
    }

    public function socketPath(string $unitDirectory = '/etc/systemd/system'): string
    {
        return rtrim($unitDirectory, '/').'/'.$this->socketName();
    }

    public function render(
        string $phpBinary,
        string $releaseDirectory,
        string $listenAddress,
        int $port,
        string $catalogPath,
        string $upstream,
    ): string {
        return implode("\n", [
            '[Unit]',
            'Description=Orbit private DNS',
            'After=network-online.target wg-quick@orbit.service dnsmasq.service '.$this->socketName(),
            'Wants=network-online.target',
            // Ordering only: a query that activates the listener must never start a tunnel an operator stopped.
            'Requires='.$this->socketName(),
            '',
            '[Service]',
            'Type=simple',
            'User=root',
            'WorkingDirectory='.$this->escapeDirectivePath($releaseDirectory),
            'ExecStart='.implode(' ', array_map($this->quoteArgument(...), [
                $phpBinary,
                rtrim($releaseDirectory, '/').'/serve.php',
                '--listen='.$listenAddress,
                '--port='.(string) $port,
                '--catalog='.$catalogPath,
                '--upstream='.$upstream,
            ])),
            'Sockets='.$this->socketName(),
            'KillSignal=SIGTERM',
            'TimeoutStopSec=5',
            'Restart=on-failure',
            'RestartSec=2',
            '',
            '[Install]',
            'WantedBy=multi-user.target',
            '',
        ]);
    }

    /**
     * FreeBind lets the socket bind the WireGuard address before the tunnel is up and keep it while the tunnel
     * restarts. The socket must not wait for the tunnel: sockets start before `basic.target`, and
     * `wg-quick@orbit.service` starts after it, so that ordering would form a boot cycle.
     */
    public function renderSocket(string $listenAddress, int $port): string
    {
        $address = $listenAddress.':'.(string) $port;

        return implode("\n", [
            '[Unit]',
            'Description=Orbit private DNS sockets',
            '',
            '[Socket]',
            'ListenDatagram='.$address,
            'ListenStream='.$address,
            'FreeBind=yes',
            'Service='.$this->name(),
            '',
            '[Install]',
            'WantedBy=sockets.target',
            '',
        ]);
    }

    private function quoteArgument(string $argument): string
    {
        return
            '"'
            .str_replace(
                ['\\', '"', '$', '%'],
                ['\\\\', '\\"', '$$', '%%'],
                $argument,
            )
            .'"';
    }

    private function escapeDirectivePath(string $value): string
    {
        $escaped = preg_replace_callback(
            '/[\x00-\x20"\'$%\\\\\x7F]/',
            static fn (array $match): string => $match[0] === '%'
                ? '%%'
                : sprintf('\\x%02x', ord($match[0])),
            $value,
        );

        return $escaped ?? $value;
    }
}
