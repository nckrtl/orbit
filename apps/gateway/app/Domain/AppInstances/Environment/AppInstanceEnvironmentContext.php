<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Environment;

use App\Models\Node;

final readonly class AppInstanceEnvironmentContext
{
    public function __construct(
        public int $appInstanceId,
        public int $appId,
        public int $nodeId,
        public string $environment,
        public string $path,
        public string $executionUser,
        public bool $laravel,
        public int $routeId,
        public string $routeHostname,
        public string $nodeStatus,
        public Node $node,
    ) {}

    public function samePlacement(self $other): bool
    {
        return
            $this->appInstanceId === $other->appInstanceId
            && $this->appId === $other->appId
            && $this->nodeId === $other->nodeId
            && $this->environment === $other->environment
            && $this->path === $other->path
            && $this->executionUser === $other->executionUser
            && $this->laravel === $other->laravel
            && $this->routeId === $other->routeId
            && $this->routeHostname === $other->routeHostname
            && $this->nodeStatus === $other->nodeStatus;
    }
}
