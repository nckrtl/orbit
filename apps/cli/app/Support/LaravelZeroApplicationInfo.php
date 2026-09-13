<?php

declare(strict_types=1);

namespace App\Support;

use Laravel\Boost\Mcp\Tools\ApplicationInfo;
use Laravel\Boost\Support\PackageRegistry;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Roster\Package;

#[IsReadOnly]
final class LaravelZeroApplicationInfo extends ApplicationInfo
{
    #[\Override]
    protected string $name = 'application-info';

    #[\Override]
    protected string $title = 'Application Info';

    public function handle(Request $request): Response
    {
        return Response::json([
            'php_version' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
            'laravel_version' => app()->version(),
            'database_engine' => null,
            'packages' => $this->project->php()->packages()
                ->concat($this->project->js()->packages())
                ->map(fn (Package $package): array => [
                    'roster_name' => PackageRegistry::rosterName($package->name()),
                    'version' => $package->version(),
                    'package_name' => $package->name(),
                ]),
        ]);
    }
}
