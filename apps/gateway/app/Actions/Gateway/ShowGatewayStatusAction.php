<?php

declare(strict_types=1);

namespace App\Actions\Gateway;

use App\Actions\GatewayReleases\ShowGatewayReleaseAutomationAction;
use App\Data\Gateway\GatewayAutoReleaseStatusData;
use App\Data\Gateway\GatewayStatusData;
use App\Domain\Fleet\DesiredFleetState;
use App\Domain\GatewayReleases\GatewayReleaseAutomation;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Gateway status: the version, the release the Gateway runs from, and, for an active WireGuard peer,
 * the automatic release state. An anonymous health probe reads no release state from the database.
 * Status stays available when the release state cannot be read; those fields are null then.
 */
final readonly class ShowGatewayStatusAction
{
    public function __construct(
        private DesiredFleetState $fleet,
        private ShowGatewayReleaseAutomationAction $releases,
        private GatewayReleaseAutomation $automation,
    ) {}

    /**
     * The desired fleet state is shown only to an active WireGuard peer, as the release endpoint does, and only
     * from the cache: a status request never waits for Git or GitHub, because release verification calls it.
     */
    public function handle(bool $authenticated): GatewayStatusData
    {
        [$release, $sha] = $this->releases->current();

        return new GatewayStatusData(
            name: 'orbit-gateway',
            status: 'ok',
            version: Config::string('app.version'),
            phpVersion: PHP_VERSION,
            laravelVersion: Application::VERSION,
            desiredFleetState: $authenticated ? $this->fleet->cached() : null,
            release: $release,
            releaseSha: $sha,
            autoRelease: $authenticated ? $this->autoRelease() : null,
        );
    }

    private function autoRelease(): ?GatewayAutoReleaseStatusData
    {
        try {
            $tick = $this->automation->lastTick();

            return new GatewayAutoReleaseStatusData(
                enabled: $this->automation->enabled(),
                paused: $this->automation->pause() !== null,
                lastCheckedAt: $tick['checked_at'] ?? null,
                lastResult: $tick['result'] ?? null,
            );
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
