<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Models\Node;

final readonly class InstanceEnvironmentContext
{
    public function __construct(
        public int $instanceId,
        public int $projectId,
        public int $nodeId,
        public string $environment,
        public string $path,
        public string $executionUser,
        public bool $laravel,
        public ?int $routeId,
        public ?string $routeDomain,
        public string $nodeStatus,
        public Node $node,
        public ?InstanceEnvironmentRouteDomain $routeDomainSource = null,
        public string $app = 'web',
    ) {}

    public function samePlacement(self $other): bool
    {
        return
            $this->app === $other->app
            && $this->instanceId === $other->instanceId
            && $this->projectId === $other->projectId
            && $this->nodeId === $other->nodeId
            && $this->environment === $other->environment
            && $this->path === $other->path
            && $this->executionUser === $other->executionUser
            && $this->laravel === $other->laravel
            && $this->routeId === $other->routeId
            && $this->routeDomain === $other->routeDomain
            && $this->nodeStatus === $other->nodeStatus
            && $this->routeDomainSource === $other->routeDomainSource;
    }
}
