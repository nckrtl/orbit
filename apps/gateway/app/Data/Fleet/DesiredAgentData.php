<?php

declare(strict_types=1);

namespace App\Data\Fleet;

use App\Infrastructure\Nodes\NodeAgentFootprint;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** The `orbit-agent` release the Gateway pins, with the SHA-256 of each Linux binary. */
#[MapOutputName(SnakeCaseMapper::class)]
final class DesiredAgentData extends Data
{
    /**
     * @param  list<FleetReleaseAssetData>  $assets
     */
    public function __construct(
        /** The pinned agent version, such as `0.3.0`. */
        public string $version,
        /** One binary per Linux architecture. */
        public array $assets,
    ) {}

    public static function fromFootprint(): self
    {
        $assets = [];

        foreach (NodeAgentFootprint::Architectures as $architecture) {
            $assets[] = new FleetReleaseAssetData(
                platform: 'linux-'.$architecture,
                name: NodeAgentFootprint::assetName($architecture),
                url: NodeAgentFootprint::downloadUrl($architecture),
                sha256: NodeAgentFootprint::checksum($architecture),
            );
        }

        return new self(NodeAgentFootprint::Version, $assets);
    }
}
