<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\ApplicationDirectory;
use App\Domain\SourceControl\RelativeWebRoot;
use App\Models\Instance;
use App\Models\Route;
use Illuminate\Support\Collection;

/**
 * A Route serves its own web root inside the Instance's checkout, or the Instance's effective root
 * when it has none. Each served application directory other than the Instance's default one gets its
 * own PHP-FPM pool and its own `APP_URL`.
 */
final class RouteWebRoot
{
    public static function normalize(?string $webRoot): ?string
    {
        if ($webRoot === null) {
            return null;
        }

        if (! RelativeWebRoot::isValid($webRoot)) {
            throw new ResourceOperationException(
                errorCode: 'route.web_root_invalid',
                message: 'A Route web root must be a normalized relative path inside the checkout, such as apps/docs/public.',
                status: 422,
            );
        }

        return $webRoot;
    }

    /** Production Instances serve only their effective root for now. */
    public static function assertSupportedTarget(Instance $instance): void
    {
        if ($instance->placedOnAppProd()) {
            throw new ResourceOperationException(
                errorCode: 'route.web_root_unsupported',
                message: 'A Route web root is supported only for development Instances.',
                status: 409,
            );
        }
    }

    /** The web root the Route serves for the Instance. */
    public static function served(Route $route, Instance $instance): ?string
    {
        return $route->web_root ?? $instance->root ?? $instance->project->root;
    }

    /** The served application directory relative to the checkout, or `''` for the checkout itself. */
    public static function relativeDirectory(?string $webRoot): string
    {
        return ltrim(ApplicationDirectory::resolve('', $webRoot), '/');
    }

    /**
     * Null for the Instance's default application directory, so its pool keeps today's name. Another
     * directory gets a stable suffix derived from its relative path.
     */
    public static function poolSuffix(Instance $instance, ?string $webRoot): ?string
    {
        $directory = self::relativeDirectory($webRoot);

        return $directory === self::relativeDirectory($instance->root ?? $instance->project->root)
            ? null
            : substr(hash('sha256', $directory), 0, 8);
    }

    /**
     * The Route whose domain each directory's `APP_URL` names, for every directory that a Route with a
     * web root serves and the Instance's own Route does not. The Instance's own Route keeps its
     * directory; otherwise the oldest Route serving a directory wins.
     *
     * @param  Collection<int, Route>  $routes  The Instance's authoritative Routes.
     * @return array<string, Route> Keyed by relative application directory.
     */
    public static function applicationUrlRoutes(Instance $instance, Collection $routes): array
    {
        $ordered = $routes->sortBy('id')->values();
        $own = $ordered->first(static fn (Route $route): bool => ! $route->hasWebRoot());
        $ownDirectory = $own instanceof Route ? self::relativeDirectory(self::served($own, $instance)) : null;
        $winners = [];

        foreach ($ordered as $route) {
            $directory = self::relativeDirectory(self::served($route, $instance));

            if ($directory !== $ownDirectory && ! array_key_exists($directory, $winners)) {
                $winners[$directory] = $route;
            }
        }

        return $winners;
    }
}
