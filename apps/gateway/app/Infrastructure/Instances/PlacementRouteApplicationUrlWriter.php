<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Instances\RouteApplicationUrlWriter;
use App\Models\Instance;

/** Development writes into the checkout. Production writes the stable file that each release links to. */
final readonly class PlacementRouteApplicationUrlWriter implements RouteApplicationUrlWriter
{
    public function __construct(
        private RemoteDevelopmentInstanceConfigurator $development,
        private RemoteProductionRouteApplicationUrlWriter $production,
    ) {}

    public function configureDirectoryUrl(Instance $instance, string $relativeDirectory, string $url): void
    {
        if ($instance->placedOnAppProd()) {
            $this->production->configureDirectoryUrl($instance, $relativeDirectory, $url);

            return;
        }

        $this->development->configureDirectoryUrl($instance, $relativeDirectory, $url);
    }
}
