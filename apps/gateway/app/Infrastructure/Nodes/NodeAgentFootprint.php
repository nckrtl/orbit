<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

final readonly class NodeAgentFootprint
{
    public const string Version = '0.4.2';

    /** @var list<string> */
    public const array Architectures = ['x86_64', 'aarch64'];

    /**
     * SHA-256 checksums from the pinned release's `SHA256SUMS` asset.
     *
     * @var array<string, array{x86_64: string, aarch64: string}>
     */
    private const array ReleaseManifests = [
        '0.4.2' => [
            'x86_64' => '800c83121c6c3c1e1614d11326e1a2386f0651897a5dd7eb35155aac1867ccee',
            'aarch64' => 'edddd97040290d7a4cc8ba83c8cc68121c46c714bb8fbf05bd60f06f94bf2bb1',
        ],
        '0.4.1' => [
            'x86_64' => '3ee2488f9dc5eb132bcd63236613536e9cf06bbe6e07a421c3c7547fe222689b',
            'aarch64' => '1aed0809800b68d97a2f8dc373cb045f8ed918c006a6cdb9567e3105608b4b27',
        ],
        '0.4.0' => [
            'x86_64' => '4f41321187e538b88bc5432b319c6ea7a09767ff18be6dcf90a277af58fbac46',
            'aarch64' => '9b0bce29354091c7f1cffe9ddb5a92ba7b34d5d93f556d9112a4cb5ecd9e50de',
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
