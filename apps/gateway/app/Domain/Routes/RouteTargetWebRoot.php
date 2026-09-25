<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\RelativeWebRoot;
use App\Models\AppInstance;

final readonly class RouteTargetWebRoot
{
    public static function assertSupported(AppInstance $instance): void
    {
        $root = $instance->root ?? $instance->app->root;

        if (! is_string($root) || ! RelativeWebRoot::isValid($root)) {
            throw new ResourceOperationException(
                errorCode: 'route.target_web_root_unsupported',
                message: 'A Route target requires a supported relative web root.',
                status: 409,
            );
        }
    }
}
