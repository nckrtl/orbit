<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\RelativeWebRoot;
use App\Models\Instance;

final readonly class RouteTargetWebRoot
{
    public static function assertSupported(Instance $instance, ?string $app = null): void
    {
        $root = $instance->relativeWebRoot($app);
        if ($root === '.') {
            return;
        }
        self::assertSupportedRoot($root);
    }

    public static function assertSupportedRoot(?string $root, ?string $message = null): void
    {
        if (! is_string($root) || ! RelativeWebRoot::isValid($root)) {
            throw new ResourceOperationException(
                errorCode: 'route.target_web_root_unsupported',
                message: $message ?? 'A Route target requires a supported relative web root.',
                status: 409,
            );
        }
    }
}
