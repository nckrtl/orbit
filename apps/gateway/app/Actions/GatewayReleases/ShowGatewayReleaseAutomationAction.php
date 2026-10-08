<?php

declare(strict_types=1);

namespace App\Actions\GatewayReleases;

use App\Data\GatewayReleases\GatewayReleaseAutomationData;
use App\Data\GatewayReleases\GatewayReleasePauseData;
use App\Data\GatewayReleases\GatewayReleaseTickData;
use App\Domain\GatewayReleases\GatewayReleaseAutomation;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use Illuminate\Support\Facades\Config;

final readonly class ShowGatewayReleaseAutomationAction
{
    public function __construct(private GatewayReleaseAutomation $automation) {}

    public function execute(): GatewayReleaseAutomationData
    {
        [$release, $sha] = $this->current();
        $pause = $this->automation->pause();
        $tick = $this->automation->lastTick();
        $stall = $this->automation->stall();
        $head = $this->automation->branchHead();

        return new GatewayReleaseAutomationData(
            enabled: $this->automation->enabled(),
            paused: $pause !== null,
            pause: $pause === null ? null : GatewayReleasePauseData::fromPause($pause),
            currentRelease: $release,
            currentSha: $sha,
            lastTick: $tick === null ? null : GatewayReleaseTickData::fromTick($tick),
            stalledSince: $stall['since'] ?? null,
            branchHead: $head['head'] ?? null,
            behindSince: $head['behind_since'] ?? null,
            branch: Config::string('orbit.gateway_releases.branch'),
            check: Config::string('orbit.gateway_releases.check'),
        );
    }

    /**
     * The release the stable path links to. A Gateway that is not in the release layout, or whose
     * checkout path is not a release layout path, has none.
     *
     * @return array{string|null, string|null}
     */
    public function current(): array
    {
        try {
            $layout = GatewayReleaseLayout::fromConfig();
        } catch (GatewayReleaseException) {
            return [null, null];
        }

        $id = $layout->currentReleaseId();

        return [$id, $id === null ? null : $layout->preparedCommit($id)];
    }
}
