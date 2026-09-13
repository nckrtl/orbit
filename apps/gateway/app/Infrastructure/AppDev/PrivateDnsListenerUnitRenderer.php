<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

final readonly class PrivateDnsListenerUnitRenderer
{
    public function name(): string
    {
        return 'orbit-private-dns.service';
    }

    public function path(string $unitDirectory = '/etc/systemd/system'): string
    {
        return rtrim($unitDirectory, '/').'/'.$this->name();
    }

    public function render(
        string $phpBinary,
        string $artisan,
        string $listenAddress,
        int $port,
        string $catalogPath,
        string $upstream,
        string $orbitHome,
        string $workingDirectory,
    ): string {
        return implode("\n", [
            '[Unit]',
            'Description=Orbit private DNS',
            'After=network-online.target wg-quick@orbit.service dnsmasq.service',
            'Wants=network-online.target',
            'Requires=wg-quick@orbit.service',
            '',
            '[Service]',
            'Type=simple',
            'User=root',
            'WorkingDirectory='.$this->escapeDirectivePath($workingDirectory),
            'Environment=ORBIT_HOME='.$this->escapeDirectivePath($orbitHome),
            'ExecStart='.implode(' ', array_map($this->quoteArgument(...), [
                $phpBinary,
                $artisan,
                'orbit:private-dns-serve',
                '--listen='.$listenAddress,
                '--port='.(string) $port,
                '--catalog='.$catalogPath,
                '--upstream='.$upstream,
            ])),
            'Restart=on-failure',
            'RestartSec=2',
            '',
            '[Install]',
            'WantedBy=multi-user.target',
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
