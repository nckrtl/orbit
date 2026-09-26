<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

final readonly class NodeAgentFootprint
{
    public const string Version = '0.2.0';

    public const string X8664Checksum = 'ed3cb9978ef9e16683342cb11d5a3b3a4f54f6fc47b6ee0ec695090cef989b57';

    public const string Aarch64Checksum = '34389c2cb4424468e94f42dff1ee05c3a6b490f4286e56fea53dede796c406e3';

    public const string BinaryPath = '/usr/local/bin/orbit-agent';

    public const string ConfigurationPath = '/etc/orbit/agent/config.toml';

    public const string CertificatePath = '/etc/orbit/agent/ca.pem';

    /** The agent's secret, `root:root` mode `0600`; the Gateway keeps only its SHA-256 hash (ADR 0155). */
    public const string SecretPath = '/etc/orbit/agent/secret';

    /** The first agent release that sends its secret on every Gateway request. */
    public const string SecretSince = '0.3.0';

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

    /** Whether the given agent release sends its secret, so the Gateway must give it one. */
    public static function sendsSecret(string $version = self::Version): bool
    {
        return version_compare($version, self::SecretSince, '>=');
    }

    public static function downloadUrl(string $architecture): string
    {
        return 'https://github.com/nckrtl/orbit/releases/download/agent-v'.self::Version
            .'/orbit-agent-'.self::Version.'-linux-'.$architecture;
    }
}
