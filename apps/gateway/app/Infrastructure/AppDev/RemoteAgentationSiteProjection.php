<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\AgentationSiteProjection;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Models\AppInstance;

final readonly class RemoteAgentationSiteProjection implements AgentationSiteProjection
{
    public function __construct(
        private RemoteAppDevCaddyManager $caddy,
        private DevelopmentProjectionOperationLock $projection,
    ) {}

    public function project(AppInstance $instance): void
    {
        $this->projection->run(function () use ($instance): void {
            $instance->loadMissing(['routes', 'node']);

            if ($instance->routes->isNotEmpty()) {
                $this->caddy->build($instance->node);
            }
        });
    }
}
