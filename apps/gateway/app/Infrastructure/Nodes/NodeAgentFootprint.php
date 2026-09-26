<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

final readonly class NodeAgentFootprint
{
    public const string Version = '0.2.0';

    /**
     * SHA-256 checksums from each release's `SHA256SUMS` asset. `Version` selects the pin, so a
     * change of that constant keeps the checksums and download URL on the same release.
     *
     * @var array<string, array{x86_64: string, aarch64: string}>
     */
    private const array ReleaseManifests = [
        '0.2.0' => [
            'x86_64' => 'ed3cb9978ef9e16683342cb11d5a3b3a4f54f6fc47b6ee0ec695090cef989b57',
            'aarch64' => '34389c2cb4424468e94f42dff1ee05c3a6b490f4286e56fea53dede796c406e3',
        ],
        '0.3.0' => [
            'x86_64' => 'f5125b2ab36abd79882b3b11eb5d40f5e457fbf23cc8bf3ff4c096e2cab4618a',
            'aarch64' => '84306df202904277c6f6cd78d4050fae97e54c3e515a2ad09585dd5a5e568811',
        ],
    ];

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
        $manifest = self::ReleaseManifests[self::Version];

        return match ($architecture) {
            'x86_64', 'aarch64' => $manifest[$architecture],
            default => throw new \InvalidArgumentException('Unsupported Node agent architecture.'),
        };
    }

    /** Whether the given agent release sends its secret, so the Gateway must give it one. */
    public static function sendsSecret(string $version): bool
    {
        return version_compare($version, self::SecretSince, '>=');
    }

    public static function downloadUrl(string $architecture): string
    {
        return 'https://github.com/nckrtl/orbit/releases/download/agent-v'.self::Version
            .'/orbit-agent-'.self::Version.'-linux-'.$architecture;
    }
}
