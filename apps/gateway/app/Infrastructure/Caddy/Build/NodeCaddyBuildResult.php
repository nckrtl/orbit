<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

enum NodeCaddyBuildResult: string
{
    /** A new version is live and Caddy reloaded it. */
    case Published = 'published';

    /** The render matched the live version, so nothing was written or reloaded. */
    case Unchanged = 'unchanged';
}
