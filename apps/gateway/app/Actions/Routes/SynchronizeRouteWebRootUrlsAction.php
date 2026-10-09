<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Domain\Instances\RouteApplicationUrlWriter;
use App\Domain\Routes\RouteWebRoot;
use App\Models\Instance;

/**
 * Points `APP_URL` of each application directory that a Route with a web root serves at the Route that
 * wins it: the Instance's own Route keeps its directory, and otherwise the oldest Route serving the
 * directory wins. A directory that no Route serves any more keeps its last `APP_URL`.
 */
final readonly class SynchronizeRouteWebRootUrlsAction
{
    public function __construct(
        private RouteApplicationUrlWriter $writer,
    ) {}

    public function execute(Instance $instance): void
    {
        $instance->refresh()->load(['project', 'node']);

        if ($instance->placedOnAppProd() || $instance->selected_php_version === null) {
            return;
        }

        $routes = RouteWebRoot::applicationUrlCandidates($instance);

        foreach (RouteWebRoot::applicationUrlRoutes($instance, $routes) as $directory => $route) {
            $this->writer->configureDirectoryUrl($instance, (string) $directory, "https://{$route->domain}");
        }
    }
}
