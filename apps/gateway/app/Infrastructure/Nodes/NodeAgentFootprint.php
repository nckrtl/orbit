<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

final readonly class NodeAgentFootprint
{
    public const string Version = '0.3.0';

    /** @var list<string> */
    public const array Architectures = ['x86_64', 'aarch64'];

    /**
     * SHA-256 checksums from the pinned release's `SHA256SUMS` asset.
     *
     * @var array<string, array{x86_64: string, aarch64: string}>
     */
    private const array ReleaseManifests = [
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

    public const string UnitPath = '/etc/systemd/system/orbit-agent.service';

    public const string Service = 'orbit-agent';

    public const string Marker = '# Managed by Orbit: agent';

    public const string CandidateSuffix = '.orbit-candidate';

    /**
     * The Node-local lock that `orbit self-update` holds while it replaces and restarts the agent. The converge
     * moves its binary into place under the same lock, so the two never swap the agent at the same time.
     */
    public const string UpdateLockPath = '/run/lock/orbit-self-update.lock';

    public static function checksum(string $architecture): string
    {
        $manifest = self::ReleaseManifests[self::Version];

        return match ($architecture) {
            'x86_64', 'aarch64' => $manifest[$architecture],
            default => throw new \InvalidArgumentException('Unsupported Node agent architecture.'),
        };
    }

    public static function assetName(string $architecture): string
    {
        return 'orbit-agent-'.self::Version.'-linux-'.$architecture;
    }

    public static function downloadUrl(string $architecture): string
    {
        return 'https://github.com/nckrtl/orbit/releases/download/agent-v'.self::Version
            .'/'.self::assetName($architecture);
    }
}
