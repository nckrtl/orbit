<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

final readonly class NodeAgentFootprint
{
    public const string Version = '0.1.0';

    public const string X8664Checksum = '5241c052051273eb77b0e6459ce638b2c208121b5257298ec9122ed4e79402f4';

    public const string Aarch64Checksum = '95ef4986c9901b1ecdb6a8e77c9b3523d5cdfd68cf1ad5416368fd22dddf8619';

    public const string BinaryPath = '/usr/local/bin/orbit-agent';

    public const string ConfigurationPath = '/etc/orbit/agent/config.toml';

    public const string CertificatePath = '/etc/orbit/agent/ca.pem';

    public const string UnitPath = '/etc/systemd/system/orbit-agent.service';

    public const string Service = 'orbit-agent';

    public const string Marker = '# Managed by Orbit: agent';

    public const string CandidateSuffix = '.orbit-candidate';

    public static function checksum(string $architecture): string
    {
        return match ($architecture) {
            'x86_64' => self::X8664Checksum,
            'aarch64' => self::Aarch64Checksum,
            default => throw new \InvalidArgumentException('Unsupported Node agent architecture.'),
        };
    }

    public static function downloadUrl(string $architecture): string
    {
        return 'https://github.com/nckrtl/orbit/releases/download/agent-v'.self::Version
            .'/orbit-agent-'.self::Version.'-linux-'.$architecture;
    }
}
