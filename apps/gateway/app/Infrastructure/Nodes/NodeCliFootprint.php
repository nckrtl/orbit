<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

/**
 * Where the Orbit CLI lives on a managed Linux Node (ADR 0202), and the Gateway connection it uses as the
 * Node itself. The layout matches `orbit self-update`: each release is its own file, `orbit-<version>`, and
 * `/usr/local/bin/orbit` is a link that one rename switches.
 *
 * `sudo orbit self-update` reads root's profile, `/root/.orbit/config.json`. The profile holds no secret: the
 * Gateway identifies the Node by its WireGuard address. It names the Gateway by its WireGuard address, which
 * the Gateway certificate covers, and pins the Orbit root certificate that the agent converge writes.
 */
final readonly class NodeCliFootprint
{
    public const string Directory = '/usr/local/bin';

    public const string LinkPath = '/usr/local/bin/orbit';

    public const string ConfigurationDirectory = '/root/.orbit';

    public const string ConfigurationPath = '/root/.orbit/config.json';

    public const string Profile = 'gateway';

    public const string CandidateSuffix = '.orbit-candidate';

    public static function binaryPath(string $version): string
    {
        return self::Directory.'/orbit-'.$version;
    }

    /** The CLI release platform of a Linux Node architecture, such as `linux-x86_64`. */
    public static function platform(string $architecture): string
    {
        return 'linux-'.$architecture;
    }

    /** Root's CLI profile on the Node, in the format the CLI reads from `config.json`. */
    public static function configuration(string $gatewayAddress): string
    {
        return json_encode([
            'active_gateway' => self::Profile,
            'gateways' => [
                self::Profile => [
                    'url' => 'https://'.$gatewayAddress,
                    'ca_path' => NodeAgentFootprint::CertificatePath,
                ],
            ],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    /**
     * `orbit self-update --json` as root, over SSH from the Gateway. After a Gateway rollback, the rollout
     * allows a downgrade to exactly the desired CLI version, and to no other.
     *
     * @return non-empty-list<string>
     */
    public static function selfUpdateCommand(?string $downgradeTo = null): array
    {
        return ['sudo', self::LinkPath, 'self-update', '--json', ...($downgradeTo === null ? [] : ['--allow-downgrade-to='.$downgradeTo])];
    }
}
