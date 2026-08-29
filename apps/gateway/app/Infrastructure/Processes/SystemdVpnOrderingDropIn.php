<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

/**
 * Renders the idempotent systemd drop-in that orders an Orbit-managed service
 * after the managed WireGuard interface. Services that bind a WireGuard
 * address fail on a cold boot when systemd starts them before
 * `wg-quick@orbit.service` brings that address up.
 */
final readonly class SystemdVpnOrderingDropIn
{
    public const string FILE_NAME = 'orbit-vpn.conf';

    public function __construct(
        private string $unitDirectory = '/etc/systemd/system',
    ) {}

    public function contents(): string
    {
        return implode("\n", [
            '# Managed by Orbit.',
            '[Unit]',
            'After=wg-quick@orbit.service',
            'Wants=wg-quick@orbit.service',
            '',
        ]);
    }

    public function path(string $service): string
    {
        return "{$this->unitDirectory}/{$service}.service.d/".self::FILE_NAME;
    }

    /** @return non-empty-list<string> */
    public function arguments(string $service): array
    {
        return ['sudo', 'bash', '-seu', '--', $service, $this->unitDirectory];
    }

    public function script(): string
    {
        $encoded = base64_encode($this->contents());
        $fileName = self::FILE_NAME;

        return <<<BASH
            service=\$1
            unit_directory=\$2
            directory=\$unit_directory/\$service.service.d
            managed=\$directory/{$fileName}
            candidate=\$directory/.{$fileName}.\$\$.candidate
            staged=\$(mktemp)
            trap 'rm -f -- "\$staged" "\$candidate"' EXIT
            install -d -o root -g root -m 0755 -- "\$directory"
            printf '%s' '{$encoded}' | base64 --decode > "\$staged"
            if [ -f "\$managed" ] && cmp -s -- "\$staged" "\$managed"; then
                if systemctl is-active --quiet "\$service"; then
                    exit 0
                fi
                systemctl restart "\$service"
                exit 0
            fi
            install -o root -g root -m 0644 -- "\$staged" "\$candidate"
            mv -fT -- "\$candidate" "\$managed"
            systemctl daemon-reload
            systemctl restart "\$service"
            BASH;
    }

    public function invocation(string $service): ProcessInvocation
    {
        return new ProcessInvocation(
            arguments: $this->arguments($service),
            timeout: 60.0,
            input: $this->script(),
        );
    }
}
