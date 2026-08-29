<?php

declare(strict_types=1);

namespace App\Actions\Gateway;

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\UbuntuRelease;

final readonly class GatewayOperatingSystemGuard
{
    public function __construct(
        private string $osReleasePath = '/etc/os-release',
    ) {}

    public function assertSupported(): void
    {
        if (! is_file($this->osReleasePath) || ! is_readable($this->osReleasePath)) {
            throw $this->unsupported();
        }

        $contents = file_get_contents($this->osReleasePath);

        if (! is_string($contents)) {
            throw $this->unsupported();
        }

        $values = [];
        $seen = [];
        $invalid = false;
        $lines = preg_split('/\R/', $contents);

        if (! is_array($lines)) {
            throw $this->unsupported();
        }

        foreach ($lines as $line) {
            $key = [];

            if (! preg_match('/^(ID|VERSION_CODENAME)=/', $line, $key)) {
                continue;
            }

            $name = $key[1];

            if (array_key_exists($name, $seen)) {
                $invalid = true;
                continue;
            }

            $seen[$name] = true;
            $matches = [];

            if (! preg_match(
                '/^(ID|VERSION_CODENAME)=(?:(\"|\')([A-Za-z0-9._-]+)\\2|([A-Za-z0-9._-]+))$/',
                $line,
                $matches,
            )) {
                $invalid = true;
                continue;
            }

            $values[$name] = $matches[3] !== '' ? $matches[3] : $matches[4];
        }

        $codename = $values['VERSION_CODENAME'] ?? null;
        $supported = array_map(
            static fn (UbuntuRelease $release): string => $release->value,
            UbuntuRelease::forRole(RoleName::Gateway),
        );

        if (
            $invalid
            || ($values['ID'] ?? null) !== 'ubuntu'
            || ! is_string($codename)
            || ! in_array($codename, $supported, strict: true)
        ) {
            throw $this->unsupported();
        }
    }

    private function unsupported(): NodeProvisioningException
    {
        return new NodeProvisioningException(
            'operating-system',
            'gateway.operating_system_unsupported',
            'Gateway bootstrap requires Ubuntu 26.04 Resolute.',
        );
    }
}
