<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

final readonly class NodeAgentFootprint
{
    public const string Version = '0.1.1';

    public const string X8664Checksum = 'a25ff7385eb63ca959100dbbc09696efaa2b6d959619cc8c68bd06614acb90be';

    public const string Aarch64Checksum = 'e6edca454ef531f98c40fd8647b2232aac8b101b852733c6cdd6b5c8947cc948';

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
