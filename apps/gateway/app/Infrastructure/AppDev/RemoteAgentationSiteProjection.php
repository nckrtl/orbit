<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\AgentationSiteProjection;
use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\Instances\InstanceSandboxGuard;
use App\Models\Instance;

final readonly class RemoteAgentationSiteProjection implements AgentationSiteProjection
{
    public function __construct(
        private RemoteAppDevCaddyManager $caddy,
        private DevelopmentProjectionOperationLock $projection,
    ) {}

    public function project(Instance $instance): void
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        $this->projection->run(function () use ($instance): void {
            $instance->load(['routes', 'node']);

            if ($instance->routes->isNotEmpty()) {
                $this->caddy->build($instance->node);
            }
        });
    }
}
