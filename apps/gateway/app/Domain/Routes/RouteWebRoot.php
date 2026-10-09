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

    /**
     * A Route with a web root takes a production Instance only when it is created for it. Moving it to
     * a production Instance later is not supported.
     */
    public static function assertRetargetable(Instance $instance): void
    {
        if ($instance->placedOnAppProd()) {
            throw new ResourceOperationException(
                errorCode: 'route.web_root_unsupported',
                message: 'A Route with a web root cannot move to a production Instance. Create the Route for that Instance instead.',
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
     * The web roots that the Instance's active Routes with a web root serve outside its default
     * application directory, ordered by web root. Each names its relative application directory and the
     * pool suffix of that directory. Production renders one PHP-FPM pool and one stable `.env` for each
     * distinct directory, and grants Caddy access to each web root.
     *
     * @return list<array{web_root: string, directory: string, suffix: string}>
     */
    public static function servedApplications(Instance $instance): array
    {
        $instance->loadMissing('project');
        $default = self::relativeDirectory($instance->root ?? $instance->project->root);
        $webRoots = Route::query()
            ->whereNotNull('web_root')
            ->whereIn('status', [RouteStatus::Active->value, RouteStatus::Activating->value])
            ->whereHas('targets', static fn ($query) => $query->where('instance_id', $instance->id))
            ->pluck('web_root')
            ->filter(static fn (mixed $webRoot): bool => is_string($webRoot))
            ->unique()
            ->sort()
            ->values();
        $served = [];

        foreach ($webRoots as $webRoot) {
            $directory = self::relativeDirectory($webRoot);

            if ($directory !== $default) {
                $served[] = ['web_root' => $webRoot, 'directory' => $directory, 'suffix' => substr(hash('sha256', $directory), 0, 8)];
            }
        }

        return $served;
    }

    /**
     * The Routes that decide `APP_URL`: the active and activating Routes that target the Instance.
     *
     * @return Collection<int, Route>
     */
    public static function applicationUrlCandidates(Instance $instance): Collection
    {
        return Route::query()
            ->whereIn('status', [RouteStatus::Active->value, RouteStatus::Activating->value])
            ->whereHas('targets', static fn ($query) => $query->where('instance_id', $instance->id))
            ->get();
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
