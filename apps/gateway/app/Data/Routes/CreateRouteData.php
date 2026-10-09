<?php

declare(strict_types=1);

namespace App\Data\Routes;

use App\Domain\Routes\RoutePublication;

final readonly class CreateRouteData
{
    public function __construct(
        public string $domain,
        public RoutePublication $publication,
        public ?int $projectId = null,
        public ?int $instanceId = null,
        public ?int $nodeId = null,
        public ?int $clusterId = null,
        public ?string $upstream = null,
        public ?int $processId = null,
        public ?string $webRoot = null,
    ) {}

    public function isCustomProxy(): bool
    {
        return $this->upstream !== null || $this->processId !== null;
    }
}
